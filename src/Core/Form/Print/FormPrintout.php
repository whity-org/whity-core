<?php

declare(strict_types=1);

namespace Whity\Core\Form\Print;

use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormFieldRepository;

/**
 * A form response as Whity Render's flowing payload: the automatic layout of
 * design 3.4 (FP.1, FP.3; `DocFormPrintout`). Any form prints from its own
 * sections and questions, with no template, in the workspace theme's `form`
 * options ({@see \Whity\Core\Document\Theme\FormPrintStyle}).
 *
 * The page, top to bottom: the letterhead and the form's title and byline;
 * the decision line carrying the office details (or the office strip); the
 * form's description as a notice; each section with its questions; the
 * signatures; the questions the office answers ("For official use"); the
 * decision block with the verification QR and the approvals, with the people
 * a step left out listed as "Not counted". From page 2 a running header
 * replaces the letterhead; the footer carries the reference, "Page x of y",
 * the client's marks and, as an option, a small QR.
 *
 * Every word is decided here and the render service only draws, so a stored
 * response prints the same way every time its inputs are the same.
 *
 * Pure: everything it needs comes in through {@see build()}.
 */
final class FormPrintout
{
    /** FP.3: more options than this always print the chosen only. */
    public const ALL_OPTIONS_MAX = 8;
    /** A run of this many Yes / No / N/A questions prints as one list. */
    private const YES_NO_RUN = 3;
    private const NA_WORDS = ['na', 'n/a', 'n.a.', 'not applicable', 'not_applicable', 'لا ينطبق'];
    private const YES_WORDS = ['yes', 'نعم', 'true'];
    private const NO_WORDS = ['no', 'لا', 'false'];

    private FormPrintLabels $t;
    /** @var array<string, mixed> */
    private array $style;
    /** @var callable(string, int): ?string */
    private $names;

    /**
     * @param array{
     *   lang: string,
     *   style: array<string, mixed>,
     *   theme?: array<string, mixed>,
     *   workspace: array{name: string, lines?: list<string>, logo?: ?string, nameAr?: ?string, linesAr?: list<string>},
     *   form: array{title: mixed, description?: mixed, sections?: list<array<string, mixed>>, fields: list<array<string, mixed>>},
     *   answers: array<string, mixed>,
     *   office?: array<string, array{value: mixed, by?: ?string, at?: ?string}>,
     *   submission: array{reference?: ?string, submittedAt?: ?string, submittedBy?: ?string},
     *   decision?: ?array{status: string, decidedAt?: ?string, approvals?: list<array{name: string, role?: ?string, at?: ?string}>, notCounted?: list<array{name: string, question?: ?string}>},
     *   verify?: ?array{url: string, reference?: ?string},
     *   marks?: list<array{src: string, alt?: string}>,
     *   names?: callable(string, int): ?string,
     * } $in
     * @return array<string, mixed>
     */
    public function build(array $in): array
    {
        $this->t = new FormPrintLabels($in['lang']);
        $this->style = $in['style'];
        $this->names = $in['names'] ?? static fn (string $type, int $id): ?string => null;
        $t = $this->t;

        $title = $t->pick($in['form']['title']) ?: 'Form';
        $reference = trim((string) ($in['submission']['reference'] ?? ''));
        $workspace = $in['workspace'];
        $decision = $in['decision'] ?? null;
        $verify = $in['verify'] ?? null;

        $content = [$this->letterhead($workspace)];
        $byline = ($in['submission']['submittedBy'] ?? '') !== ''
            ? $t->get('submittedBy', ['workspace' => $workspace['name'], 'name' => (string) $in['submission']['submittedBy'], 'date' => $t->date($in['submission']['submittedAt'] ?? null)])
            : $t->get('submittedAnon', ['workspace' => $workspace['name'], 'date' => $t->date($in['submission']['submittedAt'] ?? null)]);
        $content[] = ['type' => 'formTitle', 'title' => $title, 'byline' => $byline];

        $atTop = in_array($this->style['decision'], ['topAndEnd', 'top'], true);
        $atEnd = in_array($this->style['decision'], ['topAndEnd', 'end'], true);
        $office = $this->officeItems($reference, $in['submission']['submittedAt'] ?? null, $decision);
        if ($this->style['officeDetails'] === 'strip') {
            $content[] = ['type' => 'officeStrip', 'title' => $t->get('officeStrip'), 'items' => array_slice($office, 0, 4)];
            if ($atTop && $decision !== null) {
                $content[] = $this->decisionLine($decision, [], $atEnd);
            }
        } elseif ($atTop || $decision === null) {
            $content[] = $this->decisionLine($decision, array_values(array_filter($office, static fn (array $i): bool => !isset($i['status']))), $atEnd && $decision !== null);
        }

        $description = $t->pick($in['form']['description'] ?? null);
        if ($description !== '') {
            $content[] = ['type' => 'notice', 'text' => mb_substr($description, 0, 2000)];
        }

        $fields = $in['form']['fields'];
        $applicant = FormFieldRepository::applicantFields($fields);
        $staff = FormFieldRepository::staffFields($fields);
        $sections = $this->sections($in['form']['sections'] ?? [], $applicant);
        $number = 0;
        $titled = count(array_filter($sections, static fn (array $s): bool => $s['title'] !== ''));
        foreach ($sections as $section) {
            if ($section['fields'] === [] && $section['title'] === '') {
                continue;
            }
            $number += $section['title'] !== '' ? 1 : 0;
            $this->section($content, $section, $section['fields'], $in['answers'], $number, $titled);
        }
        if ($staff !== []) {
            $this->officialUse($content, $staff, $in['office'] ?? []);
        }
        if ($atEnd && $decision !== null) {
            if ($this->style['decision'] !== 'top') {
                $content[] = ['type' => 'pageBreak'];
            }
            $content[] = $this->sectionHeading('✓', $t->get('committeeDecision'), null);
            $content[] = $this->decisionBlock($decision, $reference, $title, $in['submission'], $verify);
            $content[] = $this->approvals($decision);
        }

        return $this->page($content, $title, $reference, $workspace, $in['theme'] ?? [], $in['marks'] ?? [], $verify);
    }

    /* --------------------------------------------------------------- page */

    /**
     * @param list<array<mixed>> $content
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $theme
     * @param list<array{src: string, alt?: string}> $marks
     * @param array{url: string, reference?: ?string}|null $verify
     * @return array<string, mixed>
     */
    private function page(array $content, string $title, string $reference, array $workspace, array $theme, array $marks, ?array $verify): array
    {
        $footer = $this->style['footer'];
        $pageOf = $this->t->lang === 'ar' ? 'صفحة {{page}} من {{pages}}' : 'Page {{page}} of {{pages}}';
        $end = [];
        if ($footer['marks'] && $marks !== []) {
            $end['marks'] = array_map(static fn (array $m): array => ['image' => ['src' => $m['src'], 'alt' => (string) ($m['alt'] ?? '')]], array_slice($marks, 0, 3));
        }
        if ($footer['qrEveryPage'] && $verify !== null) {
            $end['qr'] = ['value' => $verify['url']];
        }
        $start = $footer['reference'] && $reference !== '' ? $reference . '   ' . $pageOf : $pageOf;
        $header = ['start' => ['text' => $workspace['name'], 'subtext' => $title], 'end' => $reference, 'fromPage' => 2];
        if (($workspace['logo'] ?? null) !== null) {
            $header['start']['image'] = ['src' => $workspace['logo'], 'alt' => $workspace['name'], 'heightMm' => 7];
        }

        $payload = [
            'title' => $title,
            'direction' => $this->t->lang === 'ar' ? 'rtl' : 'ltr',
            'lang' => $this->t->lang,
            // FP: A4 at 16 mm; the bottom margin holds the footer and its marks.
            'page' => ['widthMm' => 210, 'heightMm' => 297, 'margin' => ['topMm' => 22, 'rightMm' => 16, 'bottomMm' => 22, 'leftMm' => 16]],
            'content' => $content,
            'header' => $header,
            'footer' => ['start' => $start] + ($end !== [] ? ['end' => $end] : []),
        ];
        $accent = $theme['accent'] ?? null;
        if (is_string($accent) && preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) === 1) {
            $payload['theme'] = ['accent' => $accent];
        }
        if (($theme['titleFace'] ?? null) === 'noto') {
            $payload['theme'] = ($payload['theme'] ?? []) + ['titleFace' => 'noto'];
        }
        if (($theme['largePrint'] ?? false) === true) {
            $payload['theme'] = ($payload['theme'] ?? []) + ['largePrint' => true];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $workspace
     * @return array<string, mixed>
     */
    private function letterhead(array $workspace): array
    {
        $start = ['name' => (string) $workspace['name']];
        if (($workspace['logo'] ?? null) !== null) {
            $start['image'] = ['src' => $workspace['logo'], 'alt' => (string) $workspace['name'], 'heightMm' => 15];
        }
        $lines = array_values(array_filter(array_map('strval', $workspace['lines'] ?? []), static fn (string $l): bool => trim($l) !== ''));
        $header = $this->style['header'];
        if ($header === 'bilingual' && trim((string) ($workspace['nameAr'] ?? '')) !== '') {
            return [
                'type' => 'letterhead', 'layout' => 'bilingual',
                'start' => $start + ['lines' => array_slice($lines, 0, 2)],
                'alt' => ['name' => (string) $workspace['nameAr'], 'lines' => array_slice(array_values($workspace['linesAr'] ?? []), 0, 2)],
            ];
        }
        if ($header === 'centred' || $header === 'bilingual') {
            return ['type' => 'letterhead', 'layout' => 'centred', 'start' => $start + ['lines' => [implode(' · ', array_slice($lines, 0, 3))]]];
        }

        return ['type' => 'letterhead', 'start' => $start, 'end' => ['lines' => $lines === [] ? [] : [implode(' · ', array_slice($lines, 0, 3))]]];
    }

    /* ------------------------------------------------------------ decision */

    /**
     * The office details (FP.3): reference, received and, once decided, the decision and its date.
     *
     * @param array<string, mixed>|null $decision
     * @return list<array<string, mixed>>
     */
    private function officeItems(string $reference, ?string $receivedAt, ?array $decision): array
    {
        $t = $this->t;
        $items = [];
        if ($reference !== '') {
            $items[] = ['label' => $t->get('reference'), 'value' => $reference, 'mono' => true];
        }
        $items[] = ['label' => $t->get('received'), 'value' => $t->date($receivedAt)];
        if ($decision !== null) {
            $items[] = ['label' => $t->get('decision'), 'value' => $this->statusWord($decision['status']), 'status' => $this->status($decision['status'])];
            if (($decision['decidedAt'] ?? null) !== null) {
                $items[] = ['label' => $t->get('decided'), 'value' => $t->date($decision['decidedAt'])];
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed>|null $decision
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function decisionLine(?array $decision, array $items, bool $pointToEnd): array
    {
        $status = $decision === null ? 'waiting' : $this->status($decision['status']);
        $word = $decision === null ? $this->t->get('received') : $this->statusWord($decision['status']);
        if ($decision === null) {
            $items = array_values(array_filter($items, fn (array $i): bool => $i['label'] !== $this->t->get('received')));
        }
        $line = ['type' => 'decisionLine', 'status' => $status, 'word' => $word, 'items' => array_map(
            static fn (array $i): array => array_intersect_key($i, ['label' => 1, 'value' => 1, 'mono' => 1]),
            array_slice($items, 0, 8),
        )];
        if ($pointToEnd) {
            $line['note'] = $this->t->get('fullDecision');
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $submission
     * @param array{url: string, reference?: ?string}|null $verify
     * @return array<string, mixed>
     */
    private function decisionBlock(array $decision, string $reference, string $title, array $submission, ?array $verify): array
    {
        $t = $this->t;
        $rows = [];
        if ($reference !== '') {
            $rows[] = ['label' => $t->get('reference'), 'value' => $reference, 'mono' => true];
        }
        $rows[] = ['label' => $t->get('project'), 'value' => $title];
        if (($submission['submittedBy'] ?? '') !== '') {
            $rows[] = ['label' => $t->get('applicant'), 'value' => (string) $submission['submittedBy']];
        }
        if (($decision['decidedAt'] ?? null) !== null) {
            $rows[] = ['label' => $t->get('decided'), 'value' => $t->date($decision['decidedAt'])];
        }
        // FP.5: conditions always print, even "None".
        $rows[] = ['label' => $t->get('conditions'), 'value' => trim((string) ($decision['conditions'] ?? '')) ?: $t->get('none')];
        $block = ['type' => 'decisionBlock', 'status' => $this->status($decision['status']), 'word' => $this->statusWord($decision['status']), 'rows' => $rows];
        if ($verify !== null) {
            $block['qr'] = ['value' => $verify['url'], 'caption' => $t->get('scanToVerify')];
            if (($verify['reference'] ?? '') !== '') {
                $block['qr']['reference'] = (string) $verify['reference'];
            }
        }

        return $block;
    }

    /**
     * FP.6: one row per approver; supervisors left out are named under it.
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private function approvals(array $decision): array
    {
        $t = $this->t;
        $cards = $this->style['approvals'] === 'cards';
        $rows = [];
        foreach (array_slice($decision['approvals'] ?? [], 0, 40) as $a) {
            $rows[] = array_filter([
                'status' => 'approved',
                'word' => $cards ? $t->get('approvedInWhity') : $t->get('approvedWord'),
                'name' => (string) $a['name'],
                'role' => isset($a['role']) && $a['role'] !== '' ? (string) $a['role'] : null,
                'at' => $t->date($a['at'] ?? null, true) ?: null,
            ], static fn ($v): bool => $v !== null);
        }
        $block = ['type' => 'approvals', 'title' => $t->get('approvals'), 'rows' => $rows];
        if ($cards) {
            $block['style'] = 'cards';
        }
        $left = $decision['notCounted'] ?? [];
        if ($left !== []) {
            $names = array_map(fn (array $p): string => ($p['question'] ?? '') !== ''
                ? $t->get('namedAs', ['name' => $p['name'], 'question' => $p['question']])
                : (string) $p['name'], $left);
            $block['notCounted'] = $t->get('notCounted', ['names' => implode($t->lang === 'ar' ? '؛ ' : '; ', $names)]);
        }

        return $block;
    }

    private function status(string $status): string
    {
        return match ($status) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'revisions' => 'revisions',
            default => 'waiting',
        };
    }

    private function statusWord(string $status): string
    {
        return $this->t->get(match ($status) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'revisions' => 'revisions',
            default => 'waiting',
        });
    }

    /* ------------------------------------------------------------ sections */

    /**
     * The form's sections in order, each with its questions; questions in no
     * section come first, under no heading.
     *
     * @param list<array<string, mixed>> $sections
     * @param list<array<string, mixed>> $fields
     * @return list<array{key: ?string, title: string, description: string, fields: list<array<string, mixed>>}>
     */
    private function sections(array $sections, array $fields): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[(string) ($field['section_key'] ?? '')][] = $field;
        }
        $out = [];
        $known = [];
        foreach ($sections as $section) {
            $key = (string) ($section['key'] ?? '');
            $known[$key] = true;
            $out[] = [
                'key' => $key,
                // A section with no title prints its key as words ("project_details": "Project details").
                'title' => $this->t->pick($section['title'] ?? null) ?: self::humanise($key),
                'description' => $this->t->pick($section['description'] ?? null),
                'fields' => $byKey[$key] ?? [],
            ];
        }
        $loose = [];
        foreach ($byKey as $key => $list) {
            if (!isset($known[(string) $key])) {
                array_push($loose, ...$list);
            }
        }
        if ($loose !== []) {
            array_unshift($out, ['key' => null, 'title' => '', 'description' => '', 'fields' => $loose]);
        }

        return $out;
    }

    /**
     * @param array{label: string, summary?: string}|null $na
     * @return array<string, mixed>
     */
    private function sectionHeading(string $number, string $title, ?int $total, ?string $note = null, ?array $na = null): array
    {
        $style = $this->style['sectionStyle'];
        $block = ['type' => 'formSection', 'style' => $style, 'number' => $number, 'title' => $title];
        if (ctype_digit($number) && $total !== null && $total >= (int) $number) {
            $of = $this->t->lang === 'ar' ? 'القسم %s من %s' : 'Section %s of %s';
            $block['eyebrow'] = $style === 'tab'
                ? ($this->t->lang === 'ar' ? 'القسم ' . $number : 'Section ' . $number)
                : sprintf($of, str_pad($number, 2, '0', STR_PAD_LEFT), str_pad((string) $total, 2, '0', STR_PAD_LEFT));
            if ($style === 'rail' && $total <= 30) {
                $block['rail'] = ['current' => (int) $number, 'total' => $total];
            }
        }
        if ($note !== null && $note !== '') {
            $block['note'] = mb_substr($note, 0, 600);
        }
        if ($na !== null) {
            $block['notApplicable'] = $na;
        }

        return $block;
    }

    /**
     * @param list<array<mixed>> $content
     * @param array{key: ?string, title: string, description: string, fields: list<array<string, mixed>>} $section
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $answers
     */
    private function section(array &$content, array $section, array $fields, array $answers, int $number, int $total): void
    {
        $t = $this->t;
        $empty = $this->style['empty'];
        // A question a condition hid and nobody answered was never asked.
        $fields = array_values(array_filter($fields, fn (array $f): bool => ($f['visible_when'] ?? null) === null || !$this->isBlank($answers[(string) $f['field_key']] ?? null)));

        // FP.9: a section whose every answered question says N/A (the rest left
        // empty, as their "if yes, …" follow-ups are) prints as one line.
        $answered = array_values(array_filter($fields, fn (array $f): bool => !$this->isBlank($answers[(string) $f['field_key']] ?? null)));
        $allNa = $answered !== [] && array_filter($answered, fn (array $f): bool => !$this->isNa($f, $answers[(string) $f['field_key']] ?? null)) === [];
        if ($allNa && $empty !== 'printAll') {
            if ($empty === 'hide') {
                return;
            }
            $names = array_map(fn (array $f): string => self::numbered($t->pick($f['label'] ?? null))[1], array_slice($answered, 0, 6));
            $content[] = $this->sectionHeading((string) $number, $section['title'], $total, null, [
                'label' => $t->get('notApplicable'),
                'summary' => mb_substr($t->get('allNa', ['n' => count($answered), 'names' => implode(', ', $names) . (count($answered) > 6 ? '…' : '')]), 0, 600),
            ]);

            return;
        }

        if ($section['title'] !== '') {
            $content[] = $this->sectionHeading((string) $number, $section['title'], $total, $section['description']);
        }
        $prefix = $section['title'] !== '' ? $number . '.' : '';
        $q = 0;
        /** @var list<array{block: array<string, mixed>, text: string, value: string, word: string}> $run */
        $run = [];
        $flush = function () use (&$run, &$content): void {
            if (count($run) >= self::YES_NO_RUN || ($run !== [] && $this->style['yesNo'] === 'threeColumns')) {
                $content[] = $this->yesNoRows($run);
            } else {
                foreach ($run as $item) {
                    $content[] = $item['block'];
                }
            }
            $run = [];
        };
        foreach ($fields as $field) {
            $answer = $answers[(string) $field['field_key']] ?? null;
            if (($field['field_type'] ?? '') === FieldType::SIGNATURE) {
                $flush();
                $content[] = $this->signature($field, $answer, count(array_filter($fields, static fn (array $f): bool => ($f['field_type'] ?? '') === FieldType::SIGNATURE)) > 1);
                continue;
            }
            if ($this->isBlank($answer) && $empty === 'hide') {
                continue;
            }
            $q++;
            $block = $this->question($prefix . $q, $field, $answer);
            $isYesNo = ($block['answer']['kind'] ?? '') === 'yesNo' && !isset($block['guidance']);
            if ($isYesNo) {
                $run[] = ['block' => $block, 'text' => $block['text'], 'value' => $block['answer']['value'], 'word' => $block['answer']['word']];
                continue;
            }
            $flush();
            $content[] = $block;
        }
        $flush();
    }

    /**
     * @param list<array{block: array<string, mixed>, text: string, value: string, word: string}> $run
     * @return array<string, mixed>
     */
    private function yesNoRows(array $run): array
    {
        $t = $this->t;
        $rows = array_map(static fn (array $r): array => ['text' => $r['text'], 'value' => $r['value'], 'word' => $r['word']], $run);
        $answer = ['kind' => 'yesNoRows', 'rows' => $rows];
        if ($this->style['yesNo'] === 'threeColumns') {
            $answer['style'] = 'threeColumns';
            $answer['headers'] = ['item' => $t->get('item'), 'yes' => $t->get('yes'), 'no' => $t->get('no'), 'na' => $t->get('na')];
        }
        return ['type' => 'formQuestion', 'number' => '', 'text' => '', 'answer' => $answer];
    }

    /* ----------------------------------------------------------- questions */

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function question(string $number, array $field, mixed $answer): array
    {
        $t = $this->t;
        [$own, $text] = self::numbered($t->pick($field['label'] ?? null) ?: (string) $field['field_key']);
        // A label that carries its own number ("1.1 Project title", as forms
        // copied from a paper original do) prints with that number.
        $block = ['type' => 'formQuestion', 'number' => $own ?? $number, 'text' => mb_substr($text, 0, 600)];
        $guidance = $t->pick($field['help_text_i18n'] ?? null) ?: (string) ($field['help_text'] ?? '');
        if (!($field['is_required'] ?? false) && $this->isBlank($answer)) {
            $guidance = trim($guidance . ' ' . $t->get('optional'));
        }
        if (trim($guidance) !== '') {
            $block['guidance'] = mb_substr(trim($guidance), 0, 600);
        }
        $block['answer'] = $this->answer($field, $answer, $block);
        if ($this->style['questions'] === 'twoColumns' && !in_array($block['answer']['kind'], ['table', 'facts'], true)
            && !($block['answer']['kind'] === 'text' && count($block['answer']['paragraphs']) > 1)) {
            $block['layout'] = 'row';
        }

        return $block;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function answer(array $field, mixed $answer, array &$block): array
    {
        $t = $this->t;
        $type = (string) ($field['field_type'] ?? '');
        $options = is_array($field['options'] ?? null) ? array_values($field['options']) : [];

        if ($this->isBlank($answer)) {
            if (in_array($type, [FieldType::SELECT, FieldType::MULTISELECT], true) && $this->style['empty'] === 'printAll' && $this->style['choices'] === 'allOptions' && count($options) <= self::ALL_OPTIONS_MAX && $options !== []) {
                return ['kind' => 'choices', 'options' => array_map(fn (array $o): array => ['text' => $this->optionLabel($o), 'chosen' => false], $options)];
            }

            return ['kind' => 'empty', 'text' => $t->get('notAnswered')];
        }

        if ($type === FieldType::CHECKBOX || is_bool($answer)) {
            $yes = $answer === true || $answer === 'true' || $answer === 1 || $answer === '1';

            return ['kind' => 'yesNo', 'value' => $yes ? 'yes' : 'no', 'word' => $t->get($yes ? 'yes' : 'no')];
        }
        if ($type === FieldType::SELECT && $this->yesNoOptions($options)) {
            $kind = $this->yesNoValue($answer, $options) ?? 'na';

            return ['kind' => 'yesNo', 'value' => $kind, 'word' => $this->optionText($options, $answer) ?: $t->get($kind)];
        }
        if (in_array($type, [FieldType::SELECT, FieldType::MULTISELECT], true) && $options !== []) {
            $chosen = array_map('strval', is_array($answer) ? $answer : [$answer]);
            $all = $this->style['choices'] === 'allOptions' && count($options) <= self::ALL_OPTIONS_MAX;
            $list = [];
            foreach ($options as $o) {
                $on = in_array((string) ($o['value'] ?? ''), $chosen, true);
                if ($all || $on) {
                    $list[] = ['text' => mb_substr($this->optionLabel($o), 0, 300), 'chosen' => $on];
                }
            }
            if ($list === []) {
                return ['kind' => 'text', 'paragraphs' => [implode(', ', $chosen)]];
            }

            return ['kind' => 'choices', 'options' => array_slice($list, 0, 60)];
        }
        if ($type === FieldType::REPEATING && is_array($answer)) {
            return $this->table($field, $answer);
        }
        if ($type === FieldType::FILE) {
            return ['kind' => 'text', 'paragraphs' => [$t->files(is_array($answer) ? count($answer) : 1)]];
        }
        if ($type === FieldType::PROFILE_REF || $type === FieldType::OU_REF) {
            $ids = is_array($answer) ? $answer : [$answer];
            $names = [];
            foreach ($ids as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $names[] = ($this->names)($type === FieldType::PROFILE_REF ? 'profile' : 'ou', (int) $id) ?? '—';
                }
            }

            return ['kind' => 'text', 'paragraphs' => [implode(', ', $names) ?: '—']];
        }
        if ($type === FieldType::DATE && is_string($answer)) {
            return ['kind' => 'text', 'paragraphs' => [$t->date($answer)]];
        }
        if (is_int($answer) || is_float($answer)) {
            return ['kind' => 'text', 'paragraphs' => [(string) $answer]];
        }

        $text = is_string($answer) ? $answer : (string) json_encode($answer, JSON_UNESCAPED_UNICODE);
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R+/u', $text) ?: [$text]), static fn (string $p): bool => $p !== ''));
        $paragraphs = array_slice(array_map(static fn (string $p): string => mb_substr($p, 0, 20000), $paragraphs), 0, 400);
        $out = ['kind' => 'text', 'paragraphs' => $paragraphs === [] ? [$text] : $paragraphs];
        if ($this->style['longAnswers'] === 'countAndContinued' && $type === FieldType::TEXTAREA) {
            $block['continued'] = $t->get('continued');
            $max = is_array($field['validation'] ?? null) ? (int) ($field['validation']['maxWords'] ?? 0) : 0;
            if ($max > 0) {
                $out['wordCount'] = $t->get('wordsOf', ['n' => self::words($text), 'max' => $max]);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<mixed> $rows
     * @return array<string, mixed>
     */
    private function table(array $field, array $rows): array
    {
        $t = $this->t;
        $columns = is_array($field['validation']['columns'] ?? null) ? array_slice($field['validation']['columns'], 0, 8) : [];
        if ($columns === []) {
            return ['kind' => 'text', 'paragraphs' => [$t->get('notAnswered')]];
        }
        $heads = array_map(fn ($c): string => is_array($c) ? ($t->pick($c['label'] ?? null) ?: (string) ($c['key'] ?? '')) : '', $columns);
        $out = [];
        foreach (array_slice($rows, 0, 200) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($columns as $c) {
                $v = is_array($c) ? ($row[(string) ($c['key'] ?? '')] ?? null) : null;
                $cells[] = is_bool($v) ? $t->get($v ? 'yes' : 'no') : (is_scalar($v) ? mb_substr((string) $v, 0, 500) : '');
            }
            $out[] = $cells;
        }

        return ['kind' => 'table', 'columns' => $heads, 'rows' => $out];
    }

    /* ---------------------------------------------------------- signatures */

    /**
     * FP.7: "Confirmed in Whity" with the date and time, beside the signature
     * drawn on a touch screen; none drawn, a dashed line.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function signature(array $field, mixed $answer, bool $several): array
    {
        $t = $this->t;
        $style = match ($this->style['signatures']) {
            'confirmedOnly' => 'confirmedOnly',
            'withPaperLines' => 'paper',
            default => 'drawn',
        };
        $label = $t->pick($field['label'] ?? null);
        $name = is_array($answer) ? trim((string) ($answer['name'] ?? '')) : (is_string($answer) ? trim($answer) : '');
        $row = ['type' => 'signatureRow', 'name' => mb_substr($name !== '' ? $name : $label, 0, 200), 'style' => $style];
        if ($several || $name === '') {
            $row['role'] = mb_substr($label, 0, 200);
        }
        if ($name !== '' && is_array($answer) && ($answer['signed_at'] ?? '') !== '') {
            $row['confirmed'] = $t->get('confirmed', ['at' => $t->date((string) $answer['signed_at'], true)]);
        } else {
            $row['waiting'] = $t->get('notConfirmed');
        }
        $drawing = is_array($answer) ? ($answer['drawing'] ?? null) : null;
        if (is_array($drawing) && is_array($drawing['paths'] ?? null) && is_array($drawing['viewBox'] ?? null)) {
            $row['drawing'] = ['viewBox' => array_values($drawing['viewBox']), 'paths' => array_values($drawing['paths'])];
        } else {
            $row['noDrawing'] = $t->get('noDrawing');
        }
        if ($style === 'paper') {
            $row['paper'] = ['signature' => $t->get('signature', ['name' => $row['name']]), 'date' => $t->get('date')];
        }

        return $row;
    }

    /**
     * The questions the office answers, with who answered and when.
     *
     * @param list<array<mixed>> $content
     * @param list<array<string, mixed>> $fields
     * @param array<string, array{value: mixed, by?: ?string, at?: ?string}> $office
     */
    private function officialUse(array &$content, array $fields, array $office): void
    {
        $t = $this->t;
        $content[] = $this->sectionHeading('i', $t->get('officeUse'), null);
        foreach ($fields as $field) {
            $entry = $office[(string) $field['field_key']] ?? null;
            $label = mb_substr(self::numbered($t->pick($field['label'] ?? null) ?: (string) $field['field_key'])[1], 0, 600);
            if ($entry === null || $this->isBlank($entry['value'])) {
                $content[] = ['type' => 'formQuestion', 'number' => '', 'text' => $label, 'answer' => ['kind' => 'empty', 'text' => $t->get('notAnswered')]];
                continue;
            }
            $scratch = [];
            $answer = $this->answer($field, $entry['value'], $scratch);
            $value = match ($answer['kind']) {
                'yesNo' => $answer['word'],
                'choices' => implode(', ', array_map(static fn (array $o): string => $o['text'], array_filter($answer['options'], static fn (array $o): bool => $o['chosen']))),
                'text' => implode(' ', $answer['paragraphs']),
                default => '',
            };
            if ($value === '') {
                $content[] = ['type' => 'formQuestion', 'number' => '', 'text' => $label, 'answer' => $answer];
                continue;
            }
            $items = [['label' => $t->get('answer'), 'value' => mb_substr($value, 0, 500)]];
            if (($entry['by'] ?? '') !== '') {
                $items[] = ['label' => $t->get('by'), 'value' => (string) $entry['by']];
            }
            if (($entry['at'] ?? '') !== '') {
                $items[] = ['label' => $t->get('date'), 'value' => $t->date((string) $entry['at'])];
            }
            $content[] = ['type' => 'formQuestion', 'number' => '', 'text' => $label, 'answer' => ['kind' => 'facts', 'items' => $items]];
        }
    }

    /* ------------------------------------------------------------- helpers */

    private function isBlank(mixed $answer): bool
    {
        return $answer === null || $answer === '' || $answer === [] || (is_string($answer) && trim($answer) === '');
    }

    /** @param array<string, mixed> $field */
    private function isNa(array $field, mixed $answer): bool
    {
        $options = is_array($field['options'] ?? null) ? array_values($field['options']) : [];
        if (is_array($answer) || $this->isBlank($answer)) {
            return false;
        }
        if (in_array(mb_strtolower(trim((string) $answer)), self::NA_WORDS, true)) {
            return true;
        }

        return in_array(mb_strtolower($this->optionText($options, $answer)), self::NA_WORDS, true);
    }

    /** @param list<mixed> $options */
    private function yesNoOptions(array $options): bool
    {
        if (count($options) < 2 || count($options) > 3) {
            return false;
        }
        $kinds = [];
        foreach ($options as $o) {
            $kind = is_array($o) ? $this->kindOf($o) : null;
            if ($kind === null) {
                return false;
            }
            $kinds[$kind] = true;
        }

        return isset($kinds['yes'], $kinds['no']);
    }

    /** @param list<mixed> $options */
    private function yesNoValue(mixed $answer, array $options): ?string
    {
        foreach ($options as $o) {
            if (is_array($o) && (string) ($o['value'] ?? '') === (string) $answer) {
                return $this->kindOf($o);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $option */
    private function kindOf(array $option): ?string
    {
        $words = [mb_strtolower(trim((string) ($option['value'] ?? '')))];
        $label = $option['label'] ?? null;
        foreach (is_array($label) ? $label : [$label] as $l) {
            if (is_string($l)) {
                $words[] = mb_strtolower(trim($l));
            }
        }
        foreach ($words as $w) {
            if (in_array($w, self::YES_WORDS, true)) {
                return 'yes';
            }
            if (in_array($w, self::NO_WORDS, true)) {
                return 'no';
            }
            if (in_array($w, self::NA_WORDS, true)) {
                return 'na';
            }
        }

        return null;
    }

    /** @param list<mixed> $options */
    private function optionText(array $options, mixed $answer): string
    {
        foreach ($options as $o) {
            if (is_array($o) && (string) ($o['value'] ?? '') === (string) $answer) {
                return $this->optionLabel($o);
            }
        }

        return is_scalar($answer) ? (string) $answer : '';
    }

    /** @param array<string, mixed> $option */
    private function optionLabel(array $option): string
    {
        return $this->t->pick($option['label'] ?? null) ?: (string) ($option['value'] ?? '');
    }

    /**
     * A label's own leading number, and the label without it.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function numbered(string $label): array
    {
        if (preg_match('/^\s*(\d{1,3}(?:\.\d{1,3}){0,3})\.?\s+(\S.*)$/su', $label, $m) === 1) {
            return [$m[1], trim($m[2])];
        }

        return [null, $label];
    }

    private static function humanise(string $key): string
    {
        $words = trim(str_replace(['_', '-'], ' ', $key));

        return $words === '' ? $key : mb_strtoupper(mb_substr($words, 0, 1)) . mb_substr($words, 1);
    }

    /** Words as a reader counts them: runs of letters and digits, in any script. */
    public static function words(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}\'’-]*/u', $text);
    }
}
