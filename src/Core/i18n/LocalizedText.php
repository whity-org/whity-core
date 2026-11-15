<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

/**
 * Text that people write in more than one language: one object of language
 * code to text, such as `{"ar": "مجلس المعايير", "en": "Standards Board"}`.
 *
 * This is the one bilingual text model in core (WHIT-1038). Forms, calendar
 * services and rooms, convening bodies and meetings keep the object in a JSON
 * column; workspaces and units keep the original in their own column and the
 * other languages in `localized_texts` ({@see LocalizedTexts}). On the wire the
 * object travels whole: the client knows its reader, so it picks.
 *
 * THE FALLBACK RULE
 * -----------------
 * When only one string can be shown, {@see resolve()} picks, in this order:
 *
 *  1. the reader's language;
 *  2. the text's original language, when it has one (a workspace or unit name:
 *     for a Jordanian institution the Arabic name is usually the legal one, so
 *     English is never assumed to be the original);
 *  3. English, then Arabic;
 *  4. any language that has text;
 *  5. the caller's fallback, or ''.
 *
 * Step 4 matters: a name in a language the reader did not ask for is still
 * information, and a blank cell reads as a broken query. The web app's twin,
 * `localizedText()` in `@/lib/localized-text`, applies the same rule.
 *
 * Stateless; worker-safe.
 */
final class LocalizedText
{
    /** Arabic and English: the two languages a bilingual field offers. */
    public const BILINGUAL = ['ar', 'en'];

    /** Longest text in one language, unless the caller sets another limit. */
    public const MAX_LENGTH = 255;

    /** Most languages one text may carry. */
    public const MAX_LANGUAGES = 16;

    private const LANGUAGE_PATTERN = '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/';

    /**
     * Static helpers only: never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Read a caller's text into a language => text map. Text is trimmed and
     * blank languages are dropped, because a form sends every language whether
     * or not it was filled in.
     *
     * @param string|null       $bareLanguage Where a bare string is filed; null refuses one.
     * @param list<string>|null $languages    The only languages kept, in this order.
     *        Anything else in the object is ignored. Null keeps every language
     *        code and refuses a key that is not one.
     * @param int|null          $maxLength    Longest text per language; null for no limit.
     * @param bool              $required     Refuse a text with no language in it.
     * @return array<string, string>
     *
     * @throws LocalizedTextRejected
     */
    public static function normalize(
        mixed $raw,
        string $field,
        ?string $bareLanguage = null,
        ?array $languages = null,
        ?int $maxLength = self::MAX_LENGTH,
        bool $required = false,
    ): array {
        if (is_string($raw) && $bareLanguage !== null) {
            $raw = trim($raw);
            if ($raw === '') {
                throw new LocalizedTextRejected("{$field} must not be empty.");
            }
            $raw = [$bareLanguage => $raw];
        }

        if (!is_array($raw)) {
            throw new LocalizedTextRejected(
                match (true) {
                    $languages !== null => "{$field} must be text, or an object with '" . implode("' and/or '", $languages) . "'.",
                    $bareLanguage === null => "{$field} must be an object of language code to text, for example {\"ar\": \"...\", \"en\": \"...\"}.",
                    default => "{$field} must be text, or an object of language code to text, for example {\"ar\": \"...\", \"en\": \"...\"}.",
                }
            );
        }

        $out = $languages === null ? self::anyLanguage($raw, $field) : self::onlyLanguages($raw, $languages);

        if ($maxLength !== null) {
            foreach ($out as $language => $text) {
                if (mb_strlen($text) > $maxLength) {
                    throw new LocalizedTextRejected("{$field}.{$language} must be {$maxLength} characters or fewer.");
                }
            }
        }
        if (count($out) > self::MAX_LANGUAGES) {
            throw new LocalizedTextRejected("{$field} carries more than " . self::MAX_LANGUAGES . ' languages.');
        }
        if ($required && $out === []) {
            throw new LocalizedTextRejected(
                $languages === null
                    ? "{$field} cannot be empty in every language."
                    : "{$field} must carry at least one of '" . implode("' or '", $languages) . "'."
            );
        }

        return $out;
    }

    /**
     * A required Arabic/English label, as forms and calendar services keep
     * them. A bare string is English: a label in one language is the ordinary
     * case, not a defect. Other languages are ignored, because the label's
     * renderers draw exactly these two.
     *
     * @return array<string, string>
     *
     * @throws LocalizedTextRejected When neither language has text.
     */
    public static function label(mixed $raw, string $field): array
    {
        return self::normalize($raw, $field, 'en', self::BILINGUAL, null, true);
    }

    /**
     * An Arabic/English label that may be left out, encoded for its column.
     *
     * Null or blank clears it. An object replaces it. A BARE STRING SETS ONLY
     * THE ENGLISH and keeps the Arabic in `$existing`, because a client that
     * knows one language must not erase a translation it never saw.
     *
     * @param array<string, string> $existing What the column holds now.
     *
     * @throws LocalizedTextRejected On a wrong type, or text longer than `$maxLength`.
     */
    public static function optionalLabel(mixed $value, string $field, int $maxLength, array $existing = []): ?string
    {
        if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $label = self::label($value, $field);
        if (is_string($value)) {
            $label += $existing;
        }
        foreach ($label as $text) {
            if (mb_strlen($text) > $maxLength) {
                throw new LocalizedTextRejected("{$field} must be {$maxLength} characters or fewer.");
            }
        }

        return self::encode($label);
    }

    /**
     * Encode for a JSON column. `JSON_UNESCAPED_UNICODE` keeps Arabic readable
     * in the column (escaped, it triples in length and no query over a dump can
     * find it).
     *
     * @param array<string, string> $text
     */
    public static function encode(array $text): string
    {
        $json = json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * Decode a JSON column. NEVER THROWS: a row already stored is a fact, and a
     * decoder that refused one bad row would empty the list somebody needs to
     * open to fix it. A value that is not a JSON object is a plain string
     * written before the column held one, and reads as text in `$bareLanguage`.
     *
     * @param list<string>|null $languages The only languages read; null reads all.
     * @return array<string, string>
     */
    public static function decode(mixed $stored, string $bareLanguage = 'en', ?array $languages = null): array
    {
        if (!is_string($stored) || trim($stored) === '') {
            return [];
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return [$bareLanguage => $stored];
        }

        $out = [];
        foreach ($languages ?? array_keys($decoded) as $language) {
            $text = $decoded[$language] ?? null;
            if (is_string($language) && is_string($text) && $text !== '') {
                $out[$language] = $text;
            }
        }

        return $out;
    }

    /**
     * One string for a reader of `$language`, by the rule in the class
     * docblock. A bare string is returned as it is, trimmed.
     *
     * @param array<array-key, mixed>|string|null $text
     */
    public static function resolve(
        array|string|null $text,
        ?string $language = 'en',
        ?string $originalLanguage = null,
        string $fallback = '',
    ): string {
        if (is_string($text)) {
            $text = trim($text);

            return $text !== '' ? $text : $fallback;
        }
        if ($text === null || $text === []) {
            return $fallback;
        }

        foreach ([$language, $originalLanguage, 'en', 'ar'] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $value = $text[$candidate] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        foreach ($text as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $fallback;
    }

    /**
     * Every language of a name whose original lives in its own column,
     * original first. The column wins over a stale copy in the others.
     *
     * @param array<string, string> $others The other-language texts.
     * @return array<string, string>
     */
    public static function merge(string $original, string $originalLanguage, array $others): array
    {
        unset($others[$originalLanguage]);
        if ($original === '') {
            return $others;
        }

        return [$originalLanguage => $original] + $others;
    }

    /**
     * Read a name whose original lives in its own column from a request body.
     *
     * `$languageKey` holds the original's language, `$namesKey` every language
     * at once (it replaces them all), and `$nameKey` the original alone. The
     * original must have text: every other language falls back to it.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $currentOthers The other languages stored now.
     * @return array{name: string, language: string, others: array<string, string>}
     *
     * @throws LocalizedTextRejected
     */
    public static function fromBody(
        array $body,
        string $currentName,
        string $currentLanguage,
        array $currentOthers,
        int $maxLength,
        string $nameKey = 'name',
        string $namesKey = 'names',
        string $languageKey = 'name_language',
    ): array {
        $language = $currentLanguage;
        if (array_key_exists($languageKey, $body)) {
            if (!is_string($body[$languageKey]) || !self::isLanguageCode($body[$languageKey])) {
                throw new LocalizedTextRejected("{$languageKey} must be a language code, such as \"ar\" or \"en\".");
            }
            $language = $body[$languageKey];
        }

        $names = array_key_exists($namesKey, $body)
            ? self::normalize($body[$namesKey], $namesKey)
            : self::merge($currentName, $currentLanguage, $currentOthers);

        if (isset($body[$nameKey]) && is_scalar($body[$nameKey])) {
            $name = trim((string) $body[$nameKey]);
            if ($name !== '') {
                $names[$language] = $name;
            }
        }

        $original = $names[$language] ?? '';
        if ($original === '') {
            throw new LocalizedTextRejected("The name in the original language ({$language}) is required.");
        }
        if (mb_strlen($original) > $maxLength) {
            throw new LocalizedTextRejected("{$nameKey} must be {$maxLength} characters or fewer.");
        }

        unset($names[$language]);

        return ['name' => $original, 'language' => $language, 'others' => $names];
    }

    public static function isLanguageCode(string $code): bool
    {
        return preg_match(self::LANGUAGE_PATTERN, $code) === 1;
    }

    /**
     * The language a text is most likely written in, for one that arrives
     * without one: Arabic when it has Arabic letters, English otherwise.
     */
    public static function guessLanguage(string $text): string
    {
        return preg_match('/\p{Arabic}/u', $text) === 1 ? 'ar' : 'en';
    }

    /**
     * Every key must be a language code and every value text or null.
     *
     * @param array<array-key, mixed> $raw
     * @return array<string, string>
     *
     * @throws LocalizedTextRejected
     */
    private static function anyLanguage(array $raw, string $field): array
    {
        $out = [];
        foreach ($raw as $language => $text) {
            if (!is_string($language) || !self::isLanguageCode($language)) {
                throw new LocalizedTextRejected(
                    "{$field} has a key that is not a language code: "
                    . (is_string($language) ? "'{$language}'" : gettype($language)) . '.'
                );
            }
            if ($text === null) {
                continue;
            }
            if (!is_string($text)) {
                throw new LocalizedTextRejected("{$field}.{$language} must be text.");
            }
            $text = trim($text);
            if ($text !== '') {
                $out[$language] = $text;
            }
        }

        return $out;
    }

    /**
     * Only the listed languages, in their order; anything else is ignored.
     *
     * @param array<array-key, mixed> $raw
     * @param list<string>            $languages
     * @return array<string, string>
     */
    private static function onlyLanguages(array $raw, array $languages): array
    {
        $out = [];
        foreach ($languages as $language) {
            $text = $raw[$language] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $out[$language] = trim($text);
            }
        }

        return $out;
    }
}
