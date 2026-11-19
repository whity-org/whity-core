<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Db\DbBool;
use Whity\Sdk\Inventory\Quantity;

/**
 * What a supplier sells us (WHIT-1234, design 31 §3.2 `inv_supplier_items`,
 * register IV.12): for an item variant and a supplier contact, the supplier's
 * own code, the unit they sell in (a 25 kg bag), the price of one of that unit
 * in its currency, the least they take, and their lead days. One per variant
 * and supplier; the preferred one fills a new purchase order's price.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class SupplierItems
{
    private const COLUMNS = 's.id, s.variant_id, s.contact_id, s.supplier_sku, s.unit_id, s.price_minor, s.currency, s.min_quantity,
        s.lead_days, s.is_preferred, s.updated_at';

    public function __construct(
        private readonly InventoryDb $db,
        private readonly ItemService $items,
        private readonly UnitCatalog $units,
    ) {
    }

    /**
     * @param array{variant_id?: ?int, contact_id?: ?int, item_id?: ?int} $filter
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filter = []): array
    {
        $where = [];
        $params = [':tenant_id' => $tenantId];
        foreach (['variant_id' => 's.variant_id', 'contact_id' => 's.contact_id', 'item_id' => 'v.item_id'] as $key => $column) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = $column . ' = :' . $key;
                $params[':' . $key] = (int) $filter[$key];
            }
        }

        return array_map(self::present(...), $this->db->all(
            'SELECT ' . self::COLUMNS . ', v.item_id, v.sku, i.name AS item_name, i.names AS item_names, c.name AS contact_name,
                    u.code AS unit, u.decimals AS unit_decimals
               FROM inv_supplier_items s
               JOIN inv_variants v ON v.tenant_id = s.tenant_id AND v.id = s.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN contacts c ON c.tenant_id = s.tenant_id AND c.id = s.contact_id
               JOIN inv_units u ON u.tenant_id = s.tenant_id AND u.id = s.unit_id
              WHERE s.tenant_id = :tenant_id' . ($where === [] ? '' : ' AND ' . implode(' AND ', $where)) . '
              ORDER BY i.name, s.is_preferred DESC, c.name
              LIMIT 2000',
            $params
        ));
    }

    /**
     * Sets what a supplier sells: adds it or changes it.
     *
     * @param array<string, mixed> $data `variant_id`, `contact_id`, `unit` (code; the item's base unit when left out), `price_minor`, `currency`, `supplier_sku`, `min_quantity`, `lead_days`, `is_preferred`
     * @param \Closure(int): string $defaultCurrency
     * @return array<string, mixed>
     */
    public function set(int $tenantId, array $data, \Closure $defaultCurrency): array
    {
        $variantId = $data['variant_id'] ?? null;
        $found = is_int($variantId) ? $this->items->variantForStock($tenantId, $variantId) : null;
        if ($found === null || $found['item']['kind'] === 'service') {
            throw InventoryRejectedException::field('variant_id', 'variant_id is not an item with stock here');
        }
        $contactId = $data['contact_id'] ?? null;
        $this->supplier($tenantId, $contactId, 'contact_id');
        $unitCode = is_string($data['unit'] ?? null) && $data['unit'] !== '' ? $data['unit'] : (string) $found['unit']['code'];
        $unit = $this->unitFor($tenantId, (int) $found['item']['id'], $found['unit'], $unitCode, 'unit');
        $price = $data['price_minor'] ?? null;
        if (!is_int($price) || $price < 0) {
            throw InventoryRejectedException::field('price_minor', 'price_minor is the price of one ' . $unitCode . ' in minor units, 0 or more');
        }
        $currency = is_string($data['currency'] ?? null) && $data['currency'] !== '' ? strtoupper($data['currency']) : $defaultCurrency($tenantId);
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw InventoryRejectedException::field('currency', 'currency is a 3-letter code');
        }
        $min = null;
        if (($data['min_quantity'] ?? null) !== null) {
            try {
                $min = Quantity::parse((string) $data['min_quantity'], (int) $unit['decimals'])->steps;
            } catch (\InvalidArgumentException $e) {
                throw InventoryRejectedException::field('min_quantity', 'min_quantity: ' . $e->getMessage());
            }
        }
        $lead = $data['lead_days'] ?? null;
        if ($lead !== null && (!is_int($lead) || $lead < 0 || $lead > 365)) {
            throw InventoryRejectedException::field('lead_days', 'lead_days is 0 to 365');
        }
        $preferred = $data['is_preferred'] ?? false;
        if (!is_bool($preferred)) {
            throw InventoryRejectedException::field('is_preferred', 'is_preferred is true or false');
        }
        $sku = is_string($data['supplier_sku'] ?? null) ? mb_substr(trim($data['supplier_sku']), 0, 64) : '';

        $this->db->transaction(function () use ($tenantId, $variantId, $contactId, $sku, $unit, $price, $currency, $min, $lead, $preferred): void {
            if ($preferred) {
                // One preferred supplier per variant.
                $this->db->run(
                    'UPDATE inv_supplier_items SET is_preferred = FALSE, updated_at = CURRENT_TIMESTAMP
                      WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND contact_id <> :contact_id AND is_preferred = TRUE',
                    [':tenant_id' => $tenantId, ':variant_id' => $variantId, ':contact_id' => $contactId]
                );
            }
            $this->db->run(
                'INSERT INTO inv_supplier_items (tenant_id, variant_id, contact_id, supplier_sku, unit_id, price_minor, currency, min_quantity, lead_days, is_preferred)
                 VALUES (:tenant_id, :variant_id, :contact_id, :sku, :unit_id, :price, :currency, :min, :lead, :preferred)
                 ON CONFLICT (tenant_id, variant_id, contact_id)
                 DO UPDATE SET supplier_sku = excluded.supplier_sku, unit_id = excluded.unit_id, price_minor = excluded.price_minor,
                               currency = excluded.currency, min_quantity = excluded.min_quantity, lead_days = excluded.lead_days,
                               is_preferred = excluded.is_preferred, updated_at = CURRENT_TIMESTAMP',
                [
                    ':tenant_id' => $tenantId, ':variant_id' => $variantId, ':contact_id' => $contactId, ':sku' => $sku,
                    ':unit_id' => (int) $unit['id'], ':price' => $price, ':currency' => $currency, ':min' => $min, ':lead' => $lead,
                    ':preferred' => $preferred,
                ]
            );
        });
        $id = (int) ($this->db->one(
            'SELECT id FROM inv_supplier_items WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND contact_id = :contact_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId, ':contact_id' => $contactId]
        )['id'] ?? 0);

        return $this->show($tenantId, $id);
    }

    public function remove(int $tenantId, int $id): void
    {
        $this->show($tenantId, $id);
        $this->db->run('DELETE FROM inv_supplier_items WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
    }

    /** @return array<string, mixed> */
    public function show(int $tenantId, int $id): array
    {
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ', v.item_id, v.sku, i.name AS item_name, i.names AS item_names, c.name AS contact_name,
                    u.code AS unit, u.decimals AS unit_decimals
               FROM inv_supplier_items s
               JOIN inv_variants v ON v.tenant_id = s.tenant_id AND v.id = s.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN contacts c ON c.tenant_id = s.tenant_id AND c.id = s.contact_id
               JOIN inv_units u ON u.tenant_id = s.tenant_id AND u.id = s.unit_id
              WHERE s.tenant_id = :tenant_id AND s.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );

        return self::present($row ?? throw InventoryRejectedException::notFound('Supplier price'));
    }

    /**
     * The price a supplier asks for a variant in a unit: theirs when they sell
     * it in that unit, else null.
     *
     * @return array{price_minor: int, currency: string}|null
     */
    public function priceFor(int $tenantId, int $variantId, int $contactId, int $unitId): ?array
    {
        $row = $this->db->one(
            'SELECT price_minor, currency FROM inv_supplier_items
              WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND contact_id = :contact_id AND unit_id = :unit_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId, ':contact_id' => $contactId, ':unit_id' => $unitId]
        );

        return $row === null ? null : ['price_minor' => (int) $row['price_minor'], 'currency' => (string) $row['currency']];
    }

    /**
     * A contact that is a supplier here.
     *
     * @return array{id: int, name: string}
     */
    public function supplier(int $tenantId, mixed $contactId, string $field): array
    {
        $row = is_int($contactId) ? $this->db->one(
            'SELECT id, name, is_supplier, archived_at FROM contacts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $contactId]
        ) : null;
        if ($row === null || $row['archived_at'] !== null) {
            throw InventoryRejectedException::field($field, $field . ' is not a contact here');
        }
        if (!DbBool::of($row['is_supplier'])) {
            throw InventoryRejectedException::field($field, $row['name'] . ' is not marked as a supplier; mark them as one on their contact page first');
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /**
     * A unit the item is bought or counted in.
     *
     * @param array<string, mixed> $baseUnit
     * @return array<string, mixed>
     */
    public function unitFor(int $tenantId, int $itemId, array $baseUnit, string $code, string $field): array
    {
        $unit = $this->units->rowByCode($tenantId, $code);
        if ($unit === null || $this->units->factorToBase($tenantId, $itemId, $baseUnit, $code) === null) {
            throw InventoryRejectedException::field($field, '"' . $code . '" doesn\'t convert to ' . $baseUnit['code'] . ' for this item');
        }

        return $unit;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function present(array $r): array
    {
        $min = InventoryDb::int($r['min_quantity']);

        return [
            'id' => (int) $r['id'],
            'variant_id' => (int) $r['variant_id'],
            'item_id' => (int) $r['item_id'],
            'item_name' => (string) $r['item_name'],
            'item_names' => InventoryNames::decode($r['item_names'], (string) $r['item_name']),
            'sku' => (string) $r['sku'],
            'contact_id' => (int) $r['contact_id'],
            'contact_name' => (string) $r['contact_name'],
            'supplier_sku' => (string) $r['supplier_sku'],
            'unit' => (string) $r['unit'],
            'price_minor' => (int) $r['price_minor'],
            'currency' => (string) $r['currency'],
            'min_quantity' => $min === null ? null : (new Quantity($min, (int) $r['unit_decimals']))->toDecimal(),
            'lead_days' => InventoryDb::int($r['lead_days']),
            'is_preferred' => DbBool::of($r['is_preferred']),
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
