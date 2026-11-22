<?php

declare(strict_types=1);

namespace Whity\Core\Sales\Print;

use Whity\Core\Inventory\Print\InventoryPrintFields;
use Whity\Core\Money\AmountInWords;
use Whity\Core\RBAC\CorePermissions;

/**
 * A quote as a record type for document templates (design section 34 §3.5,
 * QT.4, WHIT-1341): `{{quote.number}}`, `{{quote.customer}}`, the totals in the
 * quote's currency, its lines as a table row that repeats
 * (`quote.lines.*.item`), and `{{quote.link}}`, the public page its QR opens.
 *
 * Only the hash of a quote's link is kept, so the link is known only while the
 * quote is sent or given a new link: {@see withLink()} lends it to the copy
 * issued then, and every other copy prints without it.
 *
 * Reading one needs `quotes:read`.
 *
 * @i18n-keys documents
 *   fields.quote.record = Quote
 *   fields.quote.group = Quote
 *   fields.quote.lines = Quote lines
 *   fields.quote.number = Quote number
 *   fields.quote.revision = Revision
 *   fields.quote.customer = Customer
 *   fields.quote.customerAddress = Customer's address
 *   fields.quote.customerTaxNumber = Customer's tax number
 *   fields.quote.customerPhone = Customer's phone
 *   fields.quote.customerEmail = Customer's email
 *   fields.quote.issuedOn = Quote date
 *   fields.quote.issuedOnAr = Quote date (Arabic)
 *   fields.quote.validUntil = Valid until
 *   fields.quote.validUntilAr = Valid until (Arabic)
 *   fields.quote.currency = Currency
 *   fields.quote.subtotal = Subtotal
 *   fields.quote.tax = Sales tax
 *   fields.quote.total = Total
 *   fields.quote.totalWordsEn = Total in words (English)
 *   fields.quote.totalWordsAr = Total in words (Arabic)
 *   fields.quote.notes = Notes
 *   fields.quote.terms = Terms
 *   fields.quote.preparedBy = Prepared by
 *   fields.quote.link = Link to accept online
 *   fields.quote.lineItem = Item (a row each)
 *   fields.quote.lineItemAr = Item in Arabic (a row each)
 *   fields.quote.lineSku = SKU (a row each)
 *   fields.quote.lineQuantity = Quantity (a row each)
 *   fields.quote.lineUnitPrice = Unit price (a row each)
 *   fields.quote.lineDiscount = Discount (a row each)
 *   fields.quote.lineAmount = Amount (a row each)
 *   fields.quote.lineCount = Number of lines
 */
final class QuoteFieldProvider extends InventoryPrintFields
{
    public const RECORD_TYPE = 'sales.quote';

    /** @var array<string, string> "tenant:quote" => the public link, only while a copy is issued */
    private static array $links = [];

    private const HEAD = [
        'number' => ['fields.quote.number', 'Quote number', 'QUO-0318'],
        'revision' => ['fields.quote.revision', 'Revision', '1'],
        'customer' => ['fields.quote.customer', 'Customer', 'Example Clinic'],
        'customer_address' => ['fields.quote.customerAddress', "Customer's address", 'Rainbow Street 21, Amman'],
        'customer_tax_number' => ['fields.quote.customerTaxNumber', "Customer's tax number", '12345678'],
        'customer_phone' => ['fields.quote.customerPhone', "Customer's phone", '+96265550101'],
        'customer_email' => ['fields.quote.customerEmail', "Customer's email", 'orders@example.com'],
        'issued_on' => ['fields.quote.issuedOn', 'Quote date', '22 November 2026'],
        'issued_on_ar' => ['fields.quote.issuedOnAr', 'Quote date (Arabic)', '22 تشرين الثاني 2026'],
        'valid_until' => ['fields.quote.validUntil', 'Valid until', '22 December 2026'],
        'valid_until_ar' => ['fields.quote.validUntilAr', 'Valid until (Arabic)', '22 كانون الأول 2026'],
        'currency' => ['fields.quote.currency', 'Currency', 'USD'],
        'subtotal' => ['fields.quote.subtotal', 'Subtotal', '$260.00'],
        'tax' => ['fields.quote.tax', 'Sales tax', '$41.60'],
        'total' => ['fields.quote.total', 'Total', '$301.60'],
        'total_words_en' => ['fields.quote.totalWordsEn', 'Total in words (English)', 'Three hundred one US dollars and sixty cents'],
        'total_words_ar' => ['fields.quote.totalWordsAr', 'Total in words (Arabic)', 'ثلاثمئة وواحد دولارًا أمريكيًا وستون سنتًا'],
        'notes' => ['fields.quote.notes', 'Notes', 'Delivered on the morning of the event.'],
        'terms' => ['fields.quote.terms', 'Terms', 'Half on acceptance, the rest on delivery.'],
        'prepared_by' => ['fields.quote.preparedBy', 'Prepared by', 'Ahmad Khalil'],
        'link' => ['fields.quote.link', 'Link to accept online', 'https://app.whity.dev/q/…'],
    ];

    private const LINES = [
        'lines.*.item' => ['fields.quote.lineItem', 'Item (a row each)', 'Birthday cake, large'],
        'lines.*.item_ar' => ['fields.quote.lineItemAr', 'Item in Arabic (a row each)', 'كعكة عيد ميلاد، كبيرة'],
        'lines.*.sku' => ['fields.quote.lineSku', 'SKU (a row each)', 'CAKE-L'],
        'lines.*.quantity' => ['fields.quote.lineQuantity', 'Quantity (a row each)', '2 pc'],
        'lines.*.unit_price' => ['fields.quote.lineUnitPrice', 'Unit price (a row each)', '$45.00'],
        'lines.*.discount' => ['fields.quote.lineDiscount', 'Discount (a row each)', '10%'],
        'lines.*.amount' => ['fields.quote.lineAmount', 'Amount (a row each)', '$81.00'],
        'lines.count' => ['fields.quote.lineCount', 'Number of lines', '3'],
    ];

    /**
     * Runs $issue with the quote's public link known to this provider, so the
     * copy issued inside it carries the link and its QR. The link is forgotten
     * afterwards, whatever happens.
     *
     * @template T
     * @param callable(): T $issue
     * @return T
     */
    public static function withLink(int $tenantId, int $quoteId, string $link, callable $issue): mixed
    {
        $key = $tenantId . ':' . $quoteId;
        self::$links[$key] = $link;
        try {
            return $issue();
        } finally {
            unset(self::$links[$key]);
        }
    }

    public function recordType(): string
    {
        return self::RECORD_TYPE;
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.quote.record', 'Quote');
    }

    public function groups(int $tenantId): array
    {
        return [
            ['group' => $this->labels->get('fields.quote.group', 'Quote'), 'items' => $this->items('quote', self::HEAD)],
            ['group' => $this->labels->get('fields.quote.lines', 'Quote lines'), 'items' => $this->items('quote', self::LINES)],
        ];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        $id = $recordId !== null ? self::digits($recordId) : null;
        if ($id === null || !$hasPermission(CorePermissions::QUOTES_READ)) {
            return null;
        }
        $q = $this->one(
            'SELECT q.number, q.revision, q.issue_date, q.valid_until, q.currency, q.subtotal_minor, q.tax_minor, q.total_minor,
                    q.notes, q.terms, q.created_by_profile_id, q.created_at,
                    c.name AS customer, c.address, c.tax_number, c.phone, c.mobile, c.email
               FROM sales_quotes q
               JOIN contacts c ON c.tenant_id = q.tenant_id AND c.id = q.contact_id
              WHERE q.tenant_id = :tenant_id AND q.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        if ($q === null) {
            return null;
        }
        $currency = (string) $q['currency'];
        $total = (int) $q['total_minor'];
        $issued = $q['issue_date'] ?? $q['created_at'];
        $number = self::text($q['number']);
        $values = [
            'quote.number' => $number,
            'quote.revision' => (string) (int) $q['revision'],
            'quote.customer' => self::text($q['customer']),
            'quote.customer_address' => self::text($q['address']),
            'quote.customer_tax_number' => self::text($q['tax_number']),
            'quote.customer_phone' => self::text($q['phone']) ?? self::text($q['mobile']),
            'quote.customer_email' => self::text($q['email']),
            'quote.issued_on' => self::date($issued),
            'quote.issued_on_ar' => self::date($issued, 'ar'),
            'quote.valid_until' => self::date($q['valid_until']),
            'quote.valid_until_ar' => self::date($q['valid_until'], 'ar'),
            'quote.currency' => $currency,
            'quote.subtotal' => self::money((int) $q['subtotal_minor'], $currency),
            'quote.tax' => self::money((int) $q['tax_minor'], $currency),
            'quote.total' => self::money($total, $currency),
            'quote.total_words_en' => AmountInWords::write($total, $currency, 'en'),
            'quote.total_words_ar' => AmountInWords::write($total, $currency, 'ar'),
            'quote.notes' => self::text($q['notes']),
            'quote.terms' => self::text($q['terms']),
            'quote.prepared_by' => $this->personName($q['created_by_profile_id']),
            'quote.link' => self::$links[$tenantId . ':' . $id] ?? null,
        ];

        $lines = $this->rows(
            'SELECT l.description, l.quantity, l.quantity_decimals, l.price_minor, l.discount_bp, l.line_total_minor,
                    v.sku, i.name, i.names, u.code AS unit
               FROM sales_quote_lines l
               LEFT JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
               LEFT JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               LEFT JOIN inv_units u ON u.tenant_id = l.tenant_id AND u.id = l.unit_id
              WHERE l.tenant_id = :tenant_id AND l.quote_id = :quote_id
              ORDER BY l.position, l.id',
            [':tenant_id' => $tenantId, ':quote_id' => $id]
        );
        foreach ($lines as $i => $l) {
            $n = 'quote.lines.' . ($i + 1) . '.';
            // A line's own words win. An item line whose words are only the
            // item's name (as a quote fills them) prints the item's name in each language.
            $own = self::text($l['description']);
            if ($l['name'] !== null) {
                [$en, $ar] = self::names($l['names'], (string) $l['name']);
                $named = $own === null || $own === $en || $own === $ar;
                $values[$n . 'item'] = $named ? self::text($en) : $own;
                $values[$n . 'item_ar'] = $named ? self::text($ar) : $own;
            } else {
                $values[$n . 'item'] = $own;
                $values[$n . 'item_ar'] = $own;
            }
            $values[$n . 'sku'] = self::text($l['sku']);
            $values[$n . 'quantity'] = self::quantity((int) $l['quantity'], (int) $l['quantity_decimals'], (string) ($l['unit'] ?? ''));
            $values[$n . 'unit_price'] = self::money((int) $l['price_minor'], $currency);
            $values[$n . 'discount'] = (int) $l['discount_bp'] > 0 ? self::percent((int) $l['discount_bp']) : null;
            $values[$n . 'amount'] = self::money((int) $l['line_total_minor'], $currency);
        }
        $values['quote.lines.count'] = (string) count($lines);

        return ['label' => ($number ?? 'Draft quote') . ' · ' . (string) $q['customer'], 'values' => $values];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        if (!$hasPermission(CorePermissions::QUOTES_READ)) {
            return [];
        }
        $q = trim($query);
        $rows = $this->rows(
            "SELECT q.id, q.number, q.revision, c.name FROM sales_quotes q JOIN contacts c ON c.tenant_id = q.tenant_id AND c.id = q.contact_id
              WHERE q.tenant_id = :tenant_id
                AND (:q = '' OR LOWER(COALESCE(q.number, '')) LIKE :like_number OR LOWER(c.name) LIKE :like_name)
              ORDER BY q.id DESC LIMIT " . max(1, min($limit, 50)),
            [':tenant_id' => $tenantId, ':q' => $q, ':like_number' => '%' . mb_strtolower($q) . '%', ':like_name' => '%' . mb_strtolower($q) . '%']
        );

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'label' => ($r['number'] !== null ? $r['number'] . ((int) $r['revision'] > 1 ? ' rev ' . (int) $r['revision'] : '') : 'Draft') . ' · ' . $r['name'],
        ], $rows);
    }

    /** "10%", "12.5%": basis points as a percentage, Western digits. */
    private static function percent(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.') . '%';
    }
}
