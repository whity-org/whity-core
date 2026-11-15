<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * The reference a submission is given, in the wording its workspace chose:
 * `forms.reference_format` (migration 196), for example `REC-{YYYY}-{SEQ:4}`.
 *
 * A reference is what somebody quotes on the phone or on a letter, and every office
 * that numbers things already has a shape for it, so the shape is theirs. It needs
 * only a sequence, because without one two submissions read the same. As with
 * {@see \Whity\Core\Convening\DecisionNumbers}, any other text is allowed, Arabic
 * included.
 *
 * THE TOKENS
 *  - `{YYYY}`, `{YY}`, `{MM}`: the date, UTC.
 *  - `{SEQ}` and `{SEQ:n}`, zero-padded to n digits. Exactly one.
 *  - `{AY:m}` (WHIT-1017): the academic year starting in month m, as `2026-2027`.
 *    With m = 9, 3 Sep 2026 and 10 Jun 2027 both read `2026-2027`.
 *  - `{ANSWER:key}` (WHIT-1017): the submission's answer to question `key`, one
 *    line of at most {@see ANSWER_MAX} characters. With a list,
 *    `{ANSWER:subject|human=IRB-AAU|animal=AUB-AAU}`, the answer is looked up in
 *    it, and `*=…` gives the text for any other answer; an answer the list does
 *    not name, with no `*`, prints as it is. A missing answer prints nothing.
 *  - `{STEP:key}` (WHIT-1017): a value a reviewer entered at a flow step
 *    (`route.value.<key>`, WHIT-1016). Only on a form whose references are given
 *    on approval, because nothing has been entered when a form is sent.
 * Any other brace is refused, so a typo like `{YEAR}` is an error an author sees
 * rather than text printed on every reference.
 *
 * THE COUNTER FOLLOWS THE TEXT. {@see counterName()} hashes the format with its
 * dates, academic year and answers filled in, so a format holding `{YYYY}` or
 * `{AY:9}` restarts each year, one without never does, and
 * `{ANSWER:subject|human=IRB|animal=AUB} {SEQ}` keeps a separate series for each
 * prefix. A step value does NOT start a new series: a meeting number is part of
 * the text, not of the count. Two forms using the same format share a sequence,
 * which is what stops them producing the same reference. It is unique and
 * monotonic, not gapless, for the reason `SequenceAllocator` gives.
 *
 * Dates are UTC: the moment is the server's, and a workspace's midnight is not
 * something this class can see.
 *
 * Stateless — worker-safe.
 */
final class FormReferenceFormat
{
    /** The format as an author types it (migration 268 widened the column to 160). */
    public const FORMAT_MAX = 160;

    /** `form_submissions.reference`. A format that renders longer is refused when used. */
    public const REFERENCE_MAX = 128;

    /** The longest answer or step value a reference prints, so one long answer can't swallow it. */
    public const ANSWER_MAX = 40;

    private const SIMPLE = '/\{(YYYY|YY|MM|SEQ(?::([1-9]|1[0-2]))?|AY:([1-9]|1[0-2]))\}/';

    private const SEQUENCE = '/\{SEQ(?::[0-9]+)?\}/';

    private const ACADEMIC_YEAR = '/\{AY:([0-9]+)\}/';

    /** `{ANSWER:key}` or `{ANSWER:key|value=text|…}`. Group 1 is the key, group 2 the list. */
    private const ANSWER = '/\{ANSWER:([a-z][a-z0-9_]{0,127})((?:\|[^|{}=]+=[^|{}=]*)*)\}/u';

    private const STEP = '/\{STEP:([a-z][a-z0-9_]{0,39})\}/';

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Normalize a format from a request body. Null or blank means no references.
     *
     * @throws FormRejectedException With a sentence naming what is wrong.
     */
    public static function parse(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            throw new FormRejectedException('reference_format must be text or null');
        }

        $format = trim($raw);
        if ($format === '') {
            return null;
        }
        if (mb_strlen($format) > self::FORMAT_MAX) {
            throw new FormRejectedException('reference_format must be ' . self::FORMAT_MAX . ' characters or fewer');
        }
        // Control characters break every letter and CSV a reference is pasted into.
        // Format characters stay, because they are how mixed Arabic and Latin text
        // renders in the order its author meant.
        if (preg_match('/[\p{Cc}]/u', $format) !== 0) {
            throw new FormRejectedException('reference_format must be one line of plain text');
        }

        $sequences = preg_match_all(self::SEQUENCE, $format);
        if ($sequences !== 1) {
            throw new FormRejectedException('reference_format must contain {SEQ} exactly once');
        }

        $rest = (string) preg_replace([self::SIMPLE, self::ANSWER, self::STEP], '', $format);
        if (str_contains($rest, '{') || str_contains($rest, '}')) {
            throw new FormRejectedException(
                'reference_format may only use {YYYY}, {YY}, {MM}, {SEQ}, {SEQ:n} with n from 1 to 12, '
                . '{AY:m} with m the month the academic year starts, {ANSWER:question} or '
                . '{ANSWER:question|answer=text|*=text}, and {STEP:value}'
            );
        }

        return $format;
    }

    /** Whether the format prints a value entered at a flow step, so it can only be given on approval. */
    public static function usesStepValues(string $format): bool
    {
        return preg_match(self::STEP, $format) === 1;
    }

    /**
     * Which sequence this format draws from at this moment, for these answers.
     *
     * A format with no answer, academic year or step token names the same
     * counter it always has, so existing series carry on.
     *
     * @param array<string, mixed> $answers The submission's answers by question key.
     */
    public static function counterName(string $format, \DateTimeImmutable $at, array $answers = []): string
    {
        $scope = self::withAnswers(self::withDates($format, $at), $answers);
        // A step value is part of the text, not of the count.
        $scope = (string) preg_replace(self::STEP, '{STEP}', $scope);
        $scope = (string) preg_replace(self::SEQUENCE, '{SEQ}', $scope);

        return 'form-reference:' . md5($scope);
    }

    /**
     * @param array<string, mixed>  $answers    The submission's answers by question key.
     * @param array<string, string> $stepValues Values entered at flow steps, by key.
     */
    public static function render(
        string $format,
        int $sequence,
        \DateTimeImmutable $at,
        array $answers = [],
        array $stepValues = [],
    ): string {
        $text = self::withAnswers(self::withDates($format, $at), $answers);
        $text = (string) preg_replace_callback(
            self::STEP,
            static fn (array $match): string => self::clean($stepValues[$match[1]] ?? ''),
            $text,
        );

        return (string) preg_replace_callback(
            self::SEQUENCE,
            static function (array $match) use ($sequence): string {
                $width = (int) substr((string) strstr($match[0], ':'), 1, -1);

                return str_pad((string) $sequence, max($width, 1), '0', STR_PAD_LEFT);
            },
            $text,
        );
    }

    private static function withDates(string $format, \DateTimeImmutable $at): string
    {
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));

        $text = strtr($format, [
            '{YYYY}' => $utc->format('Y'),
            '{YY}' => $utc->format('y'),
            '{MM}' => $utc->format('m'),
        ]);

        return (string) preg_replace_callback(
            self::ACADEMIC_YEAR,
            static function (array $match) use ($utc): string {
                $year = (int) $utc->format('Y');
                $start = $year - ((int) $utc->format('n') < (int) $match[1] ? 1 : 0);

                return $start . '-' . ($start + 1);
            },
            $text,
        );
    }

    /**
     * @param array<string, mixed> $answers
     */
    private static function withAnswers(string $text, array $answers): string
    {
        return (string) preg_replace_callback(
            self::ANSWER,
            static function (array $match) use ($answers): string {
                $answer = self::answerText($answers[$match[1]] ?? null);
                if ($match[2] === '') {
                    return $answer;
                }
                $list = [];
                foreach (explode('|', substr($match[2], 1)) as $pair) {
                    [$value, $printed] = explode('=', $pair, 2);
                    $list[trim($value)] = trim($printed);
                }
                if ($answer !== '' && array_key_exists($answer, $list)) {
                    return $list[$answer];
                }

                return $list['*'] ?? $answer;
            },
            $text,
        );
    }

    /** An answer as the text a reference can hold: one short line, or nothing. */
    private static function answerText(mixed $answer): string
    {
        if (is_bool($answer)) {
            return $answer ? 'yes' : 'no';
        }
        if (is_int($answer) || is_float($answer)) {
            return (string) $answer;
        }

        return is_string($answer) ? self::clean($answer) : '';
    }

    private static function clean(string $value): string
    {
        $one = trim((string) preg_replace('/[\p{Cc}{}]+/u', ' ', $value));

        return mb_substr($one, 0, self::ANSWER_MAX);
    }
}
