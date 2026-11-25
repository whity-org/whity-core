<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Inbox\InboxItem;
use Whity\Sdk\Inbox\InboxSource;

/**
 * Stock with no cost, in the Inbox's Waiting for me (WHIT-1318 A4a, design
 * section 32 §6).
 *
 * Stock that came in with no purchase price and no priced receipt has no
 * moving average cost, so the books cannot value it: it posts nothing, and
 * the Stock account leaves it out rather than guess. Each such item waits
 * here, for the people who can set accounting up, until it has a cost.
 *
 * It only reads. The rows exist once stock went into the books (the first
 * stock change values every item), so before that, or with stock in the
 * books off, nothing waits.
 */
final class StockCostInboxSource implements InboxSource
{
    public const KEY = 'accounting_stock_cost';

    /** Most items read at once; a ceiling, not a page. */
    private const SCAN = 500;

    /**
     * @param \Closure(int, string, int): bool $hasPermission profile, permission, workspace
     */
    public function __construct(
        private readonly AccountingDb $db,
        private readonly \Closure $hasPermission,
    ) {
    }

    public function label(): string
    {
        return 'Stock with no cost';
    }

    public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array
    {
        return array_slice($this->items($tenantId, $profileId), $offset, $limit);
    }

    public function count(int $tenantId, int $profileId, bool $openOnly): int
    {
        return count($this->items($tenantId, $profileId));
    }

    /** @return list<InboxItem> */
    private function items(int $tenantId, int $profileId): array
    {
        if (!($this->hasPermission)($profileId, CorePermissions::ACCOUNTING_SETTINGS, $tenantId)) {
            return [];
        }
        $books = $this->db->one(
            'SELECT stock_in_books, stock_cutover_at FROM acc_books WHERE tenant_id = :tenant_id',
            [':tenant_id' => $tenantId]
        );
        if ($books === null || $books['stock_cutover_at'] === null || !\Whity\Core\Db\DbBool::of($books['stock_in_books'])) {
            return [];
        }

        return array_map(static fn (array $r): InboxItem => new InboxItem(
            id: (string) $r['variant_id'],
            title: (string) $r['item_name'],
            subtitle: 'No cost yet, so this stock is not in the books. Give it a cost in Accounting, under Stock.',
            timestamp: (string) $r['updated_at'],
            status: 'waiting',
            resourceType: 'inventory_item',
            resourceId: (string) $r['item_id'],
            meta: ['variant_id' => (int) $r['variant_id'], 'quantity' => (int) $r['quantity']],
        ), $this->db->all(
            'SELECT c.variant_id, v.item_id, i.name AS item_name, c.quantity, c.updated_at
               FROM inv_costs c
               JOIN inv_variants v ON v.id = c.variant_id AND v.tenant_id = c.tenant_id
               JOIN inv_items i ON i.id = v.item_id AND i.tenant_id = v.tenant_id
              WHERE c.tenant_id = :tenant_id AND c.quantity <> 0 AND c.value_minor IS NULL
              ORDER BY c.updated_at DESC, c.variant_id DESC LIMIT ' . self::SCAN,
            [':tenant_id' => $tenantId]
        ));
    }
}
