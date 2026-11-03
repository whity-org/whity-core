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
 * THE TOKENS: `{YYYY}`, `{YY}`, `{MM}`, `{SEQ}` and `{SEQ:n}`, zero-padded to n digits.
 * Any other brace is refused, so a typo like `{YEAR}` is an error an author sees
 * rather than text printed on every reference.
 *
 * THE COUNTER FOLLOWS THE TEXT. {@see counterName()} hashes the format with its dates
 * filled in, so a format holding `{YYYY}` restarts each year and one without never
 * does. Two forms using the same format share a sequence, which is what stops them
 * producing the same reference. It is unique and monotonic, not gapless, for the
 * reason `SequenceAllocator` gives.
 *
 * Dates are UTC: the moment is the server's, and a workspace's midnight is not
 * something this class can see.
 *
 * Stateless — worker-safe.
 */
final class FormReferenceFormat
{
    /** The format as an author types it. `form_submissions.reference` allows for padding. */
    public const FORMAT_MAX = 64;

    public const REFERENCE_MAX = 128;

    private const TOKEN = '/\{(YYYY|YY|MM|SEQ(?::([1-9]|1[0-2]))?)\}/';

    private const SEQUENCE = '/\{SEQ(?::[0-9]+)?\}/';

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

        $rest = (string) preg_replace(self::TOKEN, '', $format);
        if (str_contains($rest, '{') || str_contains($rest, '}')) {
            throw new FormRejectedException(
                'reference_format may only use {YYYY}, {YY}, {MM}, {SEQ} and {SEQ:n} with n from 1 to 12'
            );
        }

        return $format;
    }

    /**
     * Which sequence this format draws from at this moment.
     */
    public static function counterName(string $format, \DateTimeImmutable $at): string
    {
        $scope = (string) preg_replace(self::SEQUENCE, '{SEQ}', self::withDates($format, $at));

        return 'form-reference:' . md5($scope);
    }

    public static function render(string $format, int $sequence, \DateTimeImmutable $at): string
    {
        return (string) preg_replace_callback(
            self::SEQUENCE,
            static function (array $match) use ($sequence): string {
                $width = (int) substr((string) strstr($match[0], ':'), 1, -1);

                return str_pad((string) $sequence, max($width, 1), '0', STR_PAD_LEFT);
            },
            self::withDates($format, $at),
        );
    }

    private static function withDates(string $format, \DateTimeImmutable $at): string
    {
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));

        return strtr($format, [
            '{YYYY}' => $utc->format('Y'),
            '{YY}' => $utc->format('y'),
            '{MM}' => $utc->format('m'),
        ]);
    }
}
