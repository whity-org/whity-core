<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use Whity\Core\Document\Print\WhityDocumentPrinter;

/**
 * How well a form and its final document template fit, in both directions:
 * what the template prints that the form never asks, and what the form asks
 * that the template has no place for.
 *
 * WHICH QUESTION FILLS WHICH FIELD
 * --------------------------------
 *  - `submission.<key>` is that question's answer.
 *  - `template.<key>`, a template's own field, is the answer of the question
 *    with the same key or, failing that, the same wording in either language.
 *    That is how a template written before the form was (a starter, say) prints
 *    the form's answers. {@see self::ownFieldAnswers()} is the one place that
 *    decides it: the check reports with it and the finalise step prints with
 *    it, so what is reported as filled is what gets filled.
 *
 * Only questions the applicant answers count: the office's own questions are
 * not printed on the final document.
 *
 * Stateless — worker-safe.
 */
final class FormTemplateFit
{
    public const NOT_ASKED = 'not_asked';
    public const STAFF_ONLY = 'staff_only';
    public const OTHER_RECORD = 'other_record';

    private const OWN = 'template.';
    private const ANSWER = 'submission.';
    private const ISSUED = 'issued_document.';

    private const ABOUT_SUBMISSION = [
        FormSubmissionFieldProvider::REFERENCE => true,
        FormSubmissionFieldProvider::SENT_BY => true,
        FormSubmissionFieldProvider::SENT_ON => true,
        FormSubmissionFieldProvider::VERSION => true,
        FormSubmissionFieldProvider::CONTENT_HASH => true,
    ];

    /**
     * Each own field the content prints, with the question that fills it.
     *
     * @param list<array<string, mixed>> $fields  The form's fields, in order.
     * @param array<string, mixed>       $content A template version or draft.
     * @return array<string, string> `template.<key>` => question key
     */
    public static function ownFieldAnswers(array $fields, array $content): array
    {
        $keys = [];
        $wordings = [];
        foreach (FormFieldRepository::applicantFields($fields) as $question) {
            $key = (string) $question['field_key'];
            $keys[$key] = true;
            /** @var array<string, string> $label */
            $label = is_array($question['label'] ?? null) ? $question['label'] : [];
            foreach ($label as $text) {
                $wordings[self::wording((string) $text)] ??= $key;
            }
        }

        $own = self::ownLabels($content);
        $answers = [];
        foreach (self::refs($content) as $path => $printedLabel) {
            if (!str_starts_with($path, self::OWN)) {
                continue;
            }
            $key = substr($path, strlen(self::OWN));
            $question = isset($keys[$key]) ? $key : ($wordings[self::wording($own[$key] ?? $printedLabel)] ?? null);
            if ($question !== null) {
                $answers[$path] = $question;
            }
        }

        return $answers;
    }

    /**
     * @param list<array<string, mixed>> $fields      The form's fields, in order.
     * @param array<string, mixed>       $printed     The version that prints.
     * @param array<string, mixed>|null  $draft       The template's draft, when it can differ.
     * @param array<string, true>        $sharedPaths Fields every document has (Company, Today).
     * @param list<string>               $leftOut     Questions accepted as staying out.
     * @return array{filled: int, needs: list<array{path: string, label: string, reason: string, field_key: ?string}>, unused: list<array{field_key: string, label: array<string, string>, in_draft: bool, left_out: bool}>}
     */
    public static function check(array $fields, array $printed, ?array $draft, array $sharedPaths, array $leftOut): array
    {
        $questions = FormFieldRepository::applicantFields($fields);
        $applicant = array_fill_keys(array_map(static fn (array $q): string => (string) $q['field_key'], $questions), true);
        $answers = self::ownFieldAnswers($fields, $printed);

        $filled = 0;
        $needs = [];
        foreach (self::refs($printed) as $path => $label) {
            if (isset($sharedPaths[$path]) || isset(self::ABOUT_SUBMISSION[$path]) || str_starts_with($path, self::ISSUED)) {
                $filled++;
                continue;
            }
            $isOwn = str_starts_with($path, self::OWN);
            if (!$isOwn && !str_starts_with($path, self::ANSWER)) {
                $needs[] = ['path' => $path, 'label' => $label, 'reason' => self::OTHER_RECORD, 'field_key' => null];
                continue;
            }
            $key = substr($path, strlen($isOwn ? self::OWN : self::ANSWER));
            if ($isOwn ? isset($answers[$path]) : isset($applicant[$key])) {
                $filled++;
                continue;
            }
            $needs[] = self::isStaffQuestion($fields, $key)
                ? ['path' => $path, 'label' => $label, 'reason' => self::STAFF_ONLY, 'field_key' => null]
                : ['path' => $path, 'label' => $label, 'reason' => self::NOT_ASKED, 'field_key' => self::questionKey($key)];
        }

        $placed = self::placedQuestions($fields, $printed);
        $inDraft = $draft !== null ? self::placedQuestions($fields, $draft) : [];
        $accepted = array_flip($leftOut);
        $unused = [];
        foreach ($questions as $question) {
            $key = (string) $question['field_key'];
            if (isset($placed[$key])) {
                continue;
            }
            /** @var array<string, string> $label */
            $label = is_array($question['label'] ?? null) ? $question['label'] : [];
            $unused[] = ['field_key' => $key, 'label' => $label, 'in_draft' => isset($inDraft[$key]), 'left_out' => isset($accepted[$key])];
        }

        return ['filled' => $filled, 'needs' => $needs, 'unused' => $unused];
    }

    /** @param list<array<string, mixed>> $fields */
    private static function isStaffQuestion(array $fields, string $key): bool
    {
        foreach ($fields as $field) {
            if ((string) $field['field_key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Field path => the label it is printed with, once each, in reading order.
     * The page counts too, because a header or footer can print a field.
     *
     * @param array<string, mixed> $content
     * @return array<string, string>
     */
    private static function refs(array $content): array
    {
        $refs = [];
        foreach (WhityDocumentPrinter::fieldRefs([$content['blocks'] ?? [], $content['page'] ?? null]) as $ref) {
            $path = (string) $ref['path'];
            $refs[$path] ??= is_string($ref['label'] ?? null) && $ref['label'] !== '' ? $ref['label'] : $path;
        }

        return $refs;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $content
     * @return array<string, true>
     */
    private static function placedQuestions(array $fields, array $content): array
    {
        $placed = [];
        foreach (self::ownFieldAnswers($fields, $content) as $question) {
            $placed[$question] = true;
        }
        foreach (array_keys(self::refs($content)) as $path) {
            if (str_starts_with($path, self::ANSWER) && !isset(self::ABOUT_SUBMISSION[$path])) {
                $placed[substr($path, strlen(self::ANSWER))] = true;
            }
        }

        return $placed;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, string>
     */
    private static function ownLabels(array $content): array
    {
        $labels = [];
        foreach (is_array($content['own_fields'] ?? null) ? $content['own_fields'] : [] as $own) {
            if (is_array($own) && is_string($own['key'] ?? null) && is_string($own['label'] ?? null)) {
                $labels[$own['key']] = $own['label'];
            }
        }

        return $labels;
    }

    /** A template field's key as a form question's key: lower case, starting with a letter. */
    private static function questionKey(string $key): string
    {
        $clean = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)), '_');
        $clean = preg_match('/^[a-z]/', $clean) === 1 ? $clean : 'question_' . $clean;

        return substr($clean, 0, FormFieldRepository::KEY_MAX);
    }

    private static function wording(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
