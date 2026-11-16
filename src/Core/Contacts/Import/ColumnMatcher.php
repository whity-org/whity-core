<?php

declare(strict_types=1);

namespace Whity\Core\Contacts\Import;

/**
 * Suggests where each spreadsheet column goes (WHIT-953, `TplImport` step 2).
 *
 * By name first: a header that is one of a target's names, in English or
 * Arabic, is `sure`; one that contains a name is `doubtful` (shown amber).
 * Then by content, for a header that says nothing: a column of email
 * addresses is email, a column of phone numbers is mobile; both `doubtful`.
 * An empty column is skipped. A target is suggested at most once: the first
 * sure match wins it. Stateless.
 */
final class ColumnMatcher
{
    /**
     * The built-in targets and the names people give them.
     *
     * @var array<string, list<string>>
     */
    public const NAMES = [
        'name' => ['name', 'full name', 'company', 'company name', 'business', 'business name', 'customer', 'customer name', 'supplier', 'supplier name', 'client', 'الاسم', 'اسم', 'الشركة', 'اسم الشركة', 'العميل', 'اسم العميل', 'المورد', 'اسم المورد'],
        'contact_person' => ['contact person', 'contact', 'contact name', 'person', 'attention', 'attn', 'الشخص المسؤول', 'المسؤول', 'جهة الاتصال', 'اسم المسؤول'],
        'email' => ['email', 'e-mail', 'email address', 'mail', 'البريد', 'البريد الإلكتروني', 'الايميل', 'الإيميل'],
        'mobile' => ['mobile', 'mobile number', 'cell', 'cell phone', 'whatsapp', 'gsm', 'الجوال', 'الموبايل', 'موبايل', 'رقم الجوال', 'الهاتف المحمول', 'واتساب'],
        'phone' => ['phone', 'telephone', 'tel', 'landline', 'phone number', 'office phone', 'الهاتف', 'رقم الهاتف', 'هاتف'],
        'tax_number' => ['tax number', 'tax no', 'tax id', 'vat', 'vat number', 'tin', 'tax', 'trn', 'الرقم الضريبي', 'رقم ضريبي', 'الضريبة'],
        'payment_terms' => ['payment terms', 'terms', 'how they pay', 'payment', 'payment method', 'شروط الدفع', 'طريقة الدفع', 'الدفع'],
        'address' => ['address', 'street', 'city', 'location', 'العنوان', 'عنوان', 'المدينة'],
        'notes' => ['notes', 'note', 'comment', 'comments', 'remarks', 'ملاحظات', 'ملاحظة'],
        'tags' => ['tags', 'tag', 'labels', 'group', 'category', 'segment', 'الوسوم', 'وسم', 'تصنيف', 'الفئة', 'المجموعة'],
        'type' => ['type', 'kind', 'customer or supplier', 'role', 'النوع', 'الصفة'],
    ];

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows the data rows under the header
     * @param array<string, list<string>> $extraTargets more targets (the workspace's own fields) and their names
     * @return list<array{index: int, header: string, examples: list<string>, empty: bool, suggestion: ?array{target: string, confidence: string}}>
     */
    public static function suggest(array $header, array $rows, array $extraTargets = []): array
    {
        $targets = self::NAMES + $extraTargets;
        $width = count($header);
        foreach ($rows as $row) {
            $width = max($width, count($row));
        }

        $columns = [];
        for ($i = 0; $i < $width; $i++) {
            $values = [];
            foreach ($rows as $row) {
                $v = $row[$i] ?? '';
                if ($v !== '') {
                    $values[] = $v;
                }
            }
            $columns[] = [
                'index' => $i,
                'header' => $header[$i] ?? '',
                'examples' => array_slice(array_values(array_unique($values)), 0, 3),
                'empty' => $values === [],
                'suggestion' => null,
                '_values' => array_slice($values, 0, 50),
            ];
        }

        $taken = [];
        // Exact names first, so "Name" wins `name` before "Company name" does.
        foreach (['sure', 'doubtful'] as $pass) {
            foreach ($columns as $i => $column) {
                if ($column['empty'] || $column['suggestion'] !== null) {
                    continue;
                }
                $target = self::byName($column['header'], array_diff_key($targets, $taken), $pass === 'sure');
                if ($target !== null) {
                    $columns[$i]['suggestion'] = ['target' => $target, 'confidence' => $pass];
                    $taken[$target] = true;
                }
            }
        }
        foreach ($columns as $i => $column) {
            if ($column['empty'] || $column['suggestion'] !== null) {
                continue;
            }
            $target = self::byContent($column['_values']);
            if ($target !== null && !isset($taken[$target])) {
                $columns[$i]['suggestion'] = ['target' => $target, 'confidence' => 'doubtful'];
                $taken[$target] = true;
            }
        }

        return array_map(static function (array $c): array {
            unset($c['_values']);

            return $c;
        }, $columns);
    }

    /** @param array<string, list<string>> $targets */
    private static function byName(string $header, array $targets, bool $exact): ?string
    {
        $h = self::normal($header);
        if ($h === '') {
            return null;
        }
        foreach ($targets as $target => $names) {
            foreach ($names as $name) {
                $n = self::normal($name);
                if ($n === '') {
                    continue;
                }
                if ($exact ? $h === $n : (mb_strlen($n) >= 3 && str_contains($h, $n))) {
                    return $target;
                }
            }
        }

        return null;
    }

    /** @param list<string> $values */
    private static function byContent(array $values): ?string
    {
        if ($values === []) {
            return null;
        }
        $emails = 0;
        $phones = 0;
        foreach ($values as $v) {
            if (filter_var($v, FILTER_VALIDATE_EMAIL) !== false) {
                $emails++;
            } elseif (preg_match('/^\+?[\d\s\-\(\)\.]{7,20}$/', $v) === 1) {
                $phones++;
            }
        }
        $n = count($values);
        if ($emails >= $n * 0.8) {
            return 'email';
        }
        if ($phones >= $n * 0.8) {
            return 'mobile';
        }

        return null;
    }

    private static function normal(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/[\s_\-\.:#]+/u', ' ', $s);
        // Alef forms and taa marbuta read the same in a header.
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه']);

        return trim($s);
    }
}
