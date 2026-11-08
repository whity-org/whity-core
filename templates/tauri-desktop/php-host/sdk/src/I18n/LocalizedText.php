<?php

declare(strict_types=1);

namespace Whity\Sdk\I18n;

/**
 * Translatable content fields: one value in Arabic and English (#521).
 *
 * This is for data people write, such as a question stem or a product name.
 * It is not for interface wording. On the wire and in storage the value is one
 * object holding the languages it has:
 *
 *     {"ar": "فاتورة", "en": "Invoice"}
 *
 * Either language may be missing. An empty object means nothing was written.
 *
 * Declare a property with {@see self::schema()} in a route's OpenAPI `schema`
 * and the host's schema-driven screens do the rest: a `screen: 'crud'` list
 * shows the reader's language, falling back to the other one, and its form
 * edits both languages side by side.
 *
 * See docs/wiki/Plugin-Localized-Text.md and the plugins/Glossary example.
 */
final class LocalizedText
{
    /** The OpenAPI vendor extension that marks a property as translatable. */
    public const MARKER = 'x-whity-localized-text';

    /** The languages a value can carry. */
    public const LANGUAGES = ['ar', 'en'];

    /**
     * The OpenAPI fragment for a translatable property.
     *
     * The result is a plain array, so a route can add to it:
     * `LocalizedText::schema() + ['nullable' => true]`.
     *
     * @return array{type: 'object', 'x-whity-localized-text': true, properties: array{ar: array{type: 'string'}, en: array{type: 'string'}}}
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            self::MARKER => true,
            'properties' => [
                'ar' => ['type' => 'string'],
                'en' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Whether an OpenAPI node is a translatable property, by the same test the
     * schema-driven screens apply.
     *
     * @param array<string, mixed> $node
     */
    public static function isSchema(array $node): bool
    {
        return ($node['type'] ?? null) === 'object' && ($node[self::MARKER] ?? null) === true;
    }

    /**
     * Read a value from a request body or a stored row.
     *
     * Text is trimmed and blank or null languages are dropped, because the
     * screens send both languages whether or not both were filled in. Returns
     * null when `$raw` is not this shape, so a handler can refuse the request.
     * An array result may be empty: a field that needs text must check for that.
     *
     * @return array{ar?: string, en?: string}|null
     */
    public static function normalize(mixed $raw): ?array
    {
        if (!is_array($raw) || array_diff(array_keys($raw), self::LANGUAGES) !== []) {
            return null;
        }

        $value = [];
        foreach (self::LANGUAGES as $language) {
            $text = $raw[$language] ?? null;
            if ($text === null) {
                continue;
            }
            if (!is_string($text)) {
                return null;
            }
            $text = trim($text);
            if ($text !== '') {
                $value[$language] = $text;
            }
        }

        return $value;
    }

    /**
     * The text to show someone reading in `$language`.
     *
     * Falls back to the other language, as the screens do, and returns null
     * when the value has no text at all. Regional codes such as `ar-JO` count
     * as their base language. Any language other than Arabic reads English first.
     *
     * @param array<string, mixed> $value
     */
    public static function resolve(array $value, string $language): ?string
    {
        $order = strtolower(substr($language, 0, 2)) === 'ar' ? ['ar', 'en'] : ['en', 'ar'];
        foreach ($order as $candidate) {
            $text = $value[$candidate] ?? null;
            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Static helpers only: never instantiated.
     */
    private function __construct()
    {
    }
}
