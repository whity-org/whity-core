<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Print;

/**
 * The templates inventory prints from (IV.20, WHIT-1234): a purchase order, a
 * goods received note and a sheet of item labels, each in English and in
 * Arabic. Unlike the guideline's starters, these read their record: every
 * figure comes from the order, the receipt or the item, and the line tables
 * repeat a row per line (`po.lines.*.item`).
 *
 * The wording follows TplDocPurchaseOrder and TplDocDeliveryNote. A label
 * that stands alone carries the other language small beside it ("Supplier ·
 * المورِّد", 6.4). The workspace can change any of them in the editor; they
 * print in its current document theme.
 */
final class InventoryStarters
{
    public const PURCHASE_ORDER = 'inventory.purchase-order';
    public const GOODS_RECEIVED = 'inventory.goods-received';
    public const ITEM_LABELS = 'inventory.item-labels';
    public const BATCH_LABELS = 'inventory.batch-labels';

    /** starter key without its language => [builder, record type]. */
    private const DOCUMENTS = [
        self::PURCHASE_ORDER => ['purchaseOrder', PurchaseOrderFieldProvider::RECORD_TYPE],
        self::GOODS_RECEIVED => ['goodsReceived', ReceiptFieldProvider::RECORD_TYPE],
        self::ITEM_LABELS => ['itemLabels', VariantLabelFieldProvider::RECORD_TYPE],
        self::BATCH_LABELS => ['batchLabels', LotLabelFieldProvider::RECORD_TYPE],
    ];

    private string $lang = 'en';

    private int $ids = 0;

    /** The starter key for a document in a language: `inventory.purchase-order.ar`. */
    public static function key(string $document, string $lang): string
    {
        return $lang === 'ar' ? $document . '.ar' : $document;
    }

    /** @return list<string> */
    public static function documents(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    /**
     * One starter, ready for `document_sources`.
     *
     * @return array{kind: string, title: string, lang: string, record_type: string, page: array<string, mixed>,
     *               own_fields: list<array<string, string>>, blocks: list<array<string, mixed>>}
     */
    public function build(string $document, string $lang): array
    {
        [$builder, $recordType] = self::DOCUMENTS[$document] ?? throw new \InvalidArgumentException('Unknown inventory document ' . $document);
        $this->lang = $lang === 'ar' ? 'ar' : 'en';
        $this->ids = 0;
        [$title, $blocks, $kind, $page] = $this->{$builder}();

        return [
            'kind' => $kind,
            'title' => $title,
            'lang' => $this->lang,
            'record_type' => $recordType,
            'page' => $page,
            'own_fields' => [],
            'blocks' => $blocks,
        ];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: string, 3: array<string, mixed>} */
    private function purchaseOrder(): array
    {
        $ar = $this->lang === 'ar';

        return [$this->t('Purchase order', 'أمر شراء'), [
            $this->facts([
                [['Order no.', 'رقم الأمر'], $this->f('po.number', 'Order number', 'رقم الأمر')],
                [['Supplier', 'المورِّد'], $this->f('po.supplier', 'Supplier', 'المورِّد')],
                [['Deliver to', 'التسليم إلى'], $this->f($ar ? 'po.deliver_to_ar' : 'po.deliver_to', 'Deliver to', 'التسليم إلى')],
                [['Deliver by', 'التسليم قبل'], $this->f($ar ? 'po.deliver_by_ar' : 'po.deliver_by', 'Deliver by', 'التسليم قبل')],
            ]),
            $this->facts([
                [['Order date', 'تاريخ الأمر'], $this->f($ar ? 'po.ordered_on_ar' : 'po.ordered_on', 'Order date', 'تاريخ الأمر')],
                [['Supplier tax no.', 'الرقم الضريبي للمورِّد'], $this->f('po.supplier_tax_number', "Supplier's tax number", 'الرقم الضريبي للمورِّد')],
                [['Delivery address', 'عنوان التسليم'], $this->f('po.deliver_to_address', 'Delivery address', 'عنوان التسليم')],
            ]),
            $this->table(
                [['Description', 'الوصف'], ['Qty', 'الكمية'], ['Unit price', 'سعر الوحدة'], ['Amount', 'المبلغ']],
                [[
                    $this->f($ar ? 'po.lines.*.item_ar' : 'po.lines.*.item', 'Item', 'الصنف'),
                    $this->f('po.lines.*.quantity', 'Quantity', 'الكمية'),
                    $this->f('po.lines.*.unit_price', 'Unit price', 'سعر الوحدة'),
                    $this->f('po.lines.*.amount', 'Amount', 'المبلغ'),
                ]],
                ['start', 'end', 'end', 'end'],
            ),
            $this->table(
                [['Subtotal', 'المجموع'], ['Sales tax', 'ضريبة المبيعات'], ['Total', 'الإجمالي']],
                [[
                    $this->f('po.subtotal', 'Subtotal', 'المجموع'),
                    $this->f('po.tax', 'Sales tax', 'ضريبة المبيعات'),
                    $this->f('po.total', 'Total', 'الإجمالي', true),
                ]],
                ['end', 'end', 'end'],
            ),
            $this->para($this->f($ar ? 'po.total_words_ar' : 'po.total_words_en', 'Total in words', 'الإجمالي كتابةً')),
            $this->labelled(['Notes', 'ملاحظات'], $this->f('po.notes', 'Notes', 'ملاحظات')),
            $this->labelled(['Terms', 'الشروط'], $this->txt($this->t(
                'Please quote this order number on your delivery note and invoice. Deliver only what is ordered; anything more is checked before it is accepted.',
                'يرجى ذكر رقم هذا الأمر على إشعار التسليم والفاتورة. يُسلَّم المطلوب فقط، وما يزيد عليه يُراجَع قبل قبوله.',
            ))),
            $this->signature([
                [['Approved by', 'وافق عليه'], [$this->f('po.approved_by', 'Approved by', 'وافق عليه')], [$this->f($ar ? 'po.approved_on_ar' : 'po.approved_on', 'Date approved', 'تاريخ الموافقة')]],
            ]),
            $this->para(
                $this->txt($this->t('Raised by ', 'أعدّه ')),
                $this->f('po.raised_by', 'Raised by', 'أعدّه'),
                $this->txt($this->t(' in Whity.', ' في Whity.')),
            ),
        ], 'template', self::a4()];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: string, 3: array<string, mixed>} */
    private function goodsReceived(): array
    {
        $ar = $this->lang === 'ar';

        return [$this->t('Goods received note', 'إشعار استلام بضاعة'), [
            $this->facts([
                [['Receipt no.', 'رقم الاستلام'], $this->f('grn.number', 'Receipt number', 'رقم الاستلام')],
                [['Purchase order', 'أمر الشراء'], $this->f('grn.order_number', 'Purchase order number', 'رقم أمر الشراء')],
                [['Supplier', 'المورِّد'], $this->f('grn.supplier', 'Supplier', 'المورِّد')],
                [['Received into', 'استُلم في'], $this->f($ar ? 'grn.place_ar' : 'grn.place', 'Received into', 'استُلم في')],
                [['Date received', 'تاريخ الاستلام'], $this->f($ar ? 'grn.received_on_ar' : 'grn.received_on', 'Date received', 'تاريخ الاستلام')],
            ]),
            $this->table(
                [['Item', 'الصنف'], ['SKU', 'الرمز'], ['Ordered', 'المطلوب'], ['Received', 'المستلَم'], ['Notes', 'ملاحظات']],
                [[
                    $this->f($ar ? 'grn.lines.*.item_ar' : 'grn.lines.*.item', 'Item', 'الصنف'),
                    $this->f('grn.lines.*.sku', 'SKU', 'الرمز'),
                    $this->f('grn.lines.*.ordered', 'Ordered', 'المطلوب'),
                    $this->f('grn.lines.*.received', 'Received', 'المستلَم'),
                    $this->f('grn.lines.*.note', 'Line note', 'ملاحظة السطر'),
                ]],
                ['start', 'start', 'end', 'end', 'start'],
            ),
            $this->labelled(['Note', 'ملاحظة'], $this->f('grn.note', 'Note', 'ملاحظة')),
            $this->para($this->txt($this->t(
                'Received in the condition noted above. Anything short or damaged is written on its line.',
                'استُلمت بالحالة المبيّنة أعلاه. يُكتب أي نقص أو تلف على سطره.',
            ))),
            $this->signature([
                [['Delivered by', 'سلّمه'], null, null],
                [['Received by', 'استلمه'], [$this->f('grn.received_by', 'Received by', 'استلمه')], [$this->f($ar ? 'grn.received_on_ar' : 'grn.received_on', 'Date received', 'تاريخ الاستلام')]],
            ]),
        ], 'template', self::a4()];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: string, 3: array<string, mixed>} */
    private function itemLabels(): array
    {
        $ar = $this->lang === 'ar';

        return [$this->t('Item labels', 'ملصقات الأصناف'), [
            $this->para($this->f($ar ? 'item.name_ar' : 'item.name', 'Name', 'الاسم', true), $this->txt(' '), $this->f('item.options', 'Variant', 'النوع')),
            // The SKU prints under the barcode; an item with no price leaves this line out.
            $this->para($this->f('item.price', 'Price', 'السعر', true)),
            ['id' => $this->id(), 'type' => 'barcode', 'symbology' => 'code128', 'value' => [$this->f('item.barcode', 'Barcode', 'الباركود')], 'showText' => true, 'heightMm' => 10],
        ], 'label', [
            'size' => 'A4',
            'marginMm' => 10,
            'letterhead' => false,
            // Avery L7160: 3 × 7 labels of 63.5 × 38.1 mm.
            'labels' => ['columns' => 3, 'rows' => 7, 'size' => ['widthMm' => 63.5, 'heightMm' => 38.1], 'gapMm' => 2.5, 'gapYMm' => 0],
        ]];
    }

    /**
     * Batch labels (WHIT-1235): the item, the batch code and its expiry, and
     * a barcode that scans back to the item and the batch.
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: string, 3: array<string, mixed>}
     */
    private function batchLabels(): array
    {
        $ar = $this->lang === 'ar';

        return [$this->t('Batch labels', 'ملصقات الدفعات'), [
            $this->para($this->f($ar ? 'item.name_ar' : 'item.name', 'Name', 'الاسم', true)),
            $this->para($this->txt($this->t('Batch ', 'الدفعة ')), $this->f('lot.code', 'Batch code', 'رمز الدفعة', true)),
            $this->para($this->txt($this->t('Use by ', 'تنتهي في ')), $this->f($ar ? 'lot.expires_on_ar' : 'lot.expires_on', 'Expires on', 'تاريخ الانتهاء')),
            ['id' => $this->id(), 'type' => 'barcode', 'symbology' => 'code128', 'value' => [$this->f('lot.barcode', 'Batch barcode', 'باركود الدفعة')], 'showText' => true, 'heightMm' => 10],
        ], 'label', [
            'size' => 'A4',
            'marginMm' => 10,
            'letterhead' => false,
            // Avery L7160, as item labels: 3 × 7 labels of 63.5 × 38.1 mm.
            'labels' => ['columns' => 3, 'rows' => 7, 'size' => ['widthMm' => 63.5, 'heightMm' => 38.1], 'gapMm' => 2.5, 'gapYMm' => 0],
        ]];
    }

    /** @return array<string, mixed> */
    private static function a4(): array
    {
        return ['size' => 'A4', 'marginMm' => 16, 'letterhead' => true];
    }

    /**
     * Labels in a header row and their values in one row under them.
     *
     * @param list<array{0: array{0: string, 1: string}, 1: array<string, mixed>}> $items
     * @return array<string, mixed>
     */
    private function facts(array $items): array
    {
        return $this->table(
            array_map(static fn (array $item): array => $item[0], $items),
            [array_map(static fn (array $item): array => $item[1], $items)],
            array_fill(0, count($items), 'start'),
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $header
     * @param list<list<array<string, mixed>>> $rows
     * @param list<string> $align
     * @return array<string, mixed>
     */
    private function table(array $header, array $rows, array $align): array
    {
        return [
            'id' => $this->id(),
            'type' => 'table',
            'headerRow' => true,
            'columns' => array_map(static fn (string $a): array => ['align' => $a], $align),
            'rows' => [
                array_map(fn (array $label): array => [$this->txt($this->dual($label))], $header),
                ...array_map(static fn (array $row): array => array_map(static fn (array $cell): array => [$cell], $row), $rows),
            ],
        ];
    }

    /**
     * A bold label with its small other-language label, then its value.
     *
     * @param array{0: string, 1: string} $label
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function labelled(array $label, array $value): array
    {
        return $this->para($this->txt($this->dual($label), true), ['type' => 'break'], $value);
    }

    /**
     * Signers as [role label, name inline or null to sign by hand, date inline or null].
     *
     * @param list<array{0: array{0: string, 1: string}, 1: list<array<string, mixed>>|null, 2: list<array<string, mixed>>|null}> $signers
     * @return array<string, mixed>
     */
    private function signature(array $signers): array
    {
        return [
            'id' => $this->id(),
            'type' => 'signature',
            'signers' => array_map(fn (array $s): array => ['name' => $s[1] ?? [], 'role' => [$this->txt($this->dual($s[0]))]], $signers),
            'date' => $signers[count($signers) - 1][2] ?? [],
            'stamp' => false,
        ];
    }

    /**
     * @param array<string, mixed> ...$content
     * @return array<string, mixed>
     */
    private function para(array ...$content): array
    {
        return ['id' => $this->id(), 'type' => 'paragraph', 'content' => $content];
    }

    /** @return array<string, mixed> */
    private function txt(string $text, bool $bold = false): array
    {
        return ['type' => 'text', 'text' => $text] + ($bold ? ['marks' => [['type' => 'bold']]] : []);
    }

    /** @return array<string, mixed> */
    private function f(string $path, string $en, string $ar, bool $bold = false): array
    {
        return ['type' => 'field', 'path' => $path, 'label' => $this->t($en, $ar)] + ($bold ? ['marks' => [['type' => 'bold']]] : []);
    }

    private function t(string $en, string $ar): string
    {
        return $this->lang === 'ar' ? $ar : $en;
    }

    /** @param array{0: string, 1: string} $label */
    private function dual(array $label): string
    {
        return $this->lang === 'ar' ? $label[1] . ' · ' . $label[0] : $label[0] . ' · ' . $label[1];
    }

    private function id(): string
    {
        return 'inv' . (++$this->ids);
    }
}
