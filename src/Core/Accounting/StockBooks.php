<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Accounting\Host\HostAccountingGuard;
use Whity\Core\Db\DbBool;
use Whity\Core\Inventory\InventoryCosts;
use Whity\Sdk\Contribute\WorkspaceAccountingExport;
use Whity\Sdk\Inventory\StockPosting;
use Whity\Sdk\Inventory\StockPostingMove;

/**
 * Stock in the books (WHIT-1318 A4a, design section 32 §6, register AC.18,
 * Q3): core accounting's {@see WorkspaceAccountingExport}, perpetual, valued
 * at core's moving average cost (IV.18).
 *
 * Each stock batch posts one entry in the stock journal (STK):
 *
 *     receipt (supplier → place)     Dr Stock / Cr Goods received, not yet billed
 *     opening stock (opening → place) Dr Stock / Cr Opening balances
 *     found (found → place)          Dr Stock / Cr Stock found
 *     waste, loss (place → loss)     Dr Stock lost and wasted / Cr Stock
 *     sold (place → customer)        Dr Cost of goods sold / Cr Stock
 *     used (place → consumed)        Dr Cost of goods sold / Cr Stock
 *     a customer's return            the mirror of sold
 *     transfers (place → place)      nothing
 *
 * A reversal is the mirror of what it undoes. Moves whose cost is unknown post
 * nothing and wait for a person ({@see self::waiting()}). It runs inside the
 * stock transaction, so a closed month refuses the stock move too: one lock
 * date for stock and books.
 *
 * ON BY DEFAULT, with a switch (`acc_books.stock_in_books`, Q3). The first
 * batch after it is on (or `whity-cli accounting:stock-opening`) values all
 * stock at its current cost and posts what the Stock account lacks against
 * Opening balances, once. Switching it off and on again posts only the
 * difference, so Stock always matches the stock value.
 */
final class StockBooks implements WorkspaceAccountingExport
{
    /** What the other side of a move into or out of stock posts to, by the virtual place's role. */
    private const COUNTER = [
        'supplier' => 'goods_received_not_billed',
        'opening' => 'opening_balance',
        'found' => 'stock_found',
        'loss' => 'stock_loss',
        'customer' => 'cost_of_sales',
        'consumed' => 'cost_of_sales',
        'production' => 'stock_loss',
    ];

    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
        private readonly Ledger $ledger,
        private readonly InventoryCosts $costs,
    ) {
    }

    public function record(StockPosting $posting): ?string
    {
        // The books' refusals reach inventory as the SDK's AccountingRejected.
        return HostAccountingGuard::run(fn (): ?string => $this->post($posting));
    }

    private function post(StockPosting $posting): ?string
    {
        $tenantId = $posting->workspaceId;
        if (!$this->isOn($tenantId)) {
            return null;
        }
        if ($this->cutover($tenantId) === null) {
            $this->catchUp($tenantId, $posting->occurredOn, $posting->actingProfileId, $posting);
        }
        $lines = $this->lines($tenantId, $posting->moves);
        if ($lines === []) {
            return null;
        }
        $posted = $this->ledger->post($tenantId, $posting->actingProfileId, [
            'journal' => 'STK',
            'date' => $posting->occurredOn,
            'memo' => 'Stock: ' . $posting->sourceType . ($posting->sourceId !== null ? ' ' . $posting->sourceId : ''),
            'source_type' => 'stock',
            'source_id' => $posting->sourceId,
            'idempotency_key' => 'stock:' . $posting->batchKey,
            'lines' => $lines,
        ]);

        return (string) $posted['entry']['number'];
    }

    /**
     * Values the stock now and posts what the Stock account lacks, once per
     * switch-on. Answers what it posted.
     *
     * @return array{posted: bool, entry_id: ?int, amount_minor: int, unknown: list<int>}
     */
    public function openNow(int $tenantId, ?int $actor, ?string $date = null): array
    {
        if (!$this->isOn($tenantId)) {
            throw new AccountingRejectedException('Stock is not kept in the books here; turn it on first', 409);
        }
        if ($this->cutover($tenantId) !== null) {
            throw new AccountingRejectedException('Stock is already in the books', 409, ['code' => 'already']);
        }

        return $this->db->transaction(fn (): array => $this->catchUp($tenantId, $date ?? gmdate('Y-m-d'), $actor, null));
    }

    /**
     * Turns stock in the books on or off. Off stops posting; on again values
     * the stock with the next batch (or now, with `openNow`).
     *
     * @return array<string, mixed>
     */
    public function setOn(int $tenantId, bool $on): array
    {
        $this->workspace->ensure($tenantId);
        $this->db->run(
            'UPDATE acc_books SET stock_in_books = :on, stock_cutover_at = CASE WHEN :on2 THEN stock_cutover_at ELSE NULL END,
                    updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id',
            [':on' => $on, ':on2' => $on, ':tenant_id' => $tenantId]
        );

        return $this->status($tenantId);
    }

    /**
     * Whether stock is in the books, since when, what the stock is worth and
     * what the Stock account says.
     *
     * @return array<string, mixed>
     */
    public function status(int $tenantId): array
    {
        $this->workspace->ensure($tenantId);
        $value = $this->costs->stockValue($tenantId);
        $cutover = $this->cutover($tenantId);

        return [
            'stock_in_books' => $this->isOn($tenantId),
            'since' => $cutover,
            'currency' => $value['currency'],
            'stock_value_minor' => $value['value_minor'],
            'stock_account_minor' => $this->stockBalance($tenantId),
            'items_without_cost' => count($value['unknown']),
            'waiting' => $cutover === null ? 0 : count($this->waiting($tenantId, 1000)),
        ];
    }

    /**
     * Stock moves since stock went into the books whose cost is unknown, so
     * they posted nothing: "Waiting for you".
     *
     * @return list<array<string, mixed>>
     */
    public function waiting(int $tenantId, int $limit = 100): array
    {
        $cutover = $this->cutover($tenantId);
        if ($cutover === null) {
            return [];
        }
        return array_map(static fn (array $r): array => [
            'move_id' => (int) $r['id'],
            'variant_id' => (int) $r['variant_id'],
            'item_name' => (string) $r['item_name'],
            'quantity' => (int) $r['quantity'],
            'source_type' => (string) $r['source_type'],
            'source_id' => AccountingDb::int($r['source_id']),
            'occurred_at' => (string) $r['occurred_at'],
        ], $this->db->all(
            "SELECT m.id, m.variant_id, i.name AS item_name, m.quantity, m.source_type, m.source_id, m.occurred_at
               FROM inv_moves m
               JOIN inv_variants v ON v.id = m.variant_id AND v.tenant_id = m.tenant_id
               JOIN inv_items i ON i.id = v.item_id AND i.tenant_id = v.tenant_id
               JOIN inv_locations f ON f.id = m.from_location_id AND f.tenant_id = m.tenant_id
               JOIN inv_locations t ON t.id = m.to_location_id AND t.tenant_id = m.tenant_id
              WHERE m.tenant_id = :tenant_id AND m.value_minor IS NULL AND m.posted_at >= :since
                AND (f.kind = 'virtual') <> (t.kind = 'virtual')
                AND COALESCE(f.virtual_role, '') <> 'transit' AND COALESCE(t.virtual_role, '') <> 'transit'
              ORDER BY m.id DESC LIMIT " . max(1, min(1000, $limit)),
            [':tenant_id' => $tenantId, ':since' => $cutover]
        ));
    }

    /**
     * Brings the Stock account to the stock's value before this batch, against
     * Opening balances, and marks stock as in the books.
     *
     * @return array{posted: bool, entry_id: ?int, amount_minor: int, unknown: list<int>}
     */
    private function catchUp(int $tenantId, string $date, ?int $actor, ?StockPosting $batch): array
    {
        $claimed = $this->db->run(
            'UPDATE acc_books SET stock_cutover_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND stock_cutover_at IS NULL',
            [':tenant_id' => $tenantId]
        )->rowCount();
        if ($claimed !== 1) {
            return ['posted' => false, 'entry_id' => null, 'amount_minor' => 0, 'unknown' => []];
        }
        $value = $this->costs->stockValue($tenantId);
        $before = $value['value_minor'];
        foreach ($batch === null ? [] : $batch->moves as $move) {
            if ($move->valueMinor === null) {
                continue;
            }
            $before -= $move->isIn() ? $move->valueMinor : ($move->isOut() ? -$move->valueMinor : 0);
        }
        $difference = $before - $this->stockBalance($tenantId);
        if ($difference === 0) {
            return ['posted' => false, 'entry_id' => null, 'amount_minor' => 0, 'unknown' => $value['unknown']];
        }
        $stock = $this->workspace->roleAccountId($tenantId, 'inventory');
        $opening = $this->workspace->roleAccountId($tenantId, 'opening_balance');
        $amount = abs($difference);
        $posted = $this->ledger->post($tenantId, $actor, [
            'journal' => 'OPN',
            'date' => $date,
            'memo' => 'Stock brought into the books at its current cost',
            'source_type' => 'stock',
            'idempotency_key' => 'stock_opening:' . bin2hex(random_bytes(8)),
            'lines' => $difference > 0
                ? [['account_id' => $stock, 'debit_minor' => $amount], ['account_id' => $opening, 'credit_minor' => $amount]]
                : [['account_id' => $opening, 'debit_minor' => $amount], ['account_id' => $stock, 'credit_minor' => $amount]],
        ]);

        return ['posted' => true, 'entry_id' => (int) $posted['entry']['id'], 'amount_minor' => $difference, 'unknown' => $value['unknown']];
    }

    /**
     * @param list<StockPostingMove> $moves
     * @return list<array<string, mixed>>
     */
    private function lines(int $tenantId, array $moves): array
    {
        $stock = $this->workspace->roleAccountId($tenantId, 'inventory');
        $sums = [];
        foreach ($moves as $move) {
            if ($move->valueMinor === null || $move->valueMinor <= 0 || !($move->isIn() || $move->isOut())) {
                continue;
            }
            $role = $move->isIn() ? (string) $move->fromRole : (string) $move->toRole;
            $counterRole = self::COUNTER[$role] ?? null;
            if ($counterRole === null) {
                continue;
            }
            $counter = $this->workspace->roleAccountId($tenantId, $counterRole);
            $ou = $move->isIn() ? $move->toOuId : $move->fromOuId;
            // Into stock: Dr Stock, Cr the other side; out of stock: the mirror.
            foreach ([[$stock, $ou, $move->isIn()], [$counter, null, !$move->isIn()]] as [$account, $branch, $debit]) {
                $k = $account . ':' . ($branch ?? 0);
                $sums[$k] ??= ['account_id' => $account, 'ou_id' => $branch, 'net' => 0];
                $sums[$k]['net'] += $debit ? $move->valueMinor : -$move->valueMinor;
            }
        }
        $lines = [];
        foreach ($sums as $sum) {
            if ($sum['net'] === 0) {
                continue;
            }
            $lines[] = ['account_id' => $sum['account_id'], 'ou_id' => $sum['ou_id']]
                + ($sum['net'] > 0 ? ['debit_minor' => $sum['net']] : ['credit_minor' => -$sum['net']]);
        }

        return count($lines) >= 2 ? $lines : [];
    }

    private function isOn(int $tenantId): bool
    {
        $this->workspace->ensure($tenantId);

        return DbBool::of($this->db->one('SELECT stock_in_books FROM acc_books WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId])['stock_in_books'] ?? true);
    }

    private function cutover(int $tenantId): ?string
    {
        $at = $this->db->one('SELECT stock_cutover_at FROM acc_books WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId])['stock_cutover_at'] ?? null;

        return is_string($at) && $at !== '' ? $at : null;
    }

    private function stockBalance(int $tenantId): int
    {
        return (int) ($this->db->one(
            'SELECT COALESCE(SUM(debit_minor - credit_minor), 0) AS bal FROM acc_lines WHERE tenant_id = :tenant_id AND account_id = :account_id',
            [':tenant_id' => $tenantId, ':account_id' => $this->workspace->roleAccountId($tenantId, 'inventory')]
        )['bal'] ?? 0);
    }
}
