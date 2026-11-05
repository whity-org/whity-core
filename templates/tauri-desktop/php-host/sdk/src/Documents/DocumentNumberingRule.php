<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * How documents of one kind are numbered (SDK 1.65, WHIT-684): when a number is
 * handed out, and what it looks like. A slot declares it, or a template carries
 * its own; the template's wins, so a workspace can change a plugin's default.
 *
 * `assign` is `on_issue` (when the document is raised) or `on_final_approval`
 * (when its route ends approved; until then the document is a draft with no
 * number). `pattern` uses the tokens forms use for references: `{YYYY}`, `{YY}`,
 * `{MM}`, and `{SEQ}` or `{SEQ:n}` zero-padded to n digits, exactly once. Anything
 * else is literal text, Arabic included: `INV-{YYYY}-{SEQ:4}`.
 *
 * The sequence follows the pattern with its dates filled in, so a pattern with
 * `{YYYY}` restarts each year and one without never does, and two kinds with the
 * same pattern share a series rather than repeat a number.
 */
final class DocumentNumberingRule
{
    public const ON_ISSUE = 'on_issue';

    public const ON_FINAL_APPROVAL = 'on_final_approval';

    public const ASSIGN = [self::ON_ISSUE, self::ON_FINAL_APPROVAL];

    public const PATTERN_MAX = 64;

    private const TOKEN = '/\{(YYYY|YY|MM|SEQ(?::([1-9]|1[0-2]))?)\}/';

    private const SEQUENCE = '/\{SEQ(?::[0-9]+)?\}/';

    public function __construct(
        public readonly string $pattern,
        public readonly string $assign = self::ON_ISSUE,
    ) {
        $problem = self::patternProblem($pattern);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        if (!in_array($assign, self::ASSIGN, true)) {
            throw new \InvalidArgumentException('numbering.assign must be one of: ' . implode(', ', self::ASSIGN));
        }
    }

    /**
     * From a manifest or a stored setting: `{pattern, assign?}`.
     *
     * @throws \InvalidArgumentException With a sentence naming what is wrong.
     */
    public static function fromArray(mixed $value): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('numbering must be an object with a pattern and optionally assign');
        }
        $unknown = array_diff(array_keys($value), ['pattern', 'assign']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("numbering: unknown key '" . (string) reset($unknown) . "'");
        }
        $pattern = $value['pattern'] ?? null;
        if (!is_string($pattern)) {
            throw new \InvalidArgumentException('numbering.pattern is required, as text');
        }
        $assign = $value['assign'] ?? self::ON_ISSUE;
        if (!is_string($assign)) {
            throw new \InvalidArgumentException('numbering.assign must be one of: ' . implode(', ', self::ASSIGN));
        }

        return new self(trim($pattern), $assign);
    }

    /** Why a manifest's or a request's `{pattern, assign?}` can't be used, or null when it can. */
    public static function problem(mixed $value): ?string
    {
        try {
            self::fromArray($value);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** Why a pattern can't be used, or null when it can. */
    public static function patternProblem(string $pattern): ?string
    {
        if ($pattern === '') {
            return 'numbering.pattern must not be empty';
        }
        if (mb_strlen($pattern) > self::PATTERN_MAX) {
            return 'numbering.pattern must be ' . self::PATTERN_MAX . ' characters or fewer';
        }
        if (preg_match('/[\p{Cc}]/u', $pattern) !== 0) {
            return 'numbering.pattern must be one line of plain text';
        }
        if (preg_match_all(self::SEQUENCE, $pattern) !== 1) {
            return 'numbering.pattern must contain {SEQ} exactly once';
        }
        $rest = (string) preg_replace(self::TOKEN, '', $pattern);
        if (str_contains($rest, '{') || str_contains($rest, '}')) {
            return 'numbering.pattern may only use {YYYY}, {YY}, {MM}, {SEQ} and {SEQ:n} with n from 1 to 12';
        }

        return null;
    }

    /** @return array{pattern: string, assign: string} */
    public function toArray(): array
    {
        return ['pattern' => $this->pattern, 'assign' => $this->assign];
    }
}
