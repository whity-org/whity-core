<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * A form's responses as a CSV file, safe to open in a spreadsheet.
 *
 * FORMULA INJECTION. Answers can come from strangers on a public link, and a
 * spreadsheet runs a cell starting with `=`, `+`, `-` or `@` as a formula, which can
 * read other cells and send them somewhere. So any TEXT cell starting with one of
 * those, a tab or a carriage return, or their full-width forms, gets a leading `'`,
 * which spreadsheets treat as "this is text". Leading spaces and invisible format
 * characters don't hide one: the check looks past them. Numbers the validator typed
 * as numbers go out untouched, so `-5` from a number question stays a number.
 *
 * WHAT IS IN IT. The applicant's answers, as the record of what they said. Staff
 * answers are not: they have their own permission, and an export is easily passed on.
 * A file answer says how many are attached rather than giving the storage key, which
 * means nothing to a reader and shouldn't leave the system.
 *
 * Stateless — worker-safe.
 */
final class ResponsesCsv
{
    /** Bounds one export, which is built in memory. */
    public const MAX_ROWS = 20000;

    /** A byte-order mark, so Excel reads Arabic as UTF-8. */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * The file's own words. English and Arabic are what the export offers.
     */
    private const WORDS = [
        'en' => [
            'reference' => 'Reference',
            'submitted_at' => 'Submitted at (UTC)',
            'submitted_by' => 'Submitted by',
            'routing' => 'Where it is',
            'not routed' => 'Not sent anywhere',
            'in progress' => 'In progress',
            'completed' => 'Finished',
            'sent back' => 'Sent back for changes',
            'not approved' => 'Not approved',
            'withdrawn' => 'Withdrawn',
            'yes' => 'Yes',
            'no' => 'No',
            'attached' => '%d attached',
        ],
        'ar' => [
            'reference' => 'الرقم المرجعي',
            'submitted_at' => 'تاريخ الإرسال (UTC)',
            'submitted_by' => 'المرسل',
            'routing' => 'أين وصل',
            'not routed' => 'لم يُرسل إلى أي جهة',
            'in progress' => 'قيد المعالجة',
            'completed' => 'مكتمل',
            'sent back' => 'أُعيد لإجراء تعديلات',
            'not approved' => 'لم تتم الموافقة',
            'withdrawn' => 'مسحوب',
            'yes' => 'نعم',
            'no' => 'لا',
            'attached' => 'مرفقات: %d',
        ],
    ];

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Make one text cell safe to open in a spreadsheet.
     */
    public static function safeText(string $text): string
    {
        $visible = (string) preg_replace('/^[\s\p{Cf}]+/u', '', $text);
        if ($visible === '' && $text !== '' && preg_match('/^[\t\r\n]/', $text) !== 1) {
            return $text;
        }

        $first = mb_substr($visible, 0, 1);
        $dangerous = ['=', '+', '-', '@', '＝', '＋', '－', '＠'];
        if (in_array($first, $dangerous, true) || preg_match('/^[\t\r\n]/', $text) === 1) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * Builds the file.
     *
     * @param list<array<string, mixed>> $fields Applicant fields, in order.
     * @param iterable<array<string, mixed>> $rows Normalized submissions.
     */
    public static function build(array $fields, iterable $rows, string $language): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open a temporary stream for the export');
        }

        $header = [self::word('reference', $language), self::word('submitted_at', $language), self::word('submitted_by', $language), self::word('routing', $language)];
        foreach ($fields as $field) {
            $header[] = self::labelOf($field, $language);
        }
        self::write($stream, array_map([self::class, 'safeText'], $header));

        foreach ($rows as $row) {
            $data = $row['data'] ?? [];
            $answers = is_array($data) ? $data : [];

            $line = [
                self::safeText((string) ($row['reference'] ?? '')),
                self::timestamp((string) ($row['submitted_at'] ?? '')),
                self::safeText((string) ($row['submitted_by_name'] ?? '')),
                self::safeText(self::word((string) ($row['state'] ?? ''), $language)),
            ];
            foreach ($fields as $field) {
                $line[] = self::cell($field, $answers[(string) $field['field_key']] ?? null, $language);
            }
            self::write($stream, $line);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return self::BOM . $csv;
    }

    private static function word(string $key, string $language): string
    {
        return self::WORDS[$language][$key] ?? self::WORDS['en'][$key] ?? $key;
    }

    /**
     * A date a spreadsheet and a person can both read. The column heading says UTC.
     */
    private static function timestamp(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i');
        } catch (\Exception) {
            return $value;
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function labelOf(array $field, string $language): string
    {
        return self::localized($field['label'] ?? null, $language) ?: (string) $field['field_key'];
    }

    private static function localized(mixed $label, string $language): string
    {
        if (is_string($label)) {
            return $label;
        }
        if (!is_array($label)) {
            return '';
        }
        $wanted = $label[$language] ?? null;
        if (is_string($wanted) && $wanted !== '') {
            return $wanted;
        }
        // A named order, not the array's: PostgreSQL's JSONB reorders keys, so the same
        // label would otherwise fall back to a different language on each engine.
        $others = array_keys($label);
        sort($others);
        foreach (['en', 'ar', ...$others] as $language) {
            $text = $label[$language] ?? null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function cell(array $field, mixed $answer, string $language): string
    {
        if ($answer === null) {
            return '';
        }
        // Typed by the validator, so it can't be a formula. Only a number question's
        // answer takes this path, because text answers are always strings.
        if (is_int($answer) || is_float($answer)) {
            return (string) $answer;
        }
        if (is_bool($answer)) {
            return self::word($answer ? 'yes' : 'no', $language);
        }

        $type = (string) ($field['field_type'] ?? '');

        if ($type === FieldType::FILE) {
            $count = is_array($answer) ? count($answer) : 1;

            return sprintf(self::word('attached', $language), $count);
        }
        if ($type === FieldType::SIGNATURE && is_array($answer)) {
            return self::safeText(trim((string) ($answer['name'] ?? '') . ' ' . self::timestamp((string) ($answer['signed_at'] ?? ''))));
        }
        if ($type === FieldType::REPEATING && is_array($answer)) {
            return self::safeText(implode(' | ', array_map(
                static fn (mixed $row): string => self::rowText($field, $row, $language),
                $answer,
            )));
        }
        if (is_array($answer)) {
            return self::safeText(implode(', ', array_map(
                static fn (mixed $one): string => self::optionText($field, $one, $language),
                $answer,
            )));
        }

        return self::safeText(self::optionText($field, $answer, $language));
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function optionText(array $field, mixed $value, string $language): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === $text) {
                return self::localized($option['label'] ?? null, $language) ?: $text;
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function rowText(array $field, mixed $row, string $language): string
    {
        if (!is_array($row)) {
            return '';
        }
        $rules = $field['validation'] ?? [];
        $columns = is_array($rules) && is_array($rules['columns'] ?? null) ? $rules['columns'] : [];

        $parts = [];
        foreach ($columns as $column) {
            if (!is_array($column) || !array_key_exists((string) ($column['key'] ?? ''), $row)) {
                continue;
            }
            $value = $row[(string) $column['key']];
            $text = is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? (string) $value : '');
            $parts[] = (self::localized($column['label'] ?? null, $language) ?: (string) $column['key']) . ': ' . $text;
        }

        return implode('; ', $parts);
    }

    /**
     * @param resource $stream
     * @param list<string> $cells
     */
    private static function write($stream, array $cells): void
    {
        // The empty escape: PHP's default `\` would corrupt an answer ending in one.
        fputcsv($stream, $cells, ',', '"', '', "\r\n");
    }
}
