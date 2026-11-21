<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Import;

use Whity\Core\Db\DbBool;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\SupplierItems;
use Whity\Core\Money\Currency;
use Whity\Core\Money\MoneyException;
use Whity\Sdk\Inventory\Quantity;

/**
 * The `inventory.supplier_prices` import kind (WHIT-1234 P3d, design section
 * 34 §6.2, register IM.3): what each supplier sells and at what price, into
 * `inv_supplier_items`, matched by supplier and SKU.
 *
 * A row names the item by its SKU and the supplier by their contact name (or
 * one supplier is chosen for the whole file). A blank cell keeps what is
 * there. Making a supplier the preferred one takes that from the others, and
 * undo gives it back.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class SupplierPriceRows
{
    public const TARGETS = [
        'supplier' => ['Supplier', ['supplier', 'vendor', 'supplier name', 'vendor name', 'المورد', 'اسم المورد']],
        'sku' => ['SKU', ['sku', 'item code', 'our code', 'item sku', 'رمز الصنف', 'الرمز']],
        'price' => ['Price', ['price', 'supplier price', 'unit price', 'buying price', 'cost', 'السعر', 'سعر المورد', 'سعر الشراء']],
        'currency' => ['Currency', ['currency', 'العملة']],
        'unit' => ['Unit', ['unit', 'uom', 'pack', 'pack size', 'الوحدة', 'العبوة']],
        'supplier_sku' => ['Supplier\'s code', ['supplier sku', 'supplier code', 'vendor code', 'vendor sku', 'their code', 'رمز المورد']],
        'min_quantity' => ['Least they take', ['min quantity', 'minimum', 'minimum order', 'min order', 'moq', 'الحد الأدنى', 'أقل كمية']],
        'lead_days' => ['Lead days', ['lead days', 'lead time', 'delivery days', 'مدة التوريد', 'أيام التوريد']],
        'preferred' => ['Preferred', ['preferred', 'preferred supplier', 'main supplier', 'مفضل', 'المورد المفضل']],
    ];

    private const YES = ['yes', 'y', 'true', '1', 'preferred', 'نعم', 'مفضل'];
    private const NO = ['no', 'n', 'false', '0', 'لا'];

    /** @param \Closure(int): string $currency the workspace's currency */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly ItemService $items,
        private readonly SupplierItems $supplierItems,
        private readonly \Closure $currency,
    ) {
    }

    /**
     * What one row will do. Changes nothing.
     *
     * @param array<string, string> $v
     * @param array<string, mixed> $options
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    public function plan(int $tenantId, array $v, array $options, array $base): array
    {
        $variant = $this->db->one(
            'SELECT v.id, v.item_id, v.archived_at, i.kind, i.archived_at AS item_archived_at
               FROM inv_variants v JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
              WHERE v.tenant_id = :tenant_id AND v.sku = :sku',
            [':tenant_id' => $tenantId, ':sku' => $v['sku']]
        );
        if ($variant === null) {
            return ['action' => 'skip', 'reason' => 'No item has this SKU; import the items first'] + $base;
        }
        if ($variant['archived_at'] !== null || $variant['item_archived_at'] !== null) {
            return ['action' => 'skip', 'reason' => 'The item with this SKU is archived'] + $base;
        }
        if ($variant['kind'] === 'service') {
            return ['action' => 'skip', 'reason' => 'A service has no supplier price'] + $base;
        }
        $variantId = (int) $variant['id'];

        $supplier = ($v['supplier'] ?? '') !== '' ? $this->supplierByName($tenantId, $v['supplier']) : $this->supplierById($tenantId, InventoryDb::int($options['contact_id'] ?? null));
        if (is_string($supplier)) {
            return ['action' => 'skip', 'reason' => $supplier] + $base;
        }
        $base['name'] = $v['sku'] . ' · ' . $supplier['name'];

        $currency = strtoupper($v['currency'] ?? '');
        if ($currency === '') {
            $currency = ($this->currency)($tenantId);
        } elseif (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return ['action' => 'skip', 'reason' => 'The currency is a 3-letter code, like USD'] + $base;
        }
        try {
            $price = Currency::parse(str_replace([',', ' '], '', $v['price'] ?? ''), $currency);
        } catch (MoneyException) {
            return ['action' => 'skip', 'reason' => 'The price is not an amount in ' . $currency] + $base;
        }
        if ($price < 0) {
            return ['action' => 'skip', 'reason' => 'The price can\'t be below zero'] + $base;
        }

        $existing = $this->existing($tenantId, $variantId, $supplier['id']);
        $found = $this->items->variantForStock($tenantId, $variantId);
        if ($found === null) {
            return ['action' => 'skip', 'reason' => 'No item has this SKU; import the items first'] + $base;
        }
        $unitCode = ($v['unit'] ?? '') !== '' ? $v['unit'] : ($existing['unit'] ?? (string) $found['unit']['code']);
        try {
            $unit = $this->supplierItems->unitFor($tenantId, (int) $found['item']['id'], $found['unit'], $unitCode, 'unit');
        } catch (InventoryRejectedException $e) {
            return ['action' => 'skip', 'reason' => 'The unit ' . $e->clientMessage] + $base;
        }

        $min = $existing['min_quantity'] ?? null;
        if (($v['min_quantity'] ?? '') !== '') {
            try {
                $min = Quantity::parse($v['min_quantity'], (int) $unit['decimals'])->toDecimal();
            } catch (\InvalidArgumentException) {
                return ['action' => 'skip', 'reason' => 'The least they take is not a quantity in ' . $unitCode] + $base;
            }
        }
        $lead = $existing['lead_days'] ?? null;
        if (($v['lead_days'] ?? '') !== '') {
            if (preg_match('/^\d{1,3}$/', $v['lead_days']) !== 1 || (int) $v['lead_days'] > 365) {
                return ['action' => 'skip', 'reason' => 'Lead days is a whole number from 0 to 365'] + $base;
            }
            $lead = (int) $v['lead_days'];
        }
        // A blank cell keeps what is true when the row is applied: an earlier row may make another supplier the preferred one.
        $keepPreferred = ($v['preferred'] ?? '') === '';
        $preferred = $existing['is_preferred'] ?? false;
        if (!$keepPreferred) {
            $word = mb_strtolower($v['preferred']);
            if (!in_array($word, self::YES, true) && !in_array($word, self::NO, true)) {
                return ['action' => 'skip', 'reason' => 'Preferred is yes or no'] + $base;
            }
            $preferred = in_array($word, self::YES, true);
        }
        $supplierSku = ($v['supplier_sku'] ?? '') !== '' ? mb_substr($v['supplier_sku'], 0, 64) : ($existing['supplier_sku'] ?? '');

        $data = [
            'variant_id' => $variantId, 'contact_id' => $supplier['id'], 'unit' => $unitCode, 'price_minor' => $price,
            'currency' => $currency, 'supplier_sku' => $supplierSku, 'min_quantity' => $min, 'lead_days' => $lead, 'is_preferred' => $preferred,
        ];
        if ($existing === null) {
            return ['action' => 'create', 'data' => $data, 'keep_preferred' => $keepPreferred] + $base;
        }
        $before = self::input($existing);
        $changes = [];
        foreach ($data as $key => $value) {
            if ($before[$key] !== $value) {
                $changes[] = $key;
            }
        }
        if ($changes === []) {
            return ['action' => 'skip', 'reason' => 'Already up to date'] + $base;
        }

        return ['action' => 'update', 'data' => $data, 'before' => $before, 'changes' => $changes, 'keep_preferred' => $keepPreferred] + $base;
    }

    /**
     * Applies one planned row.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, unpreferred: list<int>}
     */
    public function apply(int $tenantId, array $row): array
    {
        /** @var array<string, mixed> $data */
        $data = $row['data'];
        if (($row['keep_preferred'] ?? false) === true) {
            $now = $this->db->one(
                'SELECT is_preferred FROM inv_supplier_items WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND contact_id = :contact_id',
                [':tenant_id' => $tenantId, ':variant_id' => $data['variant_id'], ':contact_id' => $data['contact_id']]
            );
            $data['is_preferred'] = $now !== null && DbBool::of($now['is_preferred']);
        }
        $unpreferred = [];
        if ($data['is_preferred'] === true) {
            foreach ($this->db->all(
                'SELECT id FROM inv_supplier_items WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND contact_id <> :contact_id AND is_preferred = TRUE',
                [':tenant_id' => $tenantId, ':variant_id' => $data['variant_id'], ':contact_id' => $data['contact_id']]
            ) as $r) {
                $unpreferred[] = (int) $r['id'];
            }
        }
        $saved = $this->supplierItems->set($tenantId, $data, $this->currency);

        return ['id' => (int) $saved['id'], 'unpreferred' => $unpreferred];
    }

    /**
     * Puts back one recorded change: a made row goes, a changed one gets its
     * values back, and the supplier it took "preferred" from gets it back.
     *
     * @param array<string, mixed> $change
     */
    public function undo(int $tenantId, array $change): void
    {
        $id = (int) $change['entity_id'];
        $exists = $this->db->one('SELECT id FROM inv_supplier_items WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $id]) !== null;
        if ($change['action'] === 'created') {
            if ($exists) {
                $this->supplierItems->remove($tenantId, $id);
            }
        } elseif (is_array($change['before'] ?? null)) {
            $this->supplierItems->set($tenantId, $change['before'], $this->currency);
        }
        $unpreferred = $change['extra']['unpreferred'] ?? [];
        foreach (is_array($unpreferred) ? $unpreferred : [] as $other) {
            $this->db->run(
                'UPDATE inv_supplier_items SET is_preferred = TRUE, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => (int) $other]
            );
        }
    }

    /** @return array<string, mixed>|null the supplier's row for this variant, as `SupplierItems` shows it */
    private function existing(int $tenantId, int $variantId, int $contactId): ?array
    {
        $id = InventoryDb::int($this->db->one(
            'SELECT id FROM inv_supplier_items WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND contact_id = :contact_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId, ':contact_id' => $contactId]
        )['id'] ?? null);

        return $id === null ? null : $this->supplierItems->show($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $shown
     * @return array<string, mixed> what `SupplierItems::set` takes to put it back
     */
    private static function input(array $shown): array
    {
        return [
            'variant_id' => (int) $shown['variant_id'], 'contact_id' => (int) $shown['contact_id'], 'unit' => (string) $shown['unit'],
            'price_minor' => (int) $shown['price_minor'], 'currency' => (string) $shown['currency'], 'supplier_sku' => (string) $shown['supplier_sku'],
            'min_quantity' => $shown['min_quantity'], 'lead_days' => $shown['lead_days'], 'is_preferred' => $shown['is_preferred'] === true,
        ];
    }

    /** @return array{id: int, name: string}|string the supplier, or why the row is skipped */
    private function supplierByName(int $tenantId, string $name): array|string
    {
        $rows = $this->db->all(
            'SELECT id, name, is_supplier FROM contacts WHERE tenant_id = :tenant_id AND archived_at IS NULL AND LOWER(name) = :name',
            [':tenant_id' => $tenantId, ':name' => mb_strtolower(trim($name))]
        );
        if ($rows === []) {
            return 'There is no contact called "' . $name . '"; add the supplier first';
        }
        if (count($rows) > 1) {
            return 'More than one contact is called "' . $name . '"; give them different names, or choose the supplier for the whole file';
        }
        if (!DbBool::of($rows[0]['is_supplier'])) {
            return $rows[0]['name'] . ' is not marked as a supplier; mark them as one on their contact page first';
        }

        return ['id' => (int) $rows[0]['id'], 'name' => (string) $rows[0]['name']];
    }

    /** @return array{id: int, name: string}|string */
    private function supplierById(int $tenantId, ?int $id): array|string
    {
        if ($id === null) {
            return 'No supplier';
        }
        try {
            return $this->supplierItems->supplier($tenantId, $id, 'contact_id');
        } catch (InventoryRejectedException $e) {
            return $e->clientMessage;
        }
    }
}
