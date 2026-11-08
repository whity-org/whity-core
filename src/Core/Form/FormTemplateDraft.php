<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * The document content a form's answers print in: a whole template made from
 * the form, or the lines added to a template that has no place for a question.
 *
 * Words here are printed on the document, so they follow the template's
 * language rather than the language of the person making it.
 *
 * Stateless — worker-safe.
 */
final class FormTemplateDraft
{
    /**
     * A title, the submission's reference and date, then one table of questions
     * and answers per section.
     *
     * @param array<string, mixed>       $form   A normalized `forms` row.
     * @param list<array<string, mixed>> $fields The form's fields, in order.
     * @return list<array<string, mixed>>
     */
    public static function blocks(array $form, array $fields, string $lang): array
    {
        $blocks = [self::block('heading', ['level' => 1, 'content' => [self::text(self::title($form, $lang))]])];

        if (is_string($form['reference_format'] ?? null) && $form['reference_format'] !== '') {
            $blocks[] = self::line($lang === 'ar' ? 'الرقم المرجعي' : 'Reference', FormSubmissionFieldProvider::REFERENCE);
        }
        $blocks[] = self::line($lang === 'ar' ? 'تاريخ الإرسال' : 'Sent on', FormSubmissionFieldProvider::SENT_ON);

        $titles = [];
        foreach (is_array($form['sections'] ?? null) ? $form['sections'] : [] as $section) {
            if (is_array($section) && is_string($section['key'] ?? null)) {
                /** @var array<string, string> $title */
                $title = is_array($section['title'] ?? null) ? $section['title'] : [];
                $titles[$section['key']] = self::localized($title, $lang);
            }
        }

        // In the order people answer them, keeping a section's questions together.
        $sections = [];
        foreach (FormFieldRepository::applicantFields($fields) as $field) {
            $sections[(string) ($field['section_key'] ?? '')][] = $field;
        }
        foreach ($sections as $sectionKey => $questions) {
            $title = $titles[$sectionKey] ?? '';
            if ($title !== '') {
                $blocks[] = self::block('heading', ['level' => 2, 'content' => [self::text($title)]]);
            }
            $blocks[] = self::block('table', [
                'headerRow' => false,
                'columns' => [['align' => 'start'], ['align' => 'start']],
                'rows' => array_map(static function (array $field) use ($lang): array {
                    $label = self::label($field, $lang);

                    return [[self::text($label)], [self::field(FormSubmissionFieldProvider::path((string) $field['field_key']), $label)]];
                }, $questions),
            ]);
        }

        return $blocks;
    }

    /**
     * "Label: answer", the line added to a template for one question.
     *
     * @return array<string, mixed>
     */
    public static function line(string $label, string $path): array
    {
        return self::block('paragraph', ['content' => [
            ['type' => 'text', 'text' => $label . ': ', 'marks' => [['type' => 'bold']]],
            self::field($path, $label),
        ]]);
    }

    /** @param array<string, mixed> $form */
    public static function title(array $form, string $lang): string
    {
        /** @var array<string, string> $name */
        $name = is_array($form['name'] ?? null) ? $form['name'] : [];
        $title = self::localized($name, $lang);

        return $title !== '' ? $title : (string) ($form['form_key'] ?? '');
    }

    /** @param array<string, mixed> $field */
    public static function label(array $field, string $lang): string
    {
        /** @var array<string, string> $label */
        $label = is_array($field['label'] ?? null) ? $field['label'] : [];
        $text = self::localized($label, $lang);

        return $text !== '' ? $text : (string) $field['field_key'];
    }

    /** @param array<string, string> $map */
    private static function localized(array $map, string $lang): string
    {
        $text = trim($map[$lang] ?? '');

        return $text !== '' ? $text : LocalizedLabel::preferred($map);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function block(string $type, array $attributes): array
    {
        return ['id' => 'f' . bin2hex(random_bytes(6)), 'type' => $type] + $attributes;
    }

    /** @return array<string, mixed> */
    private static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** @return array<string, mixed> */
    private static function field(string $path, string $label): array
    {
        return ['type' => 'field', 'path' => $path, 'label' => $label];
    }
}
