<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Print;

use Whity\Core\Money\AmountInWords;
use Whity\Core\RBAC\CorePermissions;

/**
 * A purchase order as a record type for document templates (IV.20, WHIT-1234):
 * `{{po.number}}`, `{{po.supplier}}`, the totals in the order's currency, and
 * its lines as a table row that repeats (`po.lines.*.item`, `po.lines.count`).
 *
 * Reading one needs `purchases:read`.
 *
 * @i18n-keys documents
 *   fields.po.record = Purchase order
 *   fields.po.group = Purchase order
 *   fields.po.lines = Purchase order lines
 *   fields.po.number = Order number
 *   fields.po.supplier = Supplier
 *   fields.po.supplierAddress = Supplier's address
 *   fields.po.supplierTaxNumber = Supplier's tax number
 *   fields.po.supplierPhone = Supplier's phone
 *   fields.po.supplierEmail = Supplier's email
 *   fields.po.deliverTo = Deliver to
 *   fields.po.deliverToAr = Deliver to (Arabic)
 *   fields.po.deliverToAddress = Delivery address
 *   fields.po.deliverBy = Deliver by
 *   fields.po.deliverByAr = Deliver by (Arabic)
 *   fields.po.orderedOn = Order date
 *   fields.po.orderedOnAr = Order date (Arabic)
 *   fields.po.currency = Currency
 *   fields.po.subtotal = Subtotal
 *   fields.po.tax = Sales tax
 *   fields.po.total = Total
 *   fields.po.totalWordsEn = Total in words (English)
 *   fields.po.totalWordsAr = Total in words (Arabic)
 *   fields.po.notes = Notes
 *   fields.po.reference = Supplier's reference
 *   fields.po.raisedBy = Raised by
 *   fields.po.approvedBy = Approved by
 *   fields.po.approvedOn = Date approved
 *   fields.po.approvedOnAr = Date approved (Arabic)
 *   fields.po.lineItem = Item (a row each)
 *   fields.po.lineItemAr = Item in Arabic (a row each)
 *   fields.po.lineSku = SKU (a row each)
 *   fields.po.lineQuantity = Quantity (a row each)
 *   fields.po.lineUnitPrice = Unit price (a row each)
 *   fields.po.lineAmount = Amount (a row each)
 *   fields.po.lineNote = Line note (a row each)
 *   fields.po.lineCount = Number of lines
 */
final class PurchaseOrderFieldProvider extends InventoryPrintFields
{
    public const RECORD_TYPE = 'inventory.purchase_order';

    private const HEAD = [
        'number' => ['fields.po.number', 'Order number', 'PO-0057'],
        'supplier' => ['fields.po.supplier', 'Supplier', 'Example Mills'],
        'supplier_address' => ['fields.po.supplierAddress', "Supplier's address", 'Sahab Industrial Estate, Amman'],
        'supplier_tax_number' => ['fields.po.supplierTaxNumber', "Supplier's tax number", '12345678'],
        'supplier_phone' => ['fields.po.supplierPhone', "Supplier's phone", '+96265550101'],
        'supplier_email' => ['fields.po.supplierEmail', "Supplier's email", 'orders@example.com'],
        'deliver_to' => ['fields.po.deliverTo', 'Deliver to', 'Main kitchen'],
        'deliver_to_ar' => ['fields.po.deliverToAr', 'Deliver to (Arabic)', 'المطبخ الرئيسي'],
        'deliver_to_address' => ['fields.po.deliverToAddress', 'Delivery address', 'Rainbow Street 21, Amman'],
        'deliver_by' => ['fields.po.deliverBy', 'Deliver by', '27 November 2026'],
        'deliver_by_ar' => ['fields.po.deliverByAr', 'Deliver by (Arabic)', '27 تشرين الثاني 2026'],
        'ordered_on' => ['fields.po.orderedOn', 'Order date', '19 November 2026'],
        'ordered_on_ar' => ['fields.po.orderedOnAr', 'Order date (Arabic)', '19 تشرين الثاني 2026'],
        'currency' => ['fields.po.currency', 'Currency', 'USD'],
        'subtotal' => ['fields.po.subtotal', 'Subtotal', '$260.00'],
        'tax' => ['fields.po.tax', 'Sales tax', '$41.60'],
        'total' => ['fields.po.total', 'Total', '$301.60'],
        'total_words_en' => ['fields.po.totalWordsEn', 'Total in words (English)', 'Three hundred one US dollars and sixty cents'],
        'total_words_ar' => ['fields.po.totalWordsAr', 'Total in words (Arabic)', 'ثلاثمئة وواحد دولارًا أمريكيًا وستون سنتًا'],
        'notes' => ['fields.po.notes', 'Notes', 'Deliver before 9.'],
        'reference' => ['fields.po.reference', "Supplier's reference", 'Q-2026-118'],
        'raised_by' => ['fields.po.raisedBy', 'Raised by', 'Ahmad Khalil'],
        'approved_by' => ['fields.po.approvedBy', 'Approved by', 'Awss Haddad'],
        'approved_on' => ['fields.po.approvedOn', 'Date approved', '20 November 2026'],
        'approved_on_ar' => ['fields.po.approvedOnAr', 'Date approved (Arabic)', '20 تشرين الثاني 2026'],
    ];

    private const LINES = [
        'lines.*.item' => ['fields.po.lineItem', 'Item (a row each)', 'Flour, 25 kg bag'],
        'lines.*.item_ar' => ['fields.po.lineItemAr', 'Item in Arabic (a row each)', 'طحين، كيس 25 كغ'],
        'lines.*.sku' => ['fields.po.lineSku', 'SKU (a row each)', 'FLR-25'],
        'lines.*.quantity' => ['fields.po.lineQuantity', 'Quantity (a row each)', '10 bag'],
        'lines.*.unit_price' => ['fields.po.lineUnitPrice', 'Unit price (a row each)', '$18.50'],
        'lines.*.amount' => ['fields.po.lineAmount', 'Amount (a row each)', '$185.00'],
        'lines.*.note' => ['fields.po.lineNote', 'Line note (a row each)', ''],
        'lines.count' => ['fields.po.lineCount', 'Number of lines', '4'],
    ];

    public function recordType(): string
    {
        return self::RECORD_TYPE;
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.po.record', 'Purchase order');
    }

    public function groups(int $tenantId): array
    {
        return [
            ['group' => $this->labels->get('fields.po.group', 'Purchase order'), 'items' => $this->items('po', self::HEAD)],
            ['group' => $this->labels->get('fields.po.lines', 'Purchase order lines'), 'items' => $this->items('po', self::LINES)],
        ];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        $id = $recordId !== null ? self::digits($recordId) : null;
        if ($id === null || !$hasPermission(CorePermissions::PURCHASES_READ)) {
            return null;
        }
        $o = $this->one(
            "SELECT o.number, o.expected_on, o.currency, o.subtotal_minor, o.tax_minor, o.total_minor, o.notes, o.external_ref,
                    o.created_by_profile_id, o.approved_by_profile_id, o.approved_at, o.created_at,
                    c.name AS supplier, c.address AS supplier_address, c.tax_number, c.phone, c.mobile, c.email,
                    l.name AS place, l.names AS place_names, l.address AS place_address
               FROM inv_orders o
               JOIN contacts c ON c.tenant_id = o.tenant_id AND c.id = o.contact_id
               JOIN inv_locations l ON l.tenant_id = o.tenant_id AND l.id = o.location_id
              WHERE o.tenant_id = :tenant_id AND o.id = :id AND o.kind = 'purchase'",
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        if ($o === null) {
            return null;
        }
        $currency = (string) $o['currency'];
        [$placeEn, $placeAr] = self::names($o['place_names'], (string) $o['place']);
        $total = (int) $o['total_minor'];
        $values = [
            'po.number' => (string) $o['number'],
            'po.supplier' => self::text($o['supplier']),
            'po.supplier_address' => self::text($o['supplier_address']),
            'po.supplier_tax_number' => self::text($o['tax_number']),
            'po.supplier_phone' => self::text($o['phone']) ?? self::text($o['mobile']),
            'po.supplier_email' => self::text($o['email']),
            'po.deliver_to' => $placeEn,
            'po.deliver_to_ar' => $placeAr,
            'po.deliver_to_address' => self::text($o['place_address']),
            'po.deliver_by' => self::date($o['expected_on']),
            'po.deliver_by_ar' => self::date($o['expected_on'], 'ar'),
            'po.ordered_on' => self::date($o['created_at']),
            'po.ordered_on_ar' => self::date($o['created_at'], 'ar'),
            'po.currency' => $currency,
            'po.subtotal' => self::money((int) $o['subtotal_minor'], $currency),
            'po.tax' => self::money((int) $o['tax_minor'], $currency),
            'po.total' => self::money($total, $currency),
            'po.total_words_en' => AmountInWords::write($total, $currency, 'en'),
            'po.total_words_ar' => AmountInWords::write($total, $currency, 'ar'),
            'po.notes' => self::text($o['notes']),
            'po.reference' => self::text($o['external_ref']),
            'po.raised_by' => $this->personName($o['created_by_profile_id']),
            'po.approved_by' => $this->personName($o['approved_by_profile_id']),
            'po.approved_on' => self::date($o['approved_at']),
            'po.approved_on_ar' => self::date($o['approved_at'], 'ar'),
        ];

        $lines = $this->rows(
            'SELECT l.entered_quantity, l.price_minor, l.line_total_minor, l.note, v.sku, i.name, i.names, u.code AS unit, u.decimals
               FROM inv_order_lines l
               JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = l.tenant_id AND u.id = l.unit_id
              WHERE l.tenant_id = :tenant_id AND l.order_id = :order_id
              ORDER BY l.sort_order, l.id',
            [':tenant_id' => $tenantId, ':order_id' => $id]
        );
        foreach ($lines as $i => $l) {
            $n = 'po.lines.' . ($i + 1) . '.';
            [$en, $ar] = self::names($l['names'], (string) $l['name']);
            $values[$n . 'item'] = $en;
            $values[$n . 'item_ar'] = $ar;
            $values[$n . 'sku'] = self::text($l['sku']);
            $values[$n . 'quantity'] = self::quantity((int) $l['entered_quantity'], (int) $l['decimals'], (string) $l['unit']);
            $values[$n . 'unit_price'] = self::money((int) $l['price_minor'], $currency);
            $values[$n . 'amount'] = self::money((int) $l['line_total_minor'], $currency);
            $values[$n . 'note'] = self::text($l['note']);
        }
        $values['po.lines.count'] = (string) count($lines);

        return ['label' => (string) $o['number'] . ' · ' . (string) $o['supplier'], 'values' => $values];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        if (!$hasPermission(CorePermissions::PURCHASES_READ)) {
            return [];
        }
        $q = trim($query);
        $rows = $this->rows(
            "SELECT o.id, o.number, c.name FROM inv_orders o JOIN contacts c ON c.tenant_id = o.tenant_id AND c.id = o.contact_id
              WHERE o.tenant_id = :tenant_id AND o.kind = 'purchase'
                AND (:q = '' OR LOWER(o.number) LIKE :like_number OR LOWER(c.name) LIKE :like_name)
              ORDER BY o.id DESC LIMIT " . max(1, min($limit, 50)),
            [':tenant_id' => $tenantId, ':q' => $q, ':like_number' => '%' . mb_strtolower($q) . '%', ':like_name' => '%' . mb_strtolower($q) . '%']
        );

        return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'label' => $r['number'] . ' · ' . $r['name']], $rows);
    }
}
