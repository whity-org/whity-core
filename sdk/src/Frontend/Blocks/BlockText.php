<?php

declare(strict_types=1);

namespace Whity\Sdk\Frontend\Blocks;

use Whity\Sdk\I18n\LocalizedText;

/**
 * Text a person reads in a block screen, in Arabic and English (SDK 1.101, WHIT-984).
 *
 * Every visible string in a `screen: 'blocks'` descriptor may be written as a
 * {@see LocalizedText} value instead of a plain string:
 *
 *     ['type' => 'submitButton', 'label' => ['en' => 'Save', 'ar' => 'حفظ']]
 *
 * That covers the descriptor's own `label`, and `label`, `title`,
 * `placeholder`, `emptyText`, `description`, `confirm`, `trigger` and
 * `itemLabel` anywhere in the tree (block props, columns, options, row
 * actions), plus `value` on a `text` block, `text` on a `heading` and
 * `body` on an `alert`.
 *
 * The host resolves each one to the reader's language, falling back to the
 * other language, before it validates and serves the screen. Renderers keep
 * receiving plain strings, so the wire shape does not change.
 * {@see BlockValidator::validate()} accepts the objects and refuses one with no
 * text at all.
 *
 * Fields that hold a value rather than words (`name`, `value` on an option,
 * `labelField`, `endpoint`) stay plain strings.
 */
final class BlockText
{
    /**
     * Fields whose value is text a person reads, wherever they appear.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'label',
        'title',
        'placeholder',
        'emptyText',
        'description',
        'confirm',
        'enLabel',
        'arLabel',
        'trigger',
        'itemLabel',
    ];

    /**
     * Fields that are text only under certain block types: `value` is a
     * paragraph on `text` and a submitted value on a select option.
     *
     * @var array<string, list<string>>
     */
    public const FIELDS_FOR_TYPE = [
        'value' => ['text'],
        'text' => ['heading'],
        'body' => ['alert'],
    ];

    /**
     * The tree with every localized value replaced by the text for `$language`.
     *
     * Anything that is not a localized value is left as it is, for the
     * validator to judge.
     *
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    public static function resolve(array $node, string $language): array
    {
        $fields = self::fieldsOf($node);
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (is_string($key) && in_array($key, $fields, true) && self::isLocalized($value)) {
                $node[$key] = LocalizedText::resolve($value, $language) ?? '';
                continue;
            }
            $node[$key] = self::resolve($value, $language);
        }

        return $node;
    }

    /**
     * Localized values with no text in either language, as validator errors.
     *
     * @param array<array-key, mixed> $node
     * @return list<string>
     */
    public static function problems(array $node, string $path = 'blocks'): array
    {
        $problems = [];
        $fields = self::fieldsOf($node);
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $here = is_int($key) ? "{$path}[{$key}]" : "{$path}.{$key}";
            if (is_string($key) && in_array($key, $fields, true) && self::isLocalized($value)) {
                if (LocalizedText::resolve($value, 'en') === null) {
                    $problems[] = "{$here}: localized text needs English or Arabic";
                }
                continue;
            }
            array_push($problems, ...self::problems($value, $here));
        }

        return $problems;
    }

    /**
     * Whether a value has the localized-text shape: an object with only `ar`
     * and `en`, each a string or null.
     */
    public static function isLocalized(mixed $value): bool
    {
        return is_array($value) && $value !== [] && !array_is_list($value) && LocalizedText::normalize($value) !== null;
    }

    /**
     * @param array<array-key, mixed> $node
     * @return list<string>
     */
    private static function fieldsOf(array $node): array
    {
        $fields = self::FIELDS;
        $type = $node['type'] ?? null;
        if (is_string($type)) {
            foreach (self::FIELDS_FOR_TYPE as $field => $types) {
                if (in_array($type, $types, true)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * Static helpers only: never instantiated.
     */
    private function __construct()
    {
    }
}
