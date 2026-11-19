<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Money\Currency;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Inventory\Quantity;

/**
 * Adjustments, counts and transfers as records (WHIT-1233, design 31 §3.9,
 * register IV.14, IV.15, IV.16).
 *
 * An operation has a number (`ADJ-0001`), lines, and a state:
 * - `draft`: being written (a count is being counted).
 * - `waiting_approval`: worth more than the workspace's value, so it travels
 *   its route first (IV.14, IV.15). Approved, it posts; turned down, it goes
 *   back to draft and can be sent again or cancelled.
 * - `in_transit`: a transfer sent and not yet received; its stock is in the
 *   virtual "On the way" place (IV.16).
 * - `done`: its moves are on the ledger, as one batch (`batch_key`), which
 *   the ledger's undo reverses.
 * - `cancelled`.
 *
 * VALUE. A line is worth its quantity at the item's buying price (the
 * variant's, else the item's), in the item's currency. With no buying price
 * a line has no value and never needs approval. Moving average cost (D1)
 * replaces the buying price when it arrives in P7.
 *
 * COUNTS take "expected" when each line is counted, not when the count
 * starts, so sales during a long count do not show as differences (IV.15).
 * A blind count hides expected from the people counting.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class StockOperations
{
    public const KINDS = ['adjustment', 'count', 'transfer', 'receipt'];
    public const STATUSES = ['draft', 'waiting_approval', 'in_transit', 'done', 'cancelled'];

    private const PREFIX = ['adjustment' => 'ADJ', 'count' => 'CNT', 'transfer' => 'TRF', 'receipt' => 'GRN'];

    /** Lines one operation may hold. A count of a whole shop can be long. */
    public const MAX_LINES = 2000;

    private const COLUMNS = 'id, kind, number, status, order_id, from_location_id, to_location_id, category_id, reason_code, note, is_blind,
        value_minor, currency, route_id, batch_key, sent_batch_key, idempotency_key, source_plugin, created_by_profile_id, agent_id,
        on_behalf_of_profile_id, done_by_profile_id, occurred_at, submitted_at, sent_at, done_at, cancelled_at, created_at, updated_at';

    /**
     * @param \Closure(int): array<string, mixed> $settings the workspace's effective settings
     */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
        private readonly ItemService $items,
        private readonly UnitCatalog $units,
        private readonly StockLedger $ledger,
        private readonly ReasonCatalog $reasons,
        private readonly \Closure $settings,
        private readonly ?HookManager $hooks = null,
        private ?OperationApprovals $approvals = null,
    ) {
    }

    /** Wired after construction: the router that issues routes needs the subject that needs this service. */
    public function useApprovals(OperationApprovals $approvals): void
    {
        $this->approvals = $approvals;
    }

    // ── adjustments ──────────────────────────────────────────────────────

    /**
     * An adjustment: signed changes at one place, with a reason. Posts at once
     * unless a line is worth more than the workspace's value, then waits for
     * approval.
     *
     * @param array<string, mixed> $data `location_id`, `reason_code`, `note`, `occurred_at`, `lines` (each `variant_id`, `change` signed decimal, `unit`, `note`, optional `reason_code`)
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, idempotency_key?: ?string, source_plugin?: ?string} $meta
     * @return array{operation: array<string, mixed>, repeated: bool}
     */
    public function adjust(int $tenantId, ?int $actor, array $data, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        if (($done = $this->byIdempotencyKey($tenantId, $meta['idempotency_key'] ?? null)) !== null) {
            return ['operation' => $done, 'repeated' => true];
        }
        $place = $this->realPlace($tenantId, $data['location_id'] ?? null, 'location_id');
        $reason = is_string($data['reason_code'] ?? null) ? $data['reason_code'] : '';
        $raw = $this->rawLines($data['lines'] ?? null, 'lines is a list of {variant_id, change}');

        $lines = [];
        foreach ($raw as $i => $line) {
            $field = 'lines[' . $i . ']';
            $change = is_string($line['change'] ?? null) || is_int($line['change'] ?? null) ? trim((string) $line['change']) : '';
            $negative = str_starts_with($change, '-');
            $own = is_string($line['reason_code'] ?? null) && $line['reason_code'] !== '';
            $lineReason = $own ? $line['reason_code'] : $reason;
            $this->reasons->usable($tenantId, $lineReason, $negative ? 'out' : 'in', $own ? $field . '.reason_code' : 'reason_code');
            $other = $this->workspace->virtualId($tenantId, $negative ? 'loss' : 'found');
            $prepared = $this->ledger->prepareLine($tenantId, [
                'variant_id' => $line['variant_id'] ?? null,
                'quantity' => ltrim($change, '-+'),
                'unit' => $line['unit'] ?? null,
                'from_location_id' => $negative ? $place : $other,
                'to_location_id' => $negative ? $other : $place,
            ], $field);
            $lines[] = [
                'variant_id' => $prepared['variant_id'],
                'quantity' => $negative ? -$prepared['steps'] : $prepared['steps'],
                'entered_unit_id' => $prepared['entered_unit_id'],
                'entered_quantity' => $prepared['entered_quantity'],
                'reason_code' => $lineReason,
                'note' => is_string($line['note'] ?? null) ? mb_substr(trim($line['note']), 0, 500) : '',
            ];
        }

        $id = $this->insert($tenantId, $actor, 'adjustment', [
            'from_location_id' => $place, 'reason_code' => $reason, 'note' => $data['note'] ?? '',
            'occurred_at' => $this->occurredAt($data['occurred_at'] ?? null),
        ], $lines, $meta);

        return ['operation' => $this->submit($tenantId, $id, $actor), 'repeated' => false];
    }

    /**
     * Sends a draft adjustment, or a count's differences, on: posted at once
     * when no line is worth more than the workspace's value, otherwise to
     * approval. Also how a draft turned down is sent again.
     *
     * @return array<string, mixed>
     */
    public function submit(int $tenantId, int $id, ?int $actor): array
    {
        $op = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Stock operation');
        if (!in_array($op['kind'], ['adjustment', 'count'], true) || ($op['status'] !== 'draft' && !self::nobodyToApprove($op))) {
            throw new InventoryRejectedException('Only a draft adjustment or count can be sent on', 409);
        }
        if (self::nobodyToApprove($op)) {
            // Waiting with nobody to approve it: try the route again, now
            // someone may hold the permission or a route is chosen (WHIT-1301).
            return $this->sendForApproval($tenantId, $id, $actor);
        }
        if ($op['kind'] === 'count') {
            return $this->finishCount($tenantId, $id, $actor);
        }
        $this->priceLines($tenantId, $id, false);
        if ($this->needsApproval($tenantId, $id, SettingsRegistry::INVENTORY_APPROVE_ADJUSTMENTS_ABOVE)) {
            return $this->sendForApproval($tenantId, $id, $actor);
        }

        return $this->post($tenantId, $id, $actor);
    }

    // ── counts ───────────────────────────────────────────────────────────

    /**
     * Starts a count at one place: lines for what is there now (or for a
     * category, or for chosen variants), nothing counted yet.
     *
     * @param array<string, mixed> $data `location_id`, optional `category_id`, `variant_ids`, `is_blind`, `note`
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, idempotency_key?: ?string, source_plugin?: ?string} $meta
     * @return array{operation: array<string, mixed>, repeated: bool}
     */
    public function startCount(int $tenantId, ?int $actor, array $data, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        if (($done = $this->byIdempotencyKey($tenantId, $meta['idempotency_key'] ?? null)) !== null) {
            return ['operation' => $done, 'repeated' => true];
        }
        $place = $this->realPlace($tenantId, $data['location_id'] ?? null, 'location_id');
        $category = $data['category_id'] ?? null;
        if ($category !== null && (!is_int($category) || $this->db->one(
            'SELECT id FROM inv_categories WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $category]
        ) === null)) {
            throw InventoryRejectedException::field('category_id', 'category_id is not a category here');
        }
        $chosen = $data['variant_ids'] ?? null;
        if ($chosen !== null && (!is_array($chosen) || !array_is_list($chosen) || array_filter($chosen, static fn ($v): bool => !is_int($v)) !== [])) {
            throw InventoryRejectedException::field('variant_ids', 'variant_ids is a list of variant ids');
        }
        $blind = $data['is_blind'] ?? false;
        if (!is_bool($blind)) {
            throw InventoryRejectedException::field('is_blind', 'is_blind is true or false');
        }

        $variantIds = $this->countScope($tenantId, $place, is_int($category) ? $category : null, $chosen);
        if (count($variantIds) > self::MAX_LINES) {
            throw InventoryRejectedException::field('location_id', 'That is more than ' . self::MAX_LINES . ' items; count a category at a time');
        }
        $lines = array_map(static fn (int $v): array => ['variant_id' => $v, 'quantity' => null], $variantIds);

        $id = $this->insert($tenantId, $actor, 'count', [
            'from_location_id' => $place, 'category_id' => is_int($category) ? $category : null, 'is_blind' => $blind,
            'note' => $data['note'] ?? '', 'reason_code' => 'count_correction',
        ], $lines, $meta);

        return ['operation' => $this->get($tenantId, $id), 'repeated' => false];
    }

    /**
     * Records what was counted. Each line's "expected" is taken now. A
     * variant not yet on the count is added.
     *
     * @param list<mixed> $rawLines each `variant_id`, `counted` (decimal, 0 or more), optional `unit`, `note`
     * @return array<string, mixed>
     */
    public function recordCount(int $tenantId, int $id, ?int $actor, array $rawLines): array
    {
        $op = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Count');
        if ($op['kind'] !== 'count' || $op['status'] !== 'draft') {
            throw new InventoryRejectedException('Only a count still being counted takes quantities', 409);
        }
        if ($rawLines === [] || count($rawLines) > StockLedger::MAX_LINES) {
            throw InventoryRejectedException::field('lines', 'Send 1 to ' . StockLedger::MAX_LINES . ' lines');
        }
        $place = (int) $op['from_location_id'];

        $this->db->transaction(function () use ($tenantId, $id, $actor, $rawLines, $place): void {
            foreach ($rawLines as $i => $raw) {
                $field = 'lines[' . $i . ']';
                if (!is_array($raw)) {
                    throw InventoryRejectedException::field($field, $field . ' must be an object');
                }
                $variantId = $raw['variant_id'] ?? null;
                $found = is_int($variantId) ? $this->items->variantForStock($tenantId, $variantId) : null;
                if ($found === null) {
                    throw InventoryRejectedException::field($field, $field . '.variant_id is not an item here');
                }
                if ($found['item']['kind'] === 'service') {
                    throw InventoryRejectedException::field($field, $field . ': ' . $found['item']['name'] . ' is a service and has no stock');
                }
                $counted = is_string($raw['counted'] ?? null) || is_int($raw['counted'] ?? null) ? trim((string) $raw['counted']) : '';
                if ($counted === '' || str_starts_with($counted, '-')) {
                    throw InventoryRejectedException::field($field, $field . '.counted is 0 or more');
                }
                $unit = is_string($raw['unit'] ?? null) && $raw['unit'] !== '' ? $raw['unit'] : null;
                $steps = $this->units->toBaseSteps($tenantId, (int) $found['item']['id'], $found['unit'], $counted, $unit, $field . '.counted');
                $expected = $this->ledger->onHandSteps($tenantId, $variantId, $place);
                $note = is_string($raw['note'] ?? null) ? mb_substr(trim($raw['note']), 0, 500) : null;

                $line = $this->db->one(
                    'SELECT id FROM inv_operation_lines WHERE tenant_id = :tenant_id AND operation_id = :op AND variant_id = :variant_id ORDER BY id LIMIT 1',
                    [':tenant_id' => $tenantId, ':op' => $id, ':variant_id' => $variantId]
                );
                if ($line === null) {
                    $order = (int) ($this->db->one(
                        'SELECT MAX(sort_order) AS m FROM inv_operation_lines WHERE tenant_id = :tenant_id AND operation_id = :op',
                        [':tenant_id' => $tenantId, ':op' => $id]
                    )['m'] ?? 0) + 1;
                    $this->db->run(
                        'INSERT INTO inv_operation_lines (tenant_id, operation_id, variant_id, sort_order) VALUES (:tenant_id, :op, :variant_id, :sort_order)',
                        [':tenant_id' => $tenantId, ':op' => $id, ':variant_id' => $variantId, ':sort_order' => $order]
                    );
                }
                $this->db->run(
                    'UPDATE inv_operation_lines
                        SET quantity = :quantity, entered_unit_id = :unit_id, entered_quantity = :entered, expected_quantity = :expected,
                            counted_at = CURRENT_TIMESTAMP, counted_by_profile_id = :actor,
                            note = COALESCE(:note, note), updated_at = CURRENT_TIMESTAMP
                      WHERE tenant_id = :tenant_id AND operation_id = :op AND variant_id = :variant_id',
                    [
                        ':quantity' => $steps['steps'], ':unit_id' => $steps['unit_id'], ':entered' => $steps['entered_steps'],
                        ':expected' => $expected, ':actor' => $actor, ':note' => $note,
                        ':tenant_id' => $tenantId, ':op' => $id, ':variant_id' => $variantId,
                    ]
                );
            }
            $this->touch($tenantId, $id);
        });

        return $this->get($tenantId, $id);
    }

    /**
     * Finishes a count. Lines not counted are left out. Differences post as
     * count corrections, after approval when one is worth more than the
     * workspace's value.
     *
     * @return array<string, mixed>
     */
    public function finishCount(int $tenantId, int $id, ?int $actor): array
    {
        $op = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Count');
        if ($op['kind'] === 'count' && self::nobodyToApprove($op)) {
            return $this->sendForApproval($tenantId, $id, $actor);
        }
        if ($op['kind'] !== 'count' || $op['status'] !== 'draft') {
            throw new InventoryRejectedException('Only a count still being counted can be finished', 409);
        }
        $counted = (int) ($this->db->one(
            'SELECT COUNT(*) AS n FROM inv_operation_lines WHERE tenant_id = :tenant_id AND operation_id = :op AND counted_at IS NOT NULL',
            [':tenant_id' => $tenantId, ':op' => $id]
        )['n'] ?? 0);
        if ($counted === 0) {
            throw new InventoryRejectedException('Count at least one item before finishing', 422, ['field' => 'lines']);
        }
        $this->priceLines($tenantId, $id, true);
        $differences = $this->countDifferences($tenantId, $id);
        $waiting = $differences !== [] && $this->needsApproval($tenantId, $id, SettingsRegistry::INVENTORY_APPROVE_COUNTS_ABOVE);

        if ($differences !== []) {
            $payload = [
                'tenant_id' => $tenantId, 'operation_id' => $id, 'number' => (string) $op['number'],
                'location_id' => (int) $op['from_location_id'], 'waiting_approval' => $waiting,
                'lines' => array_map(static fn (array $d): array => [
                    'variant_id' => $d['variant_id'],
                    'expected' => (new Quantity($d['expected'], $d['decimals']))->toDecimal(),
                    'counted' => (new Quantity($d['counted'], $d['decimals']))->toDecimal(),
                    'difference' => (new Quantity($d['counted'] - $d['expected'], $d['decimals']))->toDecimal(),
                    'value_minor' => $d['value_minor'], 'currency' => $d['currency'],
                ], $differences),
            ];
            $this->hooks?->dispatch(Events::INVENTORY_COUNT_DIFFERENCES, $payload);
            $this->async(Events::INVENTORY_COUNT_DIFFERENCES_ASYNC, $payload);
        }
        if ($waiting) {
            return $this->sendForApproval($tenantId, $id, $actor);
        }

        return $this->post($tenantId, $id, $actor);
    }

    // ── transfers ────────────────────────────────────────────────────────

    /**
     * A transfer from one place to another. Sent at once unless `send` is
     * false; arrives at once when `one_step` (or the workspace setting) says
     * so, otherwise waits "on the way" until received (IV.16).
     *
     * @param array<string, mixed> $data `from_location_id`, `to_location_id`, `lines` (each `variant_id`, `quantity`, `unit`, `note`), `note`, `send`, `one_step`
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, idempotency_key?: ?string, source_plugin?: ?string} $meta
     * @return array{operation: array<string, mixed>, repeated: bool}
     */
    public function transfer(int $tenantId, ?int $actor, array $data, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        if (($done = $this->byIdempotencyKey($tenantId, $meta['idempotency_key'] ?? null)) !== null) {
            return ['operation' => $done, 'repeated' => true];
        }
        $from = $this->realPlace($tenantId, $data['from_location_id'] ?? null, 'from_location_id');
        $to = $this->realPlace($tenantId, $data['to_location_id'] ?? null, 'to_location_id');
        if ($from === $to) {
            throw InventoryRejectedException::field('to_location_id', 'A transfer goes to another place');
        }
        $raw = $this->rawLines($data['lines'] ?? null, 'lines is a list of {variant_id, quantity}');
        $lines = [];
        foreach ($raw as $i => $line) {
            $prepared = $this->ledger->prepareLine($tenantId, [
                'variant_id' => $line['variant_id'] ?? null, 'quantity' => $line['quantity'] ?? null, 'unit' => $line['unit'] ?? null,
                'from_location_id' => $from, 'to_location_id' => $to,
            ], 'lines[' . $i . ']');
            $lines[] = [
                'variant_id' => $prepared['variant_id'], 'quantity' => $prepared['steps'],
                'entered_unit_id' => $prepared['entered_unit_id'], 'entered_quantity' => $prepared['entered_quantity'],
                'note' => is_string($line['note'] ?? null) ? mb_substr(trim($line['note']), 0, 500) : '',
            ];
        }
        foreach (['send', 'one_step'] as $flag) {
            if (array_key_exists($flag, $data) && !is_bool($data[$flag])) {
                throw InventoryRejectedException::field($flag, $flag . ' is true or false');
            }
        }

        $id = $this->insert($tenantId, $actor, 'transfer', [
            'from_location_id' => $from, 'to_location_id' => $to, 'note' => $data['note'] ?? '',
            'occurred_at' => $this->occurredAt($data['occurred_at'] ?? null),
        ], $lines, $meta);
        if (($data['send'] ?? true) === false) {
            return ['operation' => $this->get($tenantId, $id), 'repeated' => false];
        }

        return ['operation' => $this->send($tenantId, $id, $actor, $data['one_step'] ?? null), 'repeated' => false];
    }

    /** @return array<string, mixed> */
    public function send(int $tenantId, int $id, ?int $actor, ?bool $oneStep = null): array
    {
        $op = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Transfer');
        if ($op['kind'] !== 'transfer' || $op['status'] !== 'draft') {
            throw new InventoryRejectedException('Only a draft transfer can be sent', 409);
        }
        $oneStep ??= ($this->setting($tenantId, SettingsRegistry::INVENTORY_TRANSFERS_ONE_STEP) === 'true');
        if ($oneStep) {
            return $this->post($tenantId, $id, $actor);
        }
        $transit = $this->workspace->virtualId($tenantId, 'transit');
        $lines = array_map(static fn (array $l): array => [
            'variant_id' => $l['variant_id'], 'from_location_id' => (int) $op['from_location_id'], 'to_location_id' => $transit,
            'steps' => $l['quantity'], 'entered_unit_id' => $l['entered_unit_id'], 'entered_quantity' => $l['entered_quantity'],
            'note' => $l['note'], 'source_line_id' => $l['id'],
        ], $this->lineRows($tenantId, $id));

        $this->db->transaction(function () use ($tenantId, $id, $actor, $op, $lines): void {
            $posted = $this->ledger->postSteps($tenantId, $actor, $lines, $this->postMeta($op, 'transfer'));
            $this->db->run(
                "UPDATE inv_operations SET status = 'in_transit', sent_batch_key = :batch, sent_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id AND status = 'draft'",
                [':batch' => $posted['batch_key'], ':tenant_id' => $tenantId, ':id' => $id]
            );
        });

        return $this->get($tenantId, $id);
    }

    /**
     * Receives a transfer on the way. Each line arrives in full unless
     * `received` says less; what did not arrive is written off as lost on
     * the way.
     *
     * @param list<mixed> $rawLines optional, each `line_id` and `received` (decimal in the line's base unit)
     * @return array<string, mixed>
     */
    public function receive(int $tenantId, int $id, ?int $actor, array $rawLines = []): array
    {
        $op = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Transfer');
        if ($op['kind'] !== 'transfer' || $op['status'] !== 'in_transit') {
            throw new InventoryRejectedException('Only a transfer on the way can be received', 409);
        }
        $lines = $this->lineRows($tenantId, $id);
        $received = [];
        foreach ($rawLines as $i => $raw) {
            $field = 'lines[' . $i . ']';
            $lineId = is_array($raw) ? ($raw['line_id'] ?? null) : null;
            $match = array_values(array_filter($lines, static fn (array $l): bool => $l['id'] === $lineId));
            if ($match === []) {
                throw InventoryRejectedException::field($field, $field . '.line_id is not a line of this transfer');
            }
            $text = is_string($raw['received'] ?? null) || is_int($raw['received'] ?? null) ? trim((string) $raw['received']) : '';
            try {
                $steps = Quantity::parse($text, $match[0]['decimals'])->steps;
            } catch (\InvalidArgumentException $e) {
                throw InventoryRejectedException::field($field, $field . '.received: ' . $e->getMessage());
            }
            if ($steps < 0 || $steps > $match[0]['quantity']) {
                throw InventoryRejectedException::field($field, $field . '.received is between 0 and what was sent');
            }
            $received[(int) $lineId] = $steps;
        }

        $transit = $this->workspace->virtualId($tenantId, 'transit');
        $loss = $this->workspace->virtualId($tenantId, 'loss');
        $post = [];
        foreach ($lines as $l) {
            $got = $received[$l['id']] ?? $l['quantity'];
            if ($got > 0) {
                $post[] = ['variant_id' => $l['variant_id'], 'from_location_id' => $transit, 'to_location_id' => (int) $op['to_location_id'],
                    'steps' => $got, 'note' => $l['note'], 'source_line_id' => $l['id'], 'reason_code' => ''];
            }
            if ($got < $l['quantity']) {
                $post[] = ['variant_id' => $l['variant_id'], 'from_location_id' => $transit, 'to_location_id' => $loss,
                    'steps' => $l['quantity'] - $got, 'note' => $l['note'], 'source_line_id' => $l['id'], 'reason_code' => 'lost_in_transit'];
            }
        }

        $this->db->transaction(function () use ($tenantId, $id, $actor, $op, $post, $lines, $received): void {
            foreach ($lines as $l) {
                $this->db->run(
                    'UPDATE inv_operation_lines SET received_quantity = :received, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id',
                    [':received' => $received[$l['id']] ?? $l['quantity'], ':tenant_id' => $tenantId, ':id' => $l['id']]
                );
            }
            $posted = $this->ledger->postSteps($tenantId, $actor, $post, $this->postMeta($op, 'transfer'));
            $this->markDone($tenantId, $id, $actor, $posted['batch_key'], 'in_transit');
        });

        return $this->get($tenantId, $id);
    }

    // ── receipts (WHIT-1234) ─────────────────────────────────────────────

    /**
     * A goods receipt against a purchase order (IV.12): its lines post
     * `supplier → place` at once, each move carrying the order's unit cost, so
     * costing can use it. The order keeps its own count of what arrived;
     * {@see PurchaseOrders::receive()} calls this inside its transaction.
     *
     * @param list<array{variant_id: int, steps: int, entered_unit_id: ?int, entered_quantity: ?int, order_line_id: int, unit_cost_minor: ?int, currency: ?string, note: string}> $lines
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, idempotency_key?: ?string, source_plugin?: ?string} $meta
     * @return array<string, mixed> the receipt
     */
    public function receipt(int $tenantId, ?int $actor, int $orderId, int $placeId, array $lines, string $note, ?string $occurredAt, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        if ($lines === []) {
            throw InventoryRejectedException::field('lines', 'Receive at least one line');
        }
        $supplier = $this->workspace->virtualId($tenantId, 'supplier');

        return $this->db->transaction(function () use ($tenantId, $actor, $orderId, $placeId, $lines, $note, $occurredAt, $meta, $supplier): array {
            $id = $this->insert($tenantId, $actor, 'receipt', [
                'to_location_id' => $placeId, 'note' => $note, 'occurred_at' => $this->occurredAt($occurredAt), 'order_id' => $orderId,
            ], array_map(static fn (array $l): array => [
                'variant_id' => $l['variant_id'], 'quantity' => $l['steps'], 'entered_unit_id' => $l['entered_unit_id'],
                'entered_quantity' => $l['entered_quantity'], 'note' => $l['note'], 'order_line_id' => $l['order_line_id'],
            ], $lines), $meta);
            $op = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Receipt');
            $lineIds = array_column($this->lineRows($tenantId, $id), 'id');
            $post = [];
            foreach ($lines as $i => $l) {
                $post[] = [
                    'variant_id' => $l['variant_id'], 'from_location_id' => $supplier, 'to_location_id' => $placeId,
                    'steps' => $l['steps'], 'entered_unit_id' => $l['entered_unit_id'], 'entered_quantity' => $l['entered_quantity'],
                    'unit_cost_minor' => $l['unit_cost_minor'], 'currency' => $l['currency'], 'note' => $l['note'],
                    'source_line_id' => $lineIds[$i] ?? null, 'reason_code' => '',
                ];
            }
            $posted = $this->ledger->postSteps($tenantId, $actor, $post, $this->postMeta($op, 'receipt'));
            $this->markDone($tenantId, $id, $actor, $posted['batch_key'], 'draft');

            return $this->get($tenantId, $id);
        });
    }

    // ── approval, posting, cancelling ────────────────────────────────────

    /**
     * The route approved it: post. Called by {@see OperationRouteSubject}
     * inside the route's own transaction.
     */
    public function approved(int $tenantId, int $id, ?int $decidedBy): void
    {
        $op = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Stock operation');
        if ($op['status'] !== 'waiting_approval') {
            throw new InventoryRejectedException('This stock change has already been decided', 409);
        }
        $this->post($tenantId, $id, $decidedBy);
    }

    /** The route turned it down or sent it back: back to draft. */
    public function rejected(int $tenantId, int $id): void
    {
        $this->db->run(
            "UPDATE inv_operations SET status = 'draft', updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'waiting_approval'",
            [':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    /** @return array<string, mixed> */
    public function cancel(int $tenantId, int $id, ?int $actor, string $note = ''): array
    {
        $op = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Stock operation');
        if (in_array($op['status'], ['done', 'cancelled'], true)) {
            throw new InventoryRejectedException(
                $op['status'] === 'done' ? 'This is done; undo it from the stock history instead' : 'This is already cancelled',
                409
            );
        }
        $this->db->transaction(function () use ($tenantId, $id, $actor, $op, $note): void {
            if ($op['status'] === 'in_transit' && is_string($op['sent_batch_key'])) {
                $this->ledger->reverse($tenantId, $op['sent_batch_key'], $actor, $note !== '' ? $note : 'Transfer ' . $op['number'] . ' cancelled', 'transfer');
            }
            $this->db->run(
                "UPDATE inv_operations SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id AND status = :status",
                [':tenant_id' => $tenantId, ':id' => $id, ':status' => $op['status']]
            );
        });

        return $this->get($tenantId, $id);
    }

    /** @return array<string, mixed> */
    private function post(int $tenantId, int $id, ?int $actor): array
    {
        $op = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Stock operation');
        $place = (int) $op['from_location_id'];
        $lines = [];
        foreach ($this->lineRows($tenantId, $id) as $l) {
            $base = ['variant_id' => $l['variant_id'], 'note' => $l['note'], 'source_line_id' => $l['id'],
                'entered_unit_id' => $l['entered_unit_id'], 'entered_quantity' => $l['entered_quantity']];
            if ($op['kind'] === 'adjustment') {
                $out = $l['quantity'] < 0;
                $other = $this->workspace->virtualId($tenantId, $out ? 'loss' : 'found');
                $lines[] = $base + ['from_location_id' => $out ? $place : $other, 'to_location_id' => $out ? $other : $place,
                    'steps' => (int) abs((int) $l['quantity']), 'reason_code' => $l['reason_code'] !== '' ? $l['reason_code'] : (string) $op['reason_code']];
            } elseif ($op['kind'] === 'count') {
                if ($l['counted_at'] === null || $l['quantity'] === $l['expected']) {
                    continue;
                }
                $diff = $l['quantity'] - $l['expected'];
                $other = $this->workspace->virtualId($tenantId, $diff < 0 ? 'loss' : 'found');
                $lines[] = ['variant_id' => $l['variant_id'], 'note' => $l['note'], 'source_line_id' => $l['id'],
                    'from_location_id' => $diff < 0 ? $place : $other, 'to_location_id' => $diff < 0 ? $other : $place,
                    'steps' => (int) abs($diff), 'reason_code' => 'count_correction'];
            } else {
                $lines[] = $base + ['from_location_id' => $place, 'to_location_id' => (int) $op['to_location_id'], 'steps' => $l['quantity'], 'reason_code' => ''];
            }
        }

        $this->db->transaction(function () use ($tenantId, $id, $actor, $op, $lines): void {
            $batch = null;
            if ($lines !== []) {
                $batch = $this->ledger->postSteps($tenantId, $actor, $lines, $this->postMeta($op, (string) $op['kind']))['batch_key'];
            }
            $this->markDone($tenantId, $id, $actor, $batch, (string) $op['status']);
        });

        return $this->get($tenantId, $id);
    }

    private function markDone(int $tenantId, int $id, ?int $actor, ?string $batchKey, string $from): void
    {
        $this->db->run(
            "UPDATE inv_operations
                SET status = 'done', batch_key = :batch, done_at = CURRENT_TIMESTAMP, done_by_profile_id = :actor,
                    sent_at = COALESCE(sent_at, CASE WHEN kind = 'transfer' THEN CURRENT_TIMESTAMP END), updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status = :from",
            [':batch' => $batchKey, ':actor' => $actor, ':tenant_id' => $tenantId, ':id' => $id, ':from' => $from]
        );
        $op = $this->row($tenantId, $id);
        if ($op === null || $op['status'] !== 'done') {
            throw new InventoryRejectedException('This stock change was changed by someone else meanwhile; open it again', 409);
        }
        $payload = [
            'tenant_id' => $tenantId, 'operation_id' => $id, 'kind' => (string) $op['kind'], 'number' => (string) $op['number'],
            'batch_key' => $batchKey, 'acting_profile_id' => $actor,
        ];
        $this->hooks?->dispatch(Events::INVENTORY_OPERATION_DONE, $payload);
        $this->async(Events::INVENTORY_OPERATION_DONE_ASYNC, $payload);
    }

    /**
     * Sends an operation above the workspace's value to its route. It never
     * posts from here: only the route's approval posts it ({@see approved()}).
     *
     * FAILS CLOSED (WHIT-1301). When nobody can approve it (no route chosen
     * and no one but the writer holds `inventory:settings`, or approvals are
     * not wired), it stays `waiting_approval` with no route, the people who
     * can fix that are told, and sending it again tries the route again.
     *
     * @return array<string, mixed>
     */
    private function sendForApproval(int $tenantId, int $id, ?int $actor): array
    {
        $result = $this->db->transaction(fn (): array => $this->route($tenantId, $id, $actor));
        if ($result['nobody_can_approve'] === true) {
            // After the commit: a notice is not part of the operation.
            $this->approvals?->nobodyCanApprove($tenantId, $actor, $result);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function route(int $tenantId, int $id, ?int $actor): array
    {
        $this->db->run(
            "UPDATE inv_operations SET status = 'waiting_approval', submitted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'draft'",
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        $routeId = $this->approvals?->issue($tenantId, $actor, $this->get($tenantId, $id));
        if ($routeId === null) {
            return $this->get($tenantId, $id);
        }
        $this->db->run(
            'UPDATE inv_operations SET route_id = :route_id WHERE tenant_id = :tenant_id AND id = :id',
            [':route_id' => $routeId, ':tenant_id' => $tenantId, ':id' => $id]
        );

        return $this->get($tenantId, $id);
    }

    /**
     * Waiting for approval with no route: nobody could approve it when it was
     * sent (WHIT-1301).
     *
     * @param array<string, mixed> $op an inv_operations row
     */
    private static function nobodyToApprove(array $op): bool
    {
        return $op['status'] === 'waiting_approval' && $op['route_id'] === null
            && in_array($op['kind'], ['adjustment', 'count'], true);
    }

    // ── reading ──────────────────────────────────────────────────────────

    /**
     * @param array{kind?: ?string, status?: ?string, location_id?: ?int, order_id?: ?int} $filter
     * @param bool $withLines true adds each operation's lines (for the waste and adjustments list)
     * @return list<array<string, mixed>> newest first
     */
    public function list(int $tenantId, array $filter = [], int $limit = 50, int $offset = 0, bool $withLines = false): array
    {
        $where = [];
        $params = [':tenant_id' => $tenantId];
        foreach (['kind' => self::KINDS, 'status' => self::STATUSES] as $key => $allowed) {
            if (($filter[$key] ?? null) !== null) {
                if (!in_array($filter[$key], $allowed, true)) {
                    throw InventoryRejectedException::field($key, $key . ' is one of ' . implode(', ', $allowed));
                }
                $where[] = $key . ' = :' . $key;
                $params[':' . $key] = $filter[$key];
            }
        }
        if (($filter['order_id'] ?? null) !== null) {
            $where[] = 'order_id = :order_id';
            $params[':order_id'] = (int) $filter['order_id'];
        }
        if (($filter['location_id'] ?? null) !== null) {
            $where[] = '(from_location_id = :place OR to_location_id = :place2)';
            $params[':place'] = (int) $filter['location_id'];
            $params[':place2'] = (int) $filter['location_id'];
        }
        $rows = $this->db->all(
            'SELECT ' . self::COLUMNS . ' FROM inv_operations WHERE tenant_id = :tenant_id'
                . ($where === [] ? '' : ' AND ' . implode(' AND ', $where)) . ' ORDER BY id DESC LIMIT :limit OFFSET :offset',
            $params + [':limit' => max(1, min(200, $limit)), ':offset' => max(0, $offset)]
        );
        $counts = [];
        if ($rows !== []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            foreach ($this->db->all(
                'SELECT operation_id, COUNT(*) AS n, COUNT(counted_at) AS counted FROM inv_operation_lines
                  WHERE tenant_id = :tenant_id AND operation_id IN (' . implode(',', $ids) . ') GROUP BY operation_id',
                [':tenant_id' => $tenantId]
            ) as $c) {
                $counts[(int) $c['operation_id']] = [(int) $c['n'], (int) $c['counted']];
            }
        }

        return array_map(function (array $r) use ($counts, $tenantId, $withLines): array {
            [$lines, $counted] = $counts[(int) $r['id']] ?? [0, 0];
            $op = $this->present($r) + ['line_count' => $lines, 'counted_count' => $counted];
            if ($withLines) {
                $op['lines'] = $this->get($tenantId, (int) $r['id'], true)['lines'];
            }

            return $op;
        }, $rows);
    }

    /**
     * @param bool $forCounter true hides expected quantities of a blind count still being counted
     * @return array<string, mixed>
     */
    public function get(int $tenantId, int $id, bool $forCounter = false): array
    {
        $row = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Stock operation');
        $op = $this->present($row);
        $hideExpected = $forCounter && $op['is_blind'] && $op['status'] === 'draft';
        $op['lines'] = array_map(function (array $l) use ($op, $hideExpected): array {
            $q = static fn (?int $steps): ?string => $steps === null ? null : (new Quantity($steps, $l['decimals']))->toDecimal();
            $line = [
                'id' => $l['id'], 'variant_id' => $l['variant_id'], 'item_id' => $l['item_id'], 'item_name' => $l['item_name'], 'item_names' => $l['item_names'],
                'sku' => $l['sku'], 'unit' => $l['unit'], 'decimals' => $l['decimals'],
                'entered_unit' => $l['entered_unit'],
                'entered_quantity' => $l['entered_quantity'] !== null && $l['entered_unit'] !== null
                    ? (new Quantity($l['entered_quantity'], $l['entered_decimals']))->toDecimal() : null,
                'reason_code' => $l['reason_code'], 'note' => $l['note'],
                'value_minor' => $l['value_minor'], 'currency' => $l['currency'],
            ];
            if ($op['kind'] === 'count') {
                $counted = $l['counted_at'] !== null;
                $line += [
                    'counted' => $counted ? $q($l['quantity']) : null,
                    'expected' => $hideExpected || !$counted ? null : $q($l['expected']),
                    'difference' => $hideExpected || !$counted ? null : $q(($l['quantity'] ?? 0) - ($l['expected'] ?? 0)),
                    'counted_at' => $l['counted_at'], 'counted_by_profile_id' => $l['counted_by'],
                ];
            } elseif ($op['kind'] === 'adjustment') {
                $line += ['change' => $q($l['quantity'])];
            } else {
                $line += ['quantity' => $q($l['quantity']), 'received' => $q($l['received'])];
                if ($op['kind'] === 'receipt') {
                    $line += ['order_line_id' => $l['order_line_id']];
                }
            }

            return $line;
        }, $this->lineRows($tenantId, $id));

        return $op;
    }

    /** @return array<string, mixed>|null */
    public function row(int $tenantId, int $id): ?array
    {
        return $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM inv_operations WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $head
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $meta
     */
    private function insert(int $tenantId, ?int $actor, string $kind, array $head, array $lines, array $meta): int
    {
        if (count($lines) > self::MAX_LINES) {
            throw InventoryRejectedException::field('lines', 'Up to ' . self::MAX_LINES . ' lines');
        }
        $key = $meta['idempotency_key'] ?? null;
        if ($key !== null && (!is_string($key) || $key === '' || strlen($key) > 128)) {
            throw InventoryRejectedException::field('idempotency_key', 'idempotency_key is text of up to 128 characters');
        }
        $note = is_string($head['note'] ?? null) ? mb_substr(trim($head['note']), 0, 1000) : '';

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->db->transaction(function () use ($tenantId, $actor, $kind, $head, $lines, $meta, $key, $note): int {
                    $id = $this->db->insert(
                        'INSERT INTO inv_operations (tenant_id, kind, number, status, order_id, from_location_id, to_location_id, category_id, reason_code, note,
                                is_blind, idempotency_key, source_plugin, created_by_profile_id, agent_id, on_behalf_of_profile_id, occurred_at)
                         VALUES (:tenant_id, :kind, :number, \'draft\', :order_id, :from_id, :to_id, :category_id, :reason, :note,
                                :blind, :key, :plugin, :actor, :agent_id, :on_behalf_of, :occurred_at)',
                        [
                            ':tenant_id' => $tenantId, ':kind' => $kind, ':number' => $this->nextNumber($tenantId, $kind),
                            ':order_id' => $head['order_id'] ?? null,
                            ':from_id' => $head['from_location_id'] ?? null, ':to_id' => $head['to_location_id'] ?? null,
                            ':category_id' => $head['category_id'] ?? null, ':reason' => (string) ($head['reason_code'] ?? ''),
                            ':note' => $note, ':blind' => ($head['is_blind'] ?? false) === true, ':key' => $key,
                            ':plugin' => is_string($meta['source_plugin'] ?? null) ? $meta['source_plugin'] : null, ':actor' => $actor,
                            ':agent_id' => InventoryDb::int($meta['agent_id'] ?? null), ':on_behalf_of' => InventoryDb::int($meta['on_behalf_of'] ?? null),
                            ':occurred_at' => $head['occurred_at'] ?? null,
                        ]
                    );
                    foreach ($lines as $i => $l) {
                        $this->db->run(
                            'INSERT INTO inv_operation_lines (tenant_id, operation_id, variant_id, quantity, entered_unit_id, entered_quantity, reason_code, note, sort_order, order_line_id)
                             VALUES (:tenant_id, :op, :variant_id, :quantity, :unit_id, :entered, :reason, :note, :sort_order, :order_line_id)',
                            [
                                ':tenant_id' => $tenantId, ':op' => $id, ':variant_id' => $l['variant_id'], ':quantity' => $l['quantity'],
                                ':unit_id' => $l['entered_unit_id'] ?? null, ':entered' => $l['entered_quantity'] ?? null,
                                ':reason' => (string) ($l['reason_code'] ?? ''), ':note' => (string) ($l['note'] ?? ''), ':sort_order' => $i + 1,
                                ':order_line_id' => $l['order_line_id'] ?? null,
                            ]
                        );
                    }

                    return $id;
                });
            } catch (\PDOException $e) {
                if (!InventoryDb::isUniqueViolation($e) || $attempt >= 4) {
                    throw $e;
                }
                if (is_string($key) && ($done = $this->byIdempotencyKey($tenantId, $key)) !== null) {
                    return (int) $done['id'];
                }
                // Someone took the same number: try the next one.
            }
        }
    }

    private function nextNumber(int $tenantId, string $kind): string
    {
        $last = $this->db->one(
            'SELECT number FROM inv_operations WHERE tenant_id = :tenant_id AND kind = :kind ORDER BY id DESC LIMIT 1',
            [':tenant_id' => $tenantId, ':kind' => $kind]
        );
        $n = $last !== null && preg_match('/(\d+)$/', (string) $last['number'], $m) === 1 ? (int) $m[1] : 0;

        return sprintf('%s-%04d', self::PREFIX[$kind], $n + 1);
    }

    /** @return array<string, mixed>|null */
    private function byIdempotencyKey(int $tenantId, mixed $key): ?array
    {
        if (!is_string($key) || $key === '') {
            return null;
        }
        $row = $this->db->one(
            'SELECT id FROM inv_operations WHERE tenant_id = :tenant_id AND idempotency_key = :key',
            [':tenant_id' => $tenantId, ':key' => $key]
        );

        return $row === null ? null : $this->get($tenantId, (int) $row['id']);
    }

    private function realPlace(int $tenantId, mixed $id, string $field): int
    {
        $row = is_int($id) ? $this->db->one(
            'SELECT id, kind, is_active FROM inv_locations WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) : null;
        if ($row === null || $row['kind'] === 'virtual') {
            throw InventoryRejectedException::field($field, $field . ' is not a place here');
        }
        if (!DbBool::of($row['is_active'])) {
            throw InventoryRejectedException::field($field, $field . ' is a place that is no longer in use');
        }

        return (int) $row['id'];
    }

    /** @return list<array<string, mixed>> */
    private function rawLines(mixed $lines, string $message): array
    {
        if (!is_array($lines) || !array_is_list($lines) || $lines === []) {
            throw InventoryRejectedException::field('lines', $message);
        }
        if (count($lines) > StockLedger::MAX_LINES) {
            throw InventoryRejectedException::field('lines', 'Send up to ' . StockLedger::MAX_LINES . ' lines');
        }
        foreach ($lines as $i => $line) {
            if (!is_array($line)) {
                throw InventoryRejectedException::field('lines[' . $i . ']', 'lines[' . $i . '] must be an object');
            }
        }

        /** @var list<array<string, mixed>> $lines */
        return $lines;
    }

    /**
     * The variants a count starts with.
     *
     * @param list<int>|null $chosen
     * @return list<int>
     */
    private function countScope(int $tenantId, int $place, ?int $category, ?array $chosen): array
    {
        $live = "i.archived_at IS NULL AND v.archived_at IS NULL AND i.kind <> 'service'";
        if ($chosen !== null) {
            if ($chosen === []) {
                return [];
            }
            $rows = $this->db->all(
                'SELECT v.id FROM inv_variants v JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
                  WHERE v.tenant_id = :tenant_id AND ' . $live . ' AND v.id IN (' . implode(',', array_map('intval', $chosen)) . ')
                  ORDER BY i.name, v.id',
                [':tenant_id' => $tenantId]
            );
            if (count($rows) !== count(array_unique($chosen))) {
                throw InventoryRejectedException::field('variant_ids', 'variant_ids has an item that is not here, archived, or a service');
            }
        } elseif ($category !== null) {
            $rows = $this->db->all(
                'SELECT v.id FROM inv_variants v JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
                  WHERE v.tenant_id = :tenant_id AND ' . $live . ' AND i.category_id = :category
                  ORDER BY i.name, v.id',
                [':tenant_id' => $tenantId, ':category' => $category]
            );
        } else {
            $rows = $this->db->all(
                'SELECT DISTINCT v.id, i.name FROM inv_levels l
                   JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
                   JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
                  WHERE l.tenant_id = :tenant_id AND l.location_id = :place AND l.on_hand <> 0 AND ' . $live . '
                  ORDER BY i.name, v.id',
                [':tenant_id' => $tenantId, ':place' => $place]
            );
        }

        return array_values(array_unique(array_map(static fn (array $r): int => (int) $r['id'], $rows)));
    }

    /**
     * Each line's value at the buying price, and the operation's total when
     * every line is in one currency. For a count, the value of its difference.
     */
    private function priceLines(int $tenantId, int $id, bool $differences): void
    {
        $total = 0;
        $currencies = [];
        foreach ($this->lineRows($tenantId, $id) as $l) {
            $steps = $differences
                ? ($l['counted_at'] === null ? 0 : ($l['quantity'] ?? 0) - ($l['expected'] ?? 0))
                : ($l['quantity'] ?? 0);
            [$value, $currency] = $this->value($tenantId, $l, (int) abs($steps));
            $this->db->run(
                'UPDATE inv_operation_lines SET value_minor = :value WHERE tenant_id = :tenant_id AND id = :id',
                [':value' => $value, ':tenant_id' => $tenantId, ':id' => $l['id']]
            );
            if ($value !== null) {
                $total += $value;
                $currencies[(string) $currency] = true;
            }
        }
        $one = count($currencies) === 1 ? (string) array_key_first($currencies) : null;
        $this->db->run(
            'UPDATE inv_operations SET value_minor = :value, currency = :currency, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id',
            [':value' => $one !== null ? $total : null, ':currency' => $one, ':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    /**
     * @param array<string, mixed> $line a {@see self::lineRows()} row
     * @return array{0: ?int, 1: ?string}
     */
    private function value(int $tenantId, array $line, int $steps): array
    {
        $price = $line['variant_price'] ?? $line['item_price'];
        if ($price === null || $steps === 0) {
            return [$price === null ? null : 0, $price === null ? null : $line['currency']];
        }
        $scale = 10 ** $line['decimals'];

        return [intdiv($steps * $price + intdiv($scale, 2), $scale), $line['currency']];
    }

    private function needsApproval(int $tenantId, int $id, string $settingKey): bool
    {
        $threshold = $this->setting($tenantId, $settingKey);
        if ($threshold === '' || $threshold === null) {
            return false;
        }
        foreach ($this->lineRows($tenantId, $id) as $l) {
            if ($l['value_minor'] === null || $l['currency'] === null) {
                continue;
            }
            $exponent = Currency::exponentFor($l['currency']);
            [$whole, $fraction] = array_pad(explode('.', $threshold, 2), 2, '');
            $minor = (int) $whole * (10 ** $exponent) + (int) str_pad(substr($fraction, 0, $exponent), $exponent, '0');
            if ($l['value_minor'] > $minor) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{variant_id: int, expected: int, counted: int, decimals: int, value_minor: ?int, currency: ?string}> */
    private function countDifferences(int $tenantId, int $id): array
    {
        $out = [];
        foreach ($this->lineRows($tenantId, $id) as $l) {
            if ($l['counted_at'] === null || $l['quantity'] === $l['expected']) {
                continue;
            }
            $out[] = ['variant_id' => $l['variant_id'], 'expected' => (int) $l['expected'], 'counted' => (int) $l['quantity'],
                'decimals' => $l['decimals'], 'value_minor' => $l['value_minor'], 'currency' => $l['currency']];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function lineRows(int $tenantId, int $id): array
    {
        $rows = $this->db->all(
            'SELECT l.id, l.variant_id, l.order_line_id, l.quantity, l.entered_unit_id, l.entered_quantity, l.expected_quantity, l.counted_at,
                    l.counted_by_profile_id, l.received_quantity, l.reason_code, l.note, l.value_minor,
                    v.item_id, v.sku, v.purchase_price_minor AS variant_price, i.name AS item_name, i.names AS item_names, i.purchase_price_minor AS item_price,
                    i.currency, u.code AS unit, u.decimals, eu.code AS entered_unit, eu.decimals AS entered_decimals
               FROM inv_operation_lines l
               JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = i.tenant_id AND u.id = i.base_unit_id
               LEFT JOIN inv_units eu ON eu.tenant_id = l.tenant_id AND eu.id = l.entered_unit_id
              WHERE l.tenant_id = :tenant_id AND l.operation_id = :op
              ORDER BY l.sort_order, l.id',
            [':tenant_id' => $tenantId, ':op' => $id]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'variant_id' => (int) $r['variant_id'], 'item_id' => (int) $r['item_id'],
            'order_line_id' => InventoryDb::int($r['order_line_id']),
            'item_name' => (string) $r['item_name'], 'item_names' => InventoryNames::decode($r['item_names'], (string) $r['item_name']),
            'sku' => (string) $r['sku'],
            'quantity' => InventoryDb::int($r['quantity']), 'expected' => InventoryDb::int($r['expected_quantity']),
            'received' => InventoryDb::int($r['received_quantity']),
            'entered_unit_id' => InventoryDb::int($r['entered_unit_id']), 'entered_quantity' => InventoryDb::int($r['entered_quantity']),
            'entered_unit' => is_string($r['entered_unit']) ? $r['entered_unit'] : null, 'entered_decimals' => (int) ($r['entered_decimals'] ?? 0),
            'counted_at' => is_string($r['counted_at']) ? $r['counted_at'] : null, 'counted_by' => InventoryDb::int($r['counted_by_profile_id']),
            'reason_code' => (string) $r['reason_code'], 'note' => (string) $r['note'], 'value_minor' => InventoryDb::int($r['value_minor']),
            'variant_price' => InventoryDb::int($r['variant_price']), 'item_price' => InventoryDb::int($r['item_price']),
            'currency' => is_string($r['currency']) && $r['currency'] !== '' ? $r['currency'] : null,
            'unit' => (string) $r['unit'], 'decimals' => (int) $r['decimals'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $op
     * @return array{source_type: string, source_id: int, reason_code: string, note: string, occurred_at: ?string, agent_id: ?int, on_behalf_of: ?int}
     */
    private function postMeta(array $op, string $sourceType): array
    {
        return [
            'source_type' => $sourceType, 'source_id' => (int) $op['id'], 'reason_code' => (string) $op['reason_code'],
            'note' => (string) $op['note'], 'occurred_at' => is_string($op['occurred_at']) ? $op['occurred_at'] : null,
            'agent_id' => InventoryDb::int($op['agent_id']), 'on_behalf_of' => InventoryDb::int($op['on_behalf_of_profile_id']),
        ];
    }

    private function occurredAt(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $at = is_string($raw) ? new \DateTimeImmutable($raw) : null;
        } catch (\Exception) {
            $at = null;
        }
        if ($at === null) {
            throw InventoryRejectedException::field('occurred_at', 'occurred_at is a date and time such as 2026-11-19T08:20:00Z');
        }

        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function setting(int $tenantId, string $key): ?string
    {
        $value = ($this->settings)($tenantId)[$key] ?? SettingsRegistry::defaults()[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function touch(int $tenantId, int $id): void
    {
        $this->db->run('UPDATE inv_operations SET updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
    }

    /** @param array<string, mixed> $payload */
    private function async(string $event, array $payload): void
    {
        try {
            $this->hooks?->dispatchAsync($event, $payload);
        } catch (\Throwable $e) {
            error_log('[inventory] ' . $event . ' could not be queued: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function present(array $r): array
    {
        $kind = (string) $r['kind'];
        $name = function (mixed $profileId): ?string {
            if (!is_numeric($profileId)) {
                return null;
            }
            $row = $this->db->one('SELECT display_name FROM profiles WHERE id = :id', [':id' => (int) $profileId]);

            return is_string($row['display_name'] ?? null) ? $row['display_name'] : null;
        };

        return [
            'id' => (int) $r['id'],
            'kind' => $kind,
            'number' => (string) $r['number'],
            'status' => (string) $r['status'],
            'order_id' => InventoryDb::int($r['order_id']),
            'location_id' => match ($kind) {
                'transfer' => null,
                'receipt' => InventoryDb::int($r['to_location_id']),
                default => InventoryDb::int($r['from_location_id']),
            },
            'from_location_id' => $kind === 'transfer' ? InventoryDb::int($r['from_location_id']) : null,
            'to_location_id' => InventoryDb::int($r['to_location_id']),
            'category_id' => InventoryDb::int($r['category_id']),
            'reason_code' => (string) $r['reason_code'],
            'note' => (string) $r['note'],
            'is_blind' => DbBool::of($r['is_blind']),
            'value_minor' => InventoryDb::int($r['value_minor']),
            'currency' => is_string($r['currency']) ? $r['currency'] : null,
            'route_id' => InventoryDb::int($r['route_id']),
            // Waiting, and nobody can approve it yet (WHIT-1301).
            'nobody_can_approve' => self::nobodyToApprove($r),
            'batch_key' => is_string($r['batch_key']) ? $r['batch_key'] : null,
            'sent_batch_key' => is_string($r['sent_batch_key']) ? $r['sent_batch_key'] : null,
            'source_plugin' => is_string($r['source_plugin']) ? $r['source_plugin'] : null,
            'created_by_profile_id' => InventoryDb::int($r['created_by_profile_id']),
            'created_by_name' => $name($r['created_by_profile_id']),
            'agent_id' => InventoryDb::int($r['agent_id']),
            'on_behalf_of_profile_id' => InventoryDb::int($r['on_behalf_of_profile_id']),
            'done_by_profile_id' => InventoryDb::int($r['done_by_profile_id']),
            'done_by_name' => $name($r['done_by_profile_id']),
            'occurred_at' => is_string($r['occurred_at']) ? $r['occurred_at'] : null,
            'submitted_at' => is_string($r['submitted_at']) ? $r['submitted_at'] : null,
            'sent_at' => is_string($r['sent_at']) ? $r['sent_at'] : null,
            'done_at' => is_string($r['done_at']) ? $r['done_at'] : null,
            'cancelled_at' => is_string($r['cancelled_at']) ? $r['cancelled_at'] : null,
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
