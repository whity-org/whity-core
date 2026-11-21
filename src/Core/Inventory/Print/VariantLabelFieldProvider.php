<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Print;

use Whity\Core\i18n\LocalizedText;
use Whity\Core\RBAC\CorePermissions;

/**
 * An item, as one variant, as a record type for label sheets (IV.20,
 * WHIT-1234): its name in each language, SKU, barcode and selling price.
 * A variant with no barcode prints its SKU in the barcode, so every label
 * scans back to the item.
 *
 * Reading one needs `inventory:read`.
 *
 * @i18n-keys documents
 *   fields.item.record = Item
 *   fields.item.group = Item
 *   fields.item.name = Name
 *   fields.item.nameAr = Name in Arabic
 *   fields.item.options = Variant
 *   fields.item.sku = SKU
 *   fields.item.barcode = Barcode
 *   fields.item.price = Price
 *   fields.item.unit = Unit
 */
final class VariantLabelFieldProvider extends InventoryPrintFields
{
    public const RECORD_TYPE = 'inventory.variant';

    private const FIELDS = [
        'name' => ['fields.item.name', 'Name', 'Sourdough loaf'],
        'name_ar' => ['fields.item.nameAr', 'Name in Arabic', 'خبز العجين المخمّر'],
        'options' => ['fields.item.options', 'Variant', 'Large'],
        'sku' => ['fields.item.sku', 'SKU', 'SRD-L'],
        'barcode' => ['fields.item.barcode', 'Barcode', '6291041500213'],
        'price' => ['fields.item.price', 'Price', '$4.50'],
        'unit' => ['fields.item.unit', 'Unit', 'pc'],
    ];

    public function recordType(): string
    {
        return self::RECORD_TYPE;
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.item.record', 'Item');
    }

    public function groups(int $tenantId): array
    {
        return [['group' => $this->labels->get('fields.item.group', 'Item'), 'items' => $this->items('item', self::FIELDS)]];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        $id = $recordId !== null ? self::digits($recordId) : null;
        if ($id === null || !$hasPermission(CorePermissions::INVENTORY_READ)) {
            return null;
        }
        $v = $this->one(
            'SELECT v.sku, v.options, v.sale_price_minor AS variant_price, i.name, i.names, i.sale_price_minor AS item_price, i.currency,
                    u.code AS unit,
                    (SELECT b.code FROM inv_barcodes b WHERE b.tenant_id = v.tenant_id AND b.variant_id = v.id ORDER BY b.unit_id IS NOT NULL, b.id LIMIT 1) AS barcode
               FROM inv_variants v
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = i.tenant_id AND u.id = i.base_unit_id
              WHERE v.tenant_id = :tenant_id AND v.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        if ($v === null) {
            return null;
        }
        [$en, $ar] = self::names($v['names'], (string) $v['name']);
        $price = $v['variant_price'] ?? $v['item_price'];
        $currency = is_string($v['currency']) && $v['currency'] !== '' ? $v['currency'] : $this->workspaceCurrency($tenantId);
        $options = self::options($v['options']);
        $sku = (string) $v['sku'];

        return [
            'label' => $en . ($options !== null ? ' · ' . $options : '') . ' · ' . $sku,
            'values' => [
                'item.name' => $en,
                'item.name_ar' => $ar,
                'item.options' => $options,
                'item.sku' => self::text($sku),
                'item.barcode' => self::text($v['barcode']) ?? self::text($sku),
                'item.price' => $price !== null ? self::money((int) $price, $currency) : null,
                'item.unit' => self::text($v['unit']),
            ],
        ];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        if (!$hasPermission(CorePermissions::INVENTORY_READ)) {
            return [];
        }
        $q = mb_strtolower(trim($query));
        $rows = $this->rows(
            "SELECT v.id, v.sku, i.name FROM inv_variants v JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
              WHERE v.tenant_id = :tenant_id AND v.archived_at IS NULL AND i.archived_at IS NULL AND i.kind <> 'service'
                AND (:q = '' OR LOWER(i.name) LIKE :like_name OR LOWER(v.sku) LIKE :like_sku)
              ORDER BY i.name, v.sort_order, v.id LIMIT " . max(1, min($limit, 50)),
            [':tenant_id' => $tenantId, ':q' => $q, ':like_name' => '%' . $q . '%', ':like_sku' => '%' . $q . '%']
        );

        return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'label' => $r['name'] . ' · ' . $r['sku']], $rows);
    }

    /** A variant's options as "Large / Chocolate", or null for an item with one variant. */
    private static function options(mixed $stored): ?string
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }
        $parts = [];
        foreach ($decoded as $value) {
            if (is_array($value)) {
                $value = LocalizedText::decode($value)['en'] ?? reset($value);
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = trim((string) $value);
            }
        }

        return $parts !== [] ? implode(' / ', $parts) : null;
    }
}
