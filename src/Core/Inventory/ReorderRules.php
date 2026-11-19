<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Inventory\Quantity;

/**
 * Reorder levels and the watcher that tells when stock goes under one
 * (WHIT-1233, design 31 §3.6, register IV.11).
 *
 * A rule is per variant and place: the least to keep (`min_quantity`) and,
 * optionally, what to order up to, the usual supplier and their lead days.
 * After every posting ({@see Events::STOCK_MOVED}) the watcher compares what
 * is available at each place touched with its rule. Going under fires
 * {@see Events::STOCK_LEVEL_BELOW_REORDER} once; coming back fires
 * {@see Events::STOCK_LEVEL_RESTORED}. `is_below` remembers which side it is
 * on, so a busy item does not repeat the event on every sale.
 *
 * The Stock assistant listens and proposes purchase orders; nothing is ordered
 * here.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class ReorderRules
{
    private const COLUMNS = 'r.id, r.variant_id, r.location_id, r.min_quantity, r.order_up_to, r.supplier_contact_id, r.lead_days,
        r.is_active, r.is_below, r.below_since, r.updated_at';

    public function __construct(
        private readonly InventoryDb $db,
        private readonly ItemService $items,
        private readonly StockLedger $ledger,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /** Watch every posting. */
    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen(Events::STOCK_MOVED, [$this, 'onStockMoved'], 50);
    }

    /**
     * @param array<string, mixed> $data the `stock.moved` payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onStockMoved(array $data, array $context = []): array
    {
        $tenantId = is_int($data['tenant_id'] ?? null) ? $data['tenant_id'] : null;
        $moves = is_array($data['moves'] ?? null) ? $data['moves'] : [];
        if ($tenantId === null || $moves === []) {
            return $data;
        }
        $pairs = [];
        foreach ($moves as $m) {
            if (!is_array($m) || !is_int($m['variant_id'] ?? null)) {
                continue;
            }
            foreach (['from_location_id', 'to_location_id'] as $side) {
                if (is_int($m[$side] ?? null)) {
                    $pairs[$m['variant_id'] . ':' . $m[$side]] = [$m['variant_id'], $m[$side]];
                }
            }
        }
        if ($pairs === []) {
            return $data;
        }
        $variantIds = array_unique(array_map(static fn (array $p): int => $p[0], $pairs));
        $rules = $this->db->all(
            'SELECT id, variant_id, location_id FROM inv_reorder_rules
              WHERE tenant_id = :tenant_id AND is_active = TRUE AND variant_id IN (' . implode(',', array_map('intval', $variantIds)) . ')',
            [':tenant_id' => $tenantId]
        );
        foreach ($rules as $rule) {
            if (isset($pairs[(int) $rule['variant_id'] . ':' . (int) $rule['location_id']])) {
                $this->evaluate($tenantId, (int) $rule['id']);
            }
        }

        return $data;
    }

    /**
     * @param array{variant_id?: ?int, location_id?: ?int, below?: bool} $filter
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filter = []): array
    {
        $where = ['r.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        foreach (['variant_id' => 'r.variant_id', 'location_id' => 'r.location_id'] as $key => $column) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = $column . ' = :' . $key;
                $params[':' . $key] = (int) $filter[$key];
            }
        }
        if ($filter['below'] ?? false) {
            $where[] = 'r.is_below = TRUE AND r.is_active = TRUE';
        }

        return array_map(fn (array $r): array => $this->present($tenantId, $r), $this->db->all(
            'SELECT ' . self::COLUMNS . ', v.item_id, v.sku, i.name AS item_name, u.code AS unit, u.decimals
               FROM inv_reorder_rules r
               JOIN inv_variants v ON v.tenant_id = r.tenant_id AND v.id = r.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = i.tenant_id AND u.id = i.base_unit_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY i.name, r.variant_id, r.location_id
              LIMIT 2000',
            $params
        ));
    }

    /**
     * Sets the rule for a variant at a place, adding it or changing it.
     *
     * @param array<string, mixed> $data `variant_id`, `location_id`, `min_quantity`, `order_up_to` (or null), `supplier_contact_id`, `lead_days`, `is_active`
     * @return array<string, mixed>
     */
    public function set(int $tenantId, array $data): array
    {
        $variantId = $data['variant_id'] ?? null;
        $found = is_int($variantId) ? $this->items->variantForStock($tenantId, $variantId) : null;
        if ($found === null || $found['item']['kind'] === 'service') {
            throw InventoryRejectedException::field('variant_id', 'variant_id is not an item with stock here');
        }
        $place = $data['location_id'] ?? null;
        if (!is_int($place) || $this->db->one(
            "SELECT id FROM inv_locations WHERE tenant_id = :tenant_id AND id = :id AND kind <> 'virtual'",
            [':tenant_id' => $tenantId, ':id' => $place]
        ) === null) {
            throw InventoryRejectedException::field('location_id', 'location_id is not a place here');
        }
        $decimals = (int) $found['unit']['decimals'];
        $min = $this->quantity($data['min_quantity'] ?? null, $decimals, 'min_quantity');
        $upTo = ($data['order_up_to'] ?? null) === null ? null : $this->quantity($data['order_up_to'], $decimals, 'order_up_to');
        if ($upTo !== null && $upTo < $min) {
            throw InventoryRejectedException::field('order_up_to', 'order_up_to is at least the reorder level');
        }
        $supplier = $data['supplier_contact_id'] ?? null;
        if ($supplier !== null && (!is_int($supplier) || $this->db->one(
            'SELECT id FROM contacts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $supplier]
        ) === null)) {
            throw InventoryRejectedException::field('supplier_contact_id', 'supplier_contact_id is not a contact here');
        }
        $lead = $data['lead_days'] ?? null;
        if ($lead !== null && (!is_int($lead) || $lead < 0 || $lead > 365)) {
            throw InventoryRejectedException::field('lead_days', 'lead_days is 0 to 365');
        }
        $active = $data['is_active'] ?? true;
        if (!is_bool($active)) {
            throw InventoryRejectedException::field('is_active', 'is_active is true or false');
        }

        $this->db->run(
            'INSERT INTO inv_reorder_rules (tenant_id, variant_id, location_id, min_quantity, order_up_to, supplier_contact_id, lead_days, is_active)
             VALUES (:tenant_id, :variant_id, :location_id, :min, :up_to, :supplier, :lead, :active)
             ON CONFLICT (tenant_id, variant_id, location_id)
             DO UPDATE SET min_quantity = excluded.min_quantity, order_up_to = excluded.order_up_to,
                           supplier_contact_id = excluded.supplier_contact_id, lead_days = excluded.lead_days,
                           is_active = excluded.is_active, updated_at = CURRENT_TIMESTAMP',
            [
                ':tenant_id' => $tenantId, ':variant_id' => $variantId, ':location_id' => $place, ':min' => $min, ':up_to' => $upTo,
                ':supplier' => $supplier, ':lead' => $lead, ':active' => $active,
            ]
        );
        $id = (int) ($this->db->one(
            'SELECT id FROM inv_reorder_rules WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND location_id = :location_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId, ':location_id' => $place]
        )['id'] ?? 0);
        $this->evaluate($tenantId, $id);

        return $this->show($tenantId, $id);
    }

    public function remove(int $tenantId, int $id): void
    {
        $this->show($tenantId, $id);
        $this->db->run('DELETE FROM inv_reorder_rules WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
    }

    /** @return array<string, mixed> */
    public function show(int $tenantId, int $id): array
    {
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ', v.item_id, v.sku, i.name AS item_name, u.code AS unit, u.decimals
               FROM inv_reorder_rules r
               JOIN inv_variants v ON v.tenant_id = r.tenant_id AND v.id = r.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = i.tenant_id AND u.id = i.base_unit_id
              WHERE r.tenant_id = :tenant_id AND r.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );

        return $this->present($tenantId, $row ?? throw InventoryRejectedException::notFound('Reorder level'));
    }

    /**
     * Compares one rule with what is available now and fires the event when
     * it crosses.
     */
    public function evaluate(int $tenantId, int $ruleId): void
    {
        $rule = $this->db->one(
            'SELECT r.id, r.variant_id, r.location_id, r.min_quantity, r.order_up_to, r.supplier_contact_id, r.is_active, r.is_below, u.decimals
               FROM inv_reorder_rules r
               JOIN inv_variants v ON v.tenant_id = r.tenant_id AND v.id = r.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = i.tenant_id AND u.id = i.base_unit_id
              WHERE r.tenant_id = :tenant_id AND r.id = :id',
            [':tenant_id' => $tenantId, ':id' => $ruleId]
        );
        if ($rule === null) {
            return;
        }
        $active = DbBool::of($rule['is_active']);
        $wasBelow = DbBool::of($rule['is_below']);
        $available = $this->ledger->availableSteps($tenantId, (int) $rule['variant_id'], (int) $rule['location_id']);
        $isBelow = $active && $available < (int) $rule['min_quantity'];
        if ($isBelow === $wasBelow) {
            return;
        }
        $this->db->run(
            'UPDATE inv_reorder_rules SET is_below = :below, below_since = ' . ($isBelow ? 'CURRENT_TIMESTAMP' : 'NULL') . '
              WHERE tenant_id = :tenant_id AND id = :id',
            [':below' => $isBelow, ':tenant_id' => $tenantId, ':id' => $ruleId]
        );
        if (!$active && $wasBelow) {
            // Turning a rule off clears it quietly: nothing came back.
            return;
        }
        $decimals = (int) $rule['decimals'];
        $q = static fn (int $steps): string => (new Quantity($steps, $decimals))->toDecimal();
        $payload = [
            'tenant_id' => $tenantId, 'rule_id' => $ruleId, 'variant_id' => (int) $rule['variant_id'],
            'location_id' => (int) $rule['location_id'], 'available' => $q($available), 'min_quantity' => $q((int) $rule['min_quantity']),
        ];
        if ($isBelow) {
            $upTo = InventoryDb::int($rule['order_up_to']);
            $payload += [
                'order_up_to' => $upTo === null ? null : $q($upTo),
                'suggested_quantity' => $q(self::suggested($available, (int) $rule['min_quantity'], $upTo)),
                'supplier_contact_id' => InventoryDb::int($rule['supplier_contact_id']),
            ];
        }
        $this->hooks?->dispatch($isBelow ? Events::STOCK_LEVEL_BELOW_REORDER : Events::STOCK_LEVEL_RESTORED, $payload);
        try {
            $this->hooks?->dispatchAsync($isBelow ? Events::STOCK_LEVEL_BELOW_REORDER_ASYNC : Events::STOCK_LEVEL_RESTORED_ASYNC, $payload);
        } catch (\Throwable $e) {
            error_log('[inventory] reorder event could not be queued: ' . $e->getMessage());
        }
    }

    /** How much to order: up to `order_up_to`, else back to the reorder level. */
    public static function suggested(int $available, int $min, ?int $upTo): int
    {
        return max(0, ($upTo ?? $min) - $available);
    }

    private function quantity(mixed $raw, int $decimals, string $field): int
    {
        $text = is_string($raw) || is_int($raw) ? trim((string) $raw) : '';
        if ($text === '' || str_starts_with($text, '-')) {
            throw InventoryRejectedException::field($field, $field . ' is a quantity, 0 or more');
        }
        try {
            return Quantity::parse($text, $decimals)->steps;
        } catch (\InvalidArgumentException $e) {
            throw InventoryRejectedException::field($field, $field . ': ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function present(int $tenantId, array $r): array
    {
        $decimals = (int) $r['decimals'];
        $q = static fn (?int $steps): ?string => $steps === null ? null : (new Quantity($steps, $decimals))->toDecimal();
        $available = $this->ledger->availableSteps($tenantId, (int) $r['variant_id'], (int) $r['location_id']);
        $upTo = InventoryDb::int($r['order_up_to']);

        return [
            'id' => (int) $r['id'],
            'item_id' => (int) $r['item_id'],
            'item_name' => (string) $r['item_name'],
            'variant_id' => (int) $r['variant_id'],
            'sku' => (string) $r['sku'],
            'location_id' => (int) $r['location_id'],
            'unit' => (string) $r['unit'],
            'decimals' => $decimals,
            'min_quantity' => $q((int) $r['min_quantity']),
            'order_up_to' => $q($upTo),
            'supplier_contact_id' => InventoryDb::int($r['supplier_contact_id']),
            'lead_days' => InventoryDb::int($r['lead_days']),
            'is_active' => DbBool::of($r['is_active']),
            'is_below' => DbBool::of($r['is_below']),
            'below_since' => is_string($r['below_since']) ? $r['below_since'] : null,
            'available' => $q($available),
            'suggested_quantity' => DbBool::of($r['is_below']) ? $q(self::suggested($available, (int) $r['min_quantity'], $upTo)) : null,
        ];
    }
}
