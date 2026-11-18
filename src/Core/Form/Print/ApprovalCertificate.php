<?php

declare(strict_types=1);

namespace Whity\Core\Form\Print;

/**
 * The approval certificate: one page printed in front of an approved form
 * response's printout, so the PDF a route issues opens on the decision rather
 * than on page one of the application.
 *
 * Top to bottom: the workspace letterhead, centred, with its logo or crest;
 * the certificate's title; a sentence certifying the decision; the subject
 * (the form's project-title answer, not the form's name); the reference,
 * decision, decided date and conditions; the people the route template names
 * (the applicant, the student…); the approvals, one row per stage; and the
 * verification QR with its reference when the workspace prints QR codes.
 *
 * Every heading is unnumbered, and every text is capped so the page holds in
 * both languages: Whity Render refuses a cover that runs over its page
 * (`coverPages`), so a certificate that did not fit would cost the document.
 *
 * What it prints is set on the route template's final document stage, as
 * `rule_config.certificate` ({@see self::config()}); everything has a default.
 *
 * Pure: everything it needs comes in through {@see build()}.
 */
final class ApprovalCertificate
{
    /** The decisions a certificate is printed for. */
    public const STATUSES = ['approved', 'conditional'];

    public const TITLE_MAX = 80;
    public const ISSUER_MAX = 160;
    public const SUBJECT_MAX = 160;
    public const CONDITIONS_MAX = 150;
    public const ROWS_MAX = 4;
    public const ROW_VALUE_MAX = 64;
    public const APPROVALS_MAX = 6;

    /** Field keys taken for the subject when the template names none. */
    private const SUBJECT_KEYS = ['project_title', 'title', 'subject'];

    /**
     * The stage's `certificate` options, checked: what an author may set.
     *
     * - `title`: {en, ar} or a string; default "Certificate of approval".
     * - `issuer`: {en, ar} or a string, who certifies; default the workspace's name.
     * - `subject`: the question whose answer is the subject; default a question
     *   keyed `project_title`, `title` or `subject`, else the form's title.
     * - `rows`: up to four people or facts, each `{label, field}` or
     *   `{label, fields: [from, to]}` (a period); default the person who submitted.
     *
     * @return array{title?: array<string, string>, issuer?: array<string, string>, subject?: string, rows?: list<array{label: array<string, string>, fields: list<string>}>}
     *
     * @throws \InvalidArgumentException with a message for the template's author
     */
    public static function config(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new \InvalidArgumentException("'certificate' must be an object.");
        }
        $unknown = array_diff(array_keys($raw), ['title', 'issuer', 'subject', 'rows']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("'certificate' has no option '" . (string) reset($unknown) . "'; use title, issuer, subject or rows.");
        }
        $out = [];
        foreach (['title' => self::TITLE_MAX, 'issuer' => self::ISSUER_MAX] as $key => $max) {
            if (isset($raw[$key])) {
                $out[$key] = self::text($raw[$key], "'certificate.{$key}'", $max);
            }
        }
        if (isset($raw['subject'])) {
            $out['subject'] = self::fieldKey($raw['subject'], "'certificate.subject'");
        }
        if (isset($raw['rows'])) {
            if (!is_array($raw['rows']) || !array_is_list($raw['rows']) || count($raw['rows']) > self::ROWS_MAX) {
                throw new \InvalidArgumentException("'certificate.rows' must be a list of up to " . self::ROWS_MAX . ' rows.');
            }
            $out['rows'] = [];
            foreach ($raw['rows'] as $i => $row) {
                $at = "'certificate.rows[" . $i . "]'";
                if (!is_array($row)) {
                    throw new \InvalidArgumentException("{$at} must be {label, field} or {label, fields}.");
                }
                $fields = isset($row['fields']) ? $row['fields'] : (isset($row['field']) ? [$row['field']] : null);
                if (!is_array($fields) || $fields === [] || count($fields) > 2 || !array_is_list($fields)) {
                    throw new \InvalidArgumentException("{$at} must name one question (field) or two (fields: from, to).");
                }
                $out['rows'][] = [
                    'label' => self::text($row['label'] ?? null, "{$at}.label", 80),
                    'fields' => array_map(static fn (mixed $f): string => self::fieldKey($f, "{$at}.field"), $fields),
                ];
            }
        }

        return $out;
    }

    /**
     * The certificate's blocks, or [] when the decision is not one a
     * certificate is printed for.
     *
     * @param array{
     *   config: array<string, mixed>,
     *   workspace: array{name: string, lines?: list<string>, logo?: ?string, nameAr?: ?string, linesAr?: list<string>},
     *   formTitle: string,
     *   fields: list<array<string, mixed>>,
     *   answers: array<string, mixed>,
     *   reference: string,
     *   submittedBy?: ?string,
     *   decision: array<string, mixed>,
     *   verify?: ?array{url: string, reference?: ?string},
     * } $in
     * @param \Closure(array<string, mixed>, mixed): string $display an answer as one line of text
     * @return list<array<string, mixed>>
     */
    public function build(array $in, FormPrintLabels $t, \Closure $display): array
    {
        $decision = $in['decision'];
        $status = (string) ($decision['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            return [];
        }
        $config = $in['config'];
        $workspace = $in['workspace'];
        $nameAr = trim((string) ($workspace['nameAr'] ?? ''));
        $arabic = $t->lang === 'ar' && $nameAr !== '';
        $name = $arabic ? $nameAr : $workspace['name'];
        $lines = $arabic ? ($workspace['linesAr'] ?? []) : ($workspace['lines'] ?? []);
        $lines = array_values(array_filter(array_map('strval', $lines), static fn (string $l): bool => trim($l) !== ''));

        $start = ['name' => $name];
        if (($workspace['logo'] ?? null) !== null) {
            $start['image'] = ['src' => (string) $workspace['logo'], 'alt' => $name, 'heightMm' => 14];
        }
        if ($lines !== []) {
            $start['lines'] = [implode(' · ', array_slice($lines, 0, 2))];
        }
        $blocks = [['type' => 'letterhead', 'layout' => 'centred', 'start' => $start]];

        $title = self::clip($t->pick($config['title'] ?? null), self::TITLE_MAX) ?: $t->get('certificate');
        $blocks[] = ['type' => 'heading', 'level' => 1, 'unnumbered' => true, 'inContents' => false, 'text' => $title];

        $issuer = self::clip($t->pick($config['issuer'] ?? null), self::ISSUER_MAX) ?: $name;
        $certify = $t->get($status === 'conditional' ? 'certifyConditional' : 'certifyApproved', ['issuer' => $issuer]);
        if (($in['verify'] ?? null) !== null) {
            $certify .= ' ' . $t->get('certifyVerify');
        }
        $blocks[] = ['type' => 'paragraph', 'text' => $certify];

        $fields = [];
        foreach ($in['fields'] as $field) {
            $fields[(string) ($field['field_key'] ?? '')] = $field;
        }
        $subject = $this->subject($config['subject'] ?? null, $fields, $in['answers'], $display);
        $blocks[] = ['type' => 'heading', 'level' => 3, 'unnumbered' => true, 'inContents' => false,
            'text' => self::clip($subject !== '' ? $subject : $in['formTitle'], self::SUBJECT_MAX) ?: $title];

        // One block: the decision in large type, the facts and the people as
        // rows, and the verification QR beside them.
        $rows = [];
        if ($in['reference'] !== '') {
            $rows[] = ['label' => $t->get('reference'), 'value' => $in['reference'], 'mono' => true];
        }
        if (($decision['decidedAt'] ?? null) !== null) {
            $rows[] = ['label' => $t->get('decided'), 'value' => $t->date((string) $decision['decidedAt'])];
        }
        $conditions = trim((string) ($decision['conditions'] ?? ''));
        // Long conditions print in full in the decision at the end of the
        // printout; the certificate says so rather than run over its page.
        $conditions = self::clip($conditions, PHP_INT_MAX);
        $rows[] = ['label' => $t->get('conditions'), 'value' => $conditions === '' ? $t->get('none')
            : (mb_strlen($conditions) > self::CONDITIONS_MAX ? self::clip($conditions, self::CONDITIONS_MAX) . ' ' . $t->get('conditionsInFull') : $conditions)];
        foreach ($this->rows($config['rows'] ?? null, $fields, $in['answers'], $display, $t, (string) ($in['submittedBy'] ?? '')) as [$label, $value]) {
            $rows[] = ['label' => $label, 'value' => $value];
        }
        $block = ['type' => 'decisionBlock', 'status' => $status, 'word' => self::clip((string) ($decision['word'] ?? $t->get($status)), 40), 'rows' => $rows];
        $verify = $in['verify'] ?? null;
        if ($verify !== null) {
            $block['qr'] = ['value' => $verify['url'], 'caption' => $t->get('scanToVerify')];
            if (($verify['reference'] ?? '') !== '') {
                $block['qr']['reference'] = (string) $verify['reference'];
            }
        }
        $blocks[] = $block;

        // One line per stage: what it decided, who, and when.
        $approvals = [];
        foreach (array_slice($decision['approvals'] ?? [], -self::APPROVALS_MAX) as $a) {
            $approvals[] = array_filter([
                'status' => 'approved',
                'word' => self::clip((string) ($a['outcome'] ?? ''), 24) ?: $t->get('approvedWord'),
                'name' => self::clip((string) ($a['name'] ?? ''), 30) ?: '—',
                'role' => self::clip((string) ($a['role'] ?? ''), 40) ?: null,
                'at' => $t->date($a['at'] ?? null, true) ?: null,
            ], static fn (?string $v): bool => $v !== null);
        }
        if ($approvals !== []) {
            $blocks[] = ['type' => 'approvals', 'title' => $t->get('approvals'), 'rows' => $approvals];
        }

        return $blocks;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $answers
     */
    private function subject(mixed $key, array $fields, array $answers, \Closure $display): string
    {
        $keys = is_string($key) && $key !== '' ? [$key] : self::SUBJECT_KEYS;
        foreach ($keys as $k) {
            if (isset($fields[$k]) && array_key_exists($k, $answers)) {
                $text = trim($display($fields[$k], $answers[$k]));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $answers
     * @return list<array{0: string, 1: string}>
     */
    private function rows(mixed $config, array $fields, array $answers, \Closure $display, FormPrintLabels $t, string $submittedBy): array
    {
        if (!is_array($config) || $config === []) {
            return $submittedBy !== '' ? [[$t->get('applicant'), self::clip($submittedBy, self::ROW_VALUE_MAX)]] : [];
        }
        $out = [];
        foreach (array_slice($config, 0, self::ROWS_MAX) as $row) {
            $values = [];
            foreach ((array) ($row['fields'] ?? []) as $key) {
                $key = (string) $key;
                $values[] = isset($fields[$key]) && array_key_exists($key, $answers) ? trim($display($fields[$key], $answers[$key])) : '';
            }
            $value = count($values) === 2 && $values[0] !== '' && $values[1] !== ''
                ? $t->get('to', ['from' => $values[0], 'to' => $values[1]])
                : implode('', array_filter($values, static fn (string $v): bool => $v !== ''));
            if ($value === '') {
                continue;
            }
            $label = $t->pick($row['label'] ?? null);
            $out[] = [self::clip($label, 80), self::clip($value, self::ROW_VALUE_MAX)];
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function text(mixed $value, string $at, int $max): array
    {
        if (is_string($value)) {
            $value = ['en' => $value];
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$at} must be text, or {en, ar}.");
        }
        $out = [];
        foreach ($value as $lang => $text) {
            if (!in_array($lang, ['en', 'ar'], true) || !is_string($text) || mb_strlen($text) > $max) {
                throw new \InvalidArgumentException("{$at} must be {en, ar} text of {$max} characters or fewer.");
            }
            if (trim($text) !== '') {
                $out[$lang] = trim($text);
            }
        }
        if ($out === []) {
            throw new \InvalidArgumentException("{$at} must not be empty.");
        }

        return $out;
    }

    private static function fieldKey(mixed $value, string $at): string
    {
        if (!is_string($value) || preg_match('/^[a-z0-9_\-]{1,128}$/i', $value) !== 1) {
            throw new \InvalidArgumentException("{$at} must be a question's key.");
        }

        return $value;
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
