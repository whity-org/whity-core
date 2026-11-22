<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Print;

use Whity\Core\Db\DbBool;
use Whity\Core\RBAC\CorePermissions;

/**
 * A batch or serial number, as a record type for label sheets (WHIT-1235,
 * design 31 §3.5): the item's name, SKU, the batch code and its dates, and a
 * barcode that scans back to the item and the batch.
 *
 * The barcode carries the batch in GS1 form, `(01)GTIN(17)expiry(10)batch`
 * (`(21)` for a serial number), when the item has a GTIN or EAN barcode, so
 * scanning the label fills the item, the batch and its expiry. An item with
 * no such barcode prints its SKU, which finds the item.
 *
 * Reading one needs `inventory:read`.
 *
 * @i18n-keys documents
 *   fields.lot.record = Batch
 *   fields.lot.group = Batch
 *   fields.lot.code = Batch code
 *   fields.lot.expiresOn = Expires on
 *   fields.lot.madeOn = Made on
 *   fields.lot.expiresOnAr = Expires on, in Arabic
 *   fields.lot.madeOnAr = Made on, in Arabic
 *   fields.lot.barcode = Batch barcode
 */
final class LotLabelFieldProvider extends InventoryPrintFields
{
    public const RECORD_TYPE = 'inventory.lot';

    private const ITEM_FIELDS = [
        'name' => ['fields.item.name', 'Name', 'Fresh milk'],
        'name_ar' => ['fields.item.nameAr', 'Name in Arabic', 'حليب طازج'],
        'sku' => ['fields.item.sku', 'SKU', 'MLK-1L'],
    ];

    private const LOT_FIELDS = [
        'code' => ['fields.lot.code', 'Batch code', 'B-0412'],
        'expires_on' => ['fields.lot.expiresOn', 'Expires on', '31 March 2027'],
        'expires_on_ar' => ['fields.lot.expiresOnAr', 'Expires on, in Arabic', '31 آذار 2027'],
        'made_on' => ['fields.lot.madeOn', 'Made on', '1 November 2026'],
        'made_on_ar' => ['fields.lot.madeOnAr', 'Made on, in Arabic', '1 تشرين الثاني 2026'],
        'barcode' => ['fields.lot.barcode', 'Batch barcode', '(01)06251234567890(17)270331(10)B-0412'],
    ];

    public function recordType(): string
    {
        return self::RECORD_TYPE;
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.lot.record', 'Batch');
    }

    public function groups(int $tenantId): array
    {
        return [
            ['group' => $this->labels->get('fields.lot.group', 'Batch'), 'items' => $this->items('lot', self::LOT_FIELDS)],
            ['group' => $this->labels->get('fields.item.group', 'Item'), 'items' => $this->items('item', self::ITEM_FIELDS)],
        ];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        $id = $recordId !== null ? self::digits($recordId) : null;
        if ($id === null || !$hasPermission(CorePermissions::INVENTORY_READ)) {
            return null;
        }
        $lot = $this->one(
            'SELECT t.code, t.is_serial, t.expires_on, t.made_on, v.sku, i.name, i.names,
                    (SELECT b.code FROM inv_barcodes b WHERE b.tenant_id = v.tenant_id AND b.variant_id = v.id AND b.unit_id IS NULL ORDER BY b.id LIMIT 1) AS barcode
               FROM inv_lots t
               JOIN inv_variants v ON v.tenant_id = t.tenant_id AND v.id = t.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
              WHERE t.tenant_id = :tenant_id AND t.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        if ($lot === null) {
            return null;
        }
        [$en, $ar] = self::names($lot['names'], (string) $lot['name']);
        $code = (string) $lot['code'];
        $expires = $lot['expires_on'] === null ? null : substr((string) $lot['expires_on'], 0, 10);
        $made = $lot['made_on'] === null ? null : substr((string) $lot['made_on'], 0, 10);
        $sku = (string) $lot['sku'];

        return [
            'label' => $en . ' · ' . $code,
            'values' => [
                'item.name' => $en,
                'item.name_ar' => $ar,
                'item.sku' => self::text($sku),
                'lot.code' => self::text($code),
                'lot.expires_on' => self::date($expires),
                'lot.expires_on_ar' => self::date($expires, 'ar'),
                'lot.made_on' => self::date($made),
                'lot.made_on_ar' => self::date($made, 'ar'),
                'lot.barcode' => self::gs1(is_string($lot['barcode']) ? $lot['barcode'] : null, $code, DbBool::of($lot['is_serial']), $expires) ?? $sku,
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
            "SELECT t.id, t.code, i.name FROM inv_lots t
               JOIN inv_variants v ON v.tenant_id = t.tenant_id AND v.id = t.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
              WHERE t.tenant_id = :tenant_id AND (:q = '' OR LOWER(i.name) LIKE :like_name OR LOWER(t.code) LIKE :like_code)
              ORDER BY t.id DESC LIMIT " . max(1, min($limit, 50)),
            [':tenant_id' => $tenantId, ':q' => $q, ':like_name' => '%' . $q . '%', ':like_code' => '%' . $q . '%']
        );

        return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'label' => $r['name'] . ' · ' . $r['code']], $rows);
    }

    /** The batch in GS1 form, when the item has a GTIN or EAN to start it with. */
    public static function gs1(?string $barcode, string $code, bool $serial, ?string $expires): ?string
    {
        if ($barcode === null || preg_match('/^\d{8}$|^\d{12,14}$/', $barcode) !== 1 || preg_match('/^[\x21-\x7E]{1,20}$/', $code) !== 1) {
            return null;
        }
        $out = '(01)' . str_pad($barcode, 14, '0', STR_PAD_LEFT);
        if ($expires !== null) {
            $out .= '(17)' . substr(str_replace('-', '', $expires), 2);
        }

        return $out . ($serial ? '(21)' : '(10)') . $code;
    }
}
