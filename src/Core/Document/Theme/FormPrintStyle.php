<?php

declare(strict_types=1);

namespace Whity\Core\Document\Theme;

/**
 * How form responses print (design 3.4, FP.2-FP.10; `FormPrintoutStyle` in
 * `docs/design/handoff/editor/whity-document.ts`): the `form` key of a
 * document theme.
 *
 * Every key is optional and a missing one takes the standard layout (FP.3).
 * A workspace without the `documents.form_printouts` entitlement (a free
 * plan) always prints the standard layout, whatever its theme says: the
 * stored options are kept, so they come back with a paid plan, and the gate
 * is applied each time a printout is MADE ({@see resolve()}). A printout
 * already made is a stored PDF and never changes (FP.2).
 *
 * The client's marks (ISO, accreditation; FP.10) are part of the standard
 * footer, so they print on every plan.
 *
 * Pure and stateless.
 */
final class FormPrintStyle
{
    /** @var array<string, list<string>> The enum options, the standard first. */
    public const OPTIONS = [
        'header'        => ['letterhead', 'centred', 'bilingual'],
        'officeDetails' => ['decisionLine', 'strip'],
        'decision'      => ['topAndEnd', 'top', 'end'],
        // A number, B the Whity mark, C eyebrow, D rail, E tab, F plane (FP.4).
        'sectionStyle'  => ['mark', 'number', 'eyebrow', 'rail', 'tab', 'plane'],
        'questions'     => ['stacked', 'twoColumns'],
        'longAnswers'   => ['countAndContinued', 'plain'],
        'choices'       => ['allOptions', 'chosenOnly'],
        'yesNo'         => ['word', 'threeColumns'],
        'empty'         => ['collapseAndMark', 'hide', 'printAll'],
        'signatures'    => ['confirmedAndDrawn', 'confirmedOnly', 'withPaperLines'],
        'approvals'     => ['list', 'cards'],
    ];

    public const FOOTER_KEYS = ['reference', 'marks', 'qrEveryPage'];

    /** The standard footer: everything on but the QR on every page (FP.10). */
    public const STANDARD_FOOTER = ['reference' => true, 'pageCount' => true, 'marks' => true, 'qrEveryPage' => false];

    public const MAX_MARKS = 3;
    private const MAX_ALT = 80;

    /** A file of this workspace, as the editor stores an image's src. */
    private const FILE_SRC = '#^/api/files/\d{1,12}/content$#';

    /**
     * What is wrong with a `form` value, keyed by the token's path; empty when
     * it is valid.
     *
     * @return array<string, string>
     */
    public static function errors(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return ['form' => 'form must be an object.'];
        }
        $errors = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (isset(self::OPTIONS[$key])) {
                if (!in_array($item, self::OPTIONS[$key], true)) {
                    $errors["form.{$key}"] = "form.{$key} must be one of " . implode(', ', self::OPTIONS[$key]) . '.';
                }
                continue;
            }
            if ($key === 'footer') {
                if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                    $errors['form.footer'] = 'form.footer must be an object.';
                    continue;
                }
                foreach ($item as $part => $on) {
                    // Page x of y is always printed, so it may be sent but only as true.
                    if ((string) $part === 'pageCount' && $on === true) {
                        continue;
                    }
                    if (!in_array((string) $part, self::FOOTER_KEYS, true) || !is_bool($on)) {
                        $errors["form.footer.{$part}"] = 'form.footer may set ' . implode(', ', self::FOOTER_KEYS) . ' to true or false.';
                    }
                }
                continue;
            }
            if ($key === 'marks') {
                $reason = self::marksError($item);
                if ($reason !== null) {
                    $errors['form.marks'] = $reason;
                }
                continue;
            }
            $errors["form.{$key}"] = "form.{$key} is not a form printout option.";
        }

        return $errors;
    }

    /**
     * Every option resolved, with the plan gate applied: without
     * `$customAllowed` the standard layout, keeping only the client's marks.
     *
     * @param array<string, mixed>|null $form the theme's `form` tokens
     * @return array{header: string, officeDetails: string, decision: string, sectionStyle: string, questions: string, longAnswers: string, choices: string, yesNo: string, empty: string, signatures: string, approvals: string, footer: array{reference: bool, pageCount: bool, marks: bool, qrEveryPage: bool}, marks: list<array{src: string, alt: string}>, custom: bool}
     */
    public static function resolve(?array $form, bool $customAllowed): array
    {
        $form = is_array($form) && self::errors($form) === [] ? $form : [];
        $resolved = [];
        foreach (self::OPTIONS as $key => $values) {
            $resolved[$key] = $customAllowed && isset($form[$key]) ? (string) $form[$key] : $values[0];
        }
        $footer = self::STANDARD_FOOTER;
        if ($customAllowed && is_array($form['footer'] ?? null)) {
            foreach (self::FOOTER_KEYS as $part) {
                if (is_bool($form['footer'][$part] ?? null)) {
                    $footer[$part] = $form['footer'][$part];
                }
            }
        }
        $resolved['footer'] = $footer;
        $marks = [];
        foreach (is_array($form['marks'] ?? null) ? $form['marks'] : [] as $mark) {
            $marks[] = ['src' => (string) $mark['src'], 'alt' => trim((string) ($mark['alt'] ?? ''))];
        }
        $resolved['marks'] = $marks;
        $resolved['custom'] = $customAllowed && $form !== [];

        /** @var array{header: string, officeDetails: string, decision: string, sectionStyle: string, questions: string, longAnswers: string, choices: string, yesNo: string, empty: string, signatures: string, approvals: string, footer: array{reference: bool, pageCount: bool, marks: bool, qrEveryPage: bool}, marks: list<array{src: string, alt: string}>, custom: bool} $resolved */
        return $resolved;
    }

    /**
     * The options with their English and Arabic labels, for the Form printouts
     * tab (`TplFormPrintStyle`) and for agents.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $labels = [
            'header' => ['Header', 'الترويسة', [
                'letterhead' => ['Letterhead and title', 'الترويسة والعنوان'],
                'centred' => ['Centred', 'في الوسط'],
                'bilingual' => ['English and Arabic', 'بالعربية والإنجليزية'],
            ]],
            'officeDetails' => ['Office details', 'بيانات المكتب', [
                'decisionLine' => ['In the decision line', 'في سطر القرار'],
                'strip' => ['A separate strip', 'شريط منفصل'],
            ]],
            'decision' => ['Decision', 'القرار', [
                'topAndEnd' => ['Top line and full block', 'سطر في الأعلى وكتلة كاملة'],
                'top' => ['Top only', 'في الأعلى فقط'],
                'end' => ['End only', 'في النهاية فقط'],
            ]],
            'sectionStyle' => ['Section headings', 'عناوين الأقسام', [
                'mark' => ['B · Whity mark', 'B · علامة Whity'],
                'number' => ['A · Number', 'A · رقم'],
                'eyebrow' => ['C · Eyebrow', 'C · سطر علوي'],
                'rail' => ['D · Rail', 'D · شريط تقدّم'],
                'tab' => ['E · Tab', 'E · لسان'],
                'plane' => ['F · Plane', 'F · مستوى'],
            ]],
            'questions' => ['Questions', 'الأسئلة', [
                'stacked' => ['Stacked', 'متتالية'],
                'twoColumns' => ['Two columns', 'عمودان'],
            ]],
            'longAnswers' => ['Long answers', 'الإجابات الطويلة', [
                'countAndContinued' => ['Word count and "continued"', 'عدد الكلمات و«تابع»'],
                'plain' => ['Plain', 'بلا إضافات'],
            ]],
            'choices' => ['Choices', 'الخيارات', [
                'allOptions' => ['All options, chosen ticked', 'كل الخيارات مع تعليم المختار'],
                'chosenOnly' => ['Chosen only', 'المختار فقط'],
            ]],
            'yesNo' => ['Yes / No / N/A', 'نعم / لا / لا ينطبق', [
                'word' => ['A word with a mark', 'كلمة مع علامة'],
                'threeColumns' => ['Three columns', 'ثلاثة أعمدة'],
            ]],
            'empty' => ['Empty and N/A', 'الفارغ وغير المنطبق', [
                'collapseAndMark' => ['Collapse N/A, mark empty', 'طيّ غير المنطبق وتعليم الفارغ'],
                'hide' => ['Hide them', 'إخفاؤها'],
                'printAll' => ['Print everything', 'طباعة كل شيء'],
            ]],
            'signatures' => ['Signatures', 'التواقيع', [
                'confirmedAndDrawn' => ['Confirmed and drawn', 'مؤكَّد ومرسوم'],
                'confirmedOnly' => ['Confirmed only', 'مؤكَّد فقط'],
                'withPaperLines' => ['Also a line to sign on paper', 'مع سطر للتوقيع على الورق'],
            ]],
            'approvals' => ['Approvals', 'الموافقات', [
                'list' => ['A list with decisions', 'قائمة بالقرارات'],
                'cards' => ['Signature cards', 'بطاقات توقيع'],
            ]],
        ];
        $options = [];
        foreach (self::OPTIONS as $key => $values) {
            [$label, $labelAr, $valueLabels] = $labels[$key];
            $options[] = [
                'key' => "form.{$key}", 'type' => 'enum', 'overridable' => false, 'standard' => $values[0],
                'label' => $label, 'labelAr' => $labelAr,
                'values' => array_map(static function (string $v) use ($valueLabels): array {
                    $names = (array) ($valueLabels[$v] ?? [$v, $v]);

                    return ['value' => $v, 'label' => (string) $names[0], 'labelAr' => (string) $names[1]];
                }, $values),
            ];
        }

        return [
            'entitlement' => 'documents.form_printouts',
            'options' => $options,
            'footer' => [
                ['key' => 'reference', 'label' => 'Reference number', 'labelAr' => 'الرقم المرجعي', 'standard' => true],
                ['key' => 'pageCount', 'label' => 'Page x of y', 'labelAr' => 'الصفحة x من y', 'standard' => true, 'fixed' => true],
                ['key' => 'marks', 'label' => 'Your marks (ISO, accreditation)', 'labelAr' => 'علاماتك (الأيزو، الاعتماد)', 'standard' => true],
                ['key' => 'qrEveryPage', 'label' => 'Small QR on every page', 'labelAr' => 'رمز QR صغير في كل صفحة', 'standard' => false],
            ],
            'marks' => ['max' => self::MAX_MARKS, 'heightMm' => 8],
        ];
    }

    private static function marksError(mixed $marks): ?string
    {
        if (!is_array($marks) || !array_is_list($marks) || count($marks) > self::MAX_MARKS) {
            return 'form.marks must list at most ' . self::MAX_MARKS . ' images.';
        }
        foreach ($marks as $mark) {
            if (!is_array($mark) || !is_string($mark['src'] ?? null) || preg_match(self::FILE_SRC, $mark['src']) !== 1) {
                return 'Each mark must be a file of this workspace, as /api/files/{id}/content.';
            }
            $alt = $mark['alt'] ?? '';
            if (!is_string($alt) || mb_strlen($alt) > self::MAX_ALT || array_diff(array_keys($mark), ['src', 'alt']) !== []) {
                return 'Each mark is { src, alt } with alt text of at most ' . self::MAX_ALT . ' characters.';
            }
        }

        return null;
    }
}
