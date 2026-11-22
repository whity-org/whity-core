<?php

declare(strict_types=1);

namespace Whity\Core\Sales\Print;

/**
 * The template a quote prints from (design section 34 §3.5, `TplDocQuote`,
 * WHIT-1341), in English and in Arabic. Unlike the guideline's `editor.quote`
 * starter, this one reads its record: every figure comes from the quote, the
 * line table repeats a row per line (`quote.lines.*.item`), and the QR opens
 * the quote's public page to accept or decline it (QT.5). A copy issued
 * without its link leaves the QR out.
 *
 * A label that stands alone carries the other language small beside it
 * ("Customer · العميل", 6.4). The workspace can change it in the editor; it
 * prints in the workspace's current document theme.
 */
final class QuoteStarter
{
    public const KEY = 'sales.quote';

    private string $lang = 'en';

    private int $ids = 0;

    /** The starter key in a language: `sales.quote.ar`. */
    public static function key(string $lang): string
    {
        return $lang === 'ar' ? self::KEY . '.ar' : self::KEY;
    }

    /**
     * @return array{kind: string, title: string, lang: string, record_type: string, page: array<string, mixed>,
     *               own_fields: list<array<string, string>>, blocks: list<array<string, mixed>>}
     */
    public function build(string $lang): array
    {
        $this->lang = $lang === 'ar' ? 'ar' : 'en';
        $this->ids = 0;
        $ar = $this->lang === 'ar';

        $blocks = [
            $this->facts([
                [['Quote no.', 'رقم العرض'], $this->f('quote.number', 'Quote number', 'رقم العرض')],
                [['Prepared for', 'مقدَّم إلى'], $this->f('quote.customer', 'Customer', 'العميل')],
                [['Date', 'التاريخ'], $this->f($ar ? 'quote.issued_on_ar' : 'quote.issued_on', 'Quote date', 'تاريخ العرض')],
                [['Valid until', 'صالح حتى'], $this->f($ar ? 'quote.valid_until_ar' : 'quote.valid_until', 'Valid until', 'صالح حتى')],
            ]),
            $this->facts([
                [['Customer tax no.', 'الرقم الضريبي للعميل'], $this->f('quote.customer_tax_number', "Customer's tax number", 'الرقم الضريبي للعميل')],
                [['Address', 'العنوان'], $this->f('quote.customer_address', "Customer's address", 'عنوان العميل')],
                [['Revision', 'المراجعة'], $this->f('quote.revision', 'Revision', 'المراجعة')],
            ]),
            $this->table(
                [['Description', 'الوصف'], ['Qty', 'الكمية'], ['Unit price', 'سعر الوحدة'], ['Discount', 'الخصم'], ['Amount', 'المبلغ']],
                [[
                    $this->f($ar ? 'quote.lines.*.item_ar' : 'quote.lines.*.item', 'Item', 'الصنف'),
                    $this->f('quote.lines.*.quantity', 'Quantity', 'الكمية'),
                    $this->f('quote.lines.*.unit_price', 'Unit price', 'سعر الوحدة'),
                    $this->f('quote.lines.*.discount', 'Discount', 'الخصم'),
                    $this->f('quote.lines.*.amount', 'Amount', 'المبلغ'),
                ]],
                ['start', 'end', 'end', 'end', 'end'],
            ),
            $this->table(
                [['Subtotal', 'المجموع'], ['Sales tax', 'ضريبة المبيعات'], ['Total', 'الإجمالي']],
                [[
                    $this->f('quote.subtotal', 'Subtotal', 'المجموع'),
                    $this->f('quote.tax', 'Sales tax', 'ضريبة المبيعات'),
                    $this->f('quote.total', 'Total', 'الإجمالي', true),
                ]],
                ['end', 'end', 'end'],
            ),
            $this->para($this->f($ar ? 'quote.total_words_ar' : 'quote.total_words_en', 'Total in words', 'الإجمالي كتابةً')),
            $this->labelled(['Notes', 'ملاحظات'], $this->f('quote.notes', 'Notes', 'ملاحظات')),
            $this->labelled(['Terms', 'الشروط'], $this->f('quote.terms', 'Terms', 'الشروط')),
            [
                'id' => $this->id(),
                'type' => 'qr',
                'value' => [$this->f('quote.link', 'Link to accept online', 'رابط القبول عبر الإنترنت')],
                'caption' => [$this->txt($this->t('Scan to accept or decline online', 'امسح الرمز للقبول أو الرفض عبر الإنترنت'))],
            ],
            [
                'id' => $this->id(),
                'type' => 'signature',
                'signers' => [
                    ['name' => [], 'role' => [$this->txt($this->dual(['Accepted by', 'قبِله']))]],
                    ['name' => [$this->f('quote.prepared_by', 'Prepared by', 'أعدّه')], 'role' => [$this->txt($this->dual(['Prepared by', 'أعدّه']))]],
                ],
                'date' => [],
                'stamp' => false,
            ],
        ];

        return [
            'kind' => 'template',
            'title' => $this->t('Quote', 'عرض سعر'),
            'lang' => $this->lang,
            'record_type' => QuoteFieldProvider::RECORD_TYPE,
            'page' => ['size' => 'A4', 'marginMm' => 16, 'letterhead' => true],
            'own_fields' => [],
            'blocks' => $blocks,
        ];
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
     * @param array{0: string, 1: string} $label
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function labelled(array $label, array $value): array
    {
        return $this->para($this->txt($this->dual($label), true), ['type' => 'break'], $value);
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
        return 'quo' . (++$this->ids);
    }
}
