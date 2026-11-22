<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Print;

use Whity\Core\RBAC\CorePermissions;

/**
 * A goods received note as a record type for document templates (IV.20,
 * WHIT-1234): one receipt (GRN-0001) against its purchase order, what was
 * ordered and what came on each line, with no prices (those are the order's).
 *
 * Reading one needs `purchases:read`, as the order it belongs to does.
 *
 * @i18n-keys documents
 *   fields.grn.record = Goods received note
 *   fields.grn.group = Goods received note
 *   fields.grn.lines = Goods received lines
 *   fields.grn.number = Receipt number
 *   fields.grn.orderNumber = Purchase order number
 *   fields.grn.supplier = Supplier
 *   fields.grn.place = Received into
 *   fields.grn.placeAr = Received into (Arabic)
 *   fields.grn.receivedOn = Date received
 *   fields.grn.receivedOnAr = Date received (Arabic)
 *   fields.grn.receivedBy = Received by
 *   fields.grn.note = Note
 *   fields.grn.lineItem = Item (a row each)
 *   fields.grn.lineItemAr = Item in Arabic (a row each)
 *   fields.grn.lineSku = SKU (a row each)
 *   fields.grn.lineOrdered = Ordered (a row each)
 *   fields.grn.lineReceived = Received (a row each)
 *   fields.grn.lineNote = Line note (a row each)
 *   fields.grn.lineCount = Number of lines
 */
final class ReceiptFieldProvider extends InventoryPrintFields
{
    public const RECORD_TYPE = 'inventory.receipt';

    private const HEAD = [
        'number' => ['fields.grn.number', 'Receipt number', 'GRN-0012'],
        'order_number' => ['fields.grn.orderNumber', 'Purchase order number', 'PO-0057'],
        'supplier' => ['fields.grn.supplier', 'Supplier', 'Example Mills'],
        'place' => ['fields.grn.place', 'Received into', 'Main kitchen'],
        'place_ar' => ['fields.grn.placeAr', 'Received into (Arabic)', 'المطبخ الرئيسي'],
        'received_on' => ['fields.grn.receivedOn', 'Date received', '26 November 2026'],
        'received_on_ar' => ['fields.grn.receivedOnAr', 'Date received (Arabic)', '26 تشرين الثاني 2026'],
        'received_by' => ['fields.grn.receivedBy', 'Received by', 'Ibrahim Mansour'],
        'note' => ['fields.grn.note', 'Note', 'Two bags torn, kept aside.'],
    ];

    private const LINES = [
        'lines.*.item' => ['fields.grn.lineItem', 'Item (a row each)', 'Flour, 25 kg bag'],
        'lines.*.item_ar' => ['fields.grn.lineItemAr', 'Item in Arabic (a row each)', 'طحين، كيس 25 كغ'],
        'lines.*.sku' => ['fields.grn.lineSku', 'SKU (a row each)', 'FLR-25'],
        'lines.*.ordered' => ['fields.grn.lineOrdered', 'Ordered (a row each)', '10 bag'],
        'lines.*.received' => ['fields.grn.lineReceived', 'Received (a row each)', '8 bag'],
        'lines.*.note' => ['fields.grn.lineNote', 'Line note (a row each)', ''],
        'lines.count' => ['fields.grn.lineCount', 'Number of lines', '3'],
    ];

    public function recordType(): string
    {
        return self::RECORD_TYPE;
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.grn.record', 'Goods received note');
    }

    public function groups(int $tenantId): array
    {
        return [
            ['group' => $this->labels->get('fields.grn.group', 'Goods received note'), 'items' => $this->items('grn', self::HEAD)],
            ['group' => $this->labels->get('fields.grn.lines', 'Goods received lines'), 'items' => $this->items('grn', self::LINES)],
        ];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        $id = $recordId !== null ? self::digits($recordId) : null;
        if ($id === null || !$hasPermission(CorePermissions::PURCHASES_READ)) {
            return null;
        }
        $r = $this->one(
            "SELECT op.number, op.note, op.occurred_at, op.done_at, op.created_at, op.created_by_profile_id, op.done_by_profile_id,
                    o.number AS order_number, c.name AS supplier, l.name AS place, l.names AS place_names
               FROM inv_operations op
               JOIN inv_orders o ON o.tenant_id = op.tenant_id AND o.id = op.order_id
               JOIN contacts c ON c.tenant_id = o.tenant_id AND c.id = o.contact_id
               JOIN inv_locations l ON l.tenant_id = op.tenant_id AND l.id = op.to_location_id
              WHERE op.tenant_id = :tenant_id AND op.id = :id AND op.kind = 'receipt'",
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        if ($r === null) {
            return null;
        }
        [$placeEn, $placeAr] = self::names($r['place_names'], (string) $r['place']);
        $values = [
            'grn.number' => (string) $r['number'],
            'grn.order_number' => (string) $r['order_number'],
            'grn.supplier' => self::text($r['supplier']),
            'grn.place' => $placeEn,
            'grn.place_ar' => $placeAr,
            'grn.received_on' => self::date($r['occurred_at'] ?? $r['done_at'] ?? $r['created_at']),
            'grn.received_on_ar' => self::date($r['occurred_at'] ?? $r['done_at'] ?? $r['created_at'], 'ar'),
            'grn.received_by' => $this->personName($r['done_by_profile_id'] ?? $r['created_by_profile_id']),
            'grn.note' => self::text($r['note']),
        ];

        $lines = $this->rows(
            'SELECT l.quantity, l.entered_quantity, l.note, v.sku, i.name, i.names, bu.code AS base_unit, bu.decimals AS base_decimals,
                    eu.code AS entered_unit, eu.decimals AS entered_decimals,
                    ol.entered_quantity AS ordered_quantity, ou.code AS ordered_unit, ou.decimals AS ordered_decimals
               FROM inv_operation_lines l
               JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units bu ON bu.tenant_id = i.tenant_id AND bu.id = i.base_unit_id
               LEFT JOIN inv_units eu ON eu.tenant_id = l.tenant_id AND eu.id = l.entered_unit_id
               LEFT JOIN inv_order_lines ol ON ol.tenant_id = l.tenant_id AND ol.id = l.order_line_id
               LEFT JOIN inv_units ou ON ou.tenant_id = ol.tenant_id AND ou.id = ol.unit_id
              WHERE l.tenant_id = :tenant_id AND l.operation_id = :op
              ORDER BY l.sort_order, l.id',
            [':tenant_id' => $tenantId, ':op' => $id]
        );
        foreach ($lines as $i => $l) {
            $n = 'grn.lines.' . ($i + 1) . '.';
            [$en, $ar] = self::names($l['names'], (string) $l['name']);
            $values[$n . 'item'] = $en;
            $values[$n . 'item_ar'] = $ar;
            $values[$n . 'sku'] = self::text($l['sku']);
            $values[$n . 'ordered'] = $l['ordered_quantity'] !== null
                ? self::quantity((int) $l['ordered_quantity'], (int) $l['ordered_decimals'], (string) $l['ordered_unit'])
                : null;
            // As it was counted at the door (8 bags), else in the item's own unit.
            $values[$n . 'received'] = $l['entered_quantity'] !== null && $l['entered_unit'] !== null
                ? self::quantity((int) $l['entered_quantity'], (int) $l['entered_decimals'], (string) $l['entered_unit'])
                : self::quantity((int) $l['quantity'], (int) $l['base_decimals'], (string) $l['base_unit']);
            $values[$n . 'note'] = self::text($l['note']);
        }
        $values['grn.lines.count'] = (string) count($lines);

        return ['label' => (string) $r['number'] . ' · ' . (string) $r['order_number'], 'values' => $values];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        if (!$hasPermission(CorePermissions::PURCHASES_READ)) {
            return [];
        }
        $q = mb_strtolower(trim($query));
        $rows = $this->rows(
            "SELECT op.id, op.number, o.number AS order_number FROM inv_operations op
               JOIN inv_orders o ON o.tenant_id = op.tenant_id AND o.id = op.order_id
              WHERE op.tenant_id = :tenant_id AND op.kind = 'receipt'
                AND (:q = '' OR LOWER(op.number) LIKE :like_number OR LOWER(o.number) LIKE :like_order)
              ORDER BY op.id DESC LIMIT " . max(1, min($limit, 50)),
            [':tenant_id' => $tenantId, ':q' => $q, ':like_number' => '%' . $q . '%', ':like_order' => '%' . $q . '%']
        );

        return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'label' => $r['number'] . ' · ' . $r['order_number']], $rows);
    }
}
