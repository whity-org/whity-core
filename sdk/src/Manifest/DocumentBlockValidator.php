<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use Whity\Sdk\Frontend\Blocks\BlockValidator;

/**
 * Checks document blocks a plugin adds to the editor (SDK 1.103; editor M7,
 * E.17–E.20): `documents.blocks` in a workspace plugin's manifest, and each
 * {@see \Whity\Sdk\Documents\DocumentBlock} a code plugin declares.
 *
 * A plugin block is a declaration, never code (register E.17, Q13): Whity's
 * own renderer draws it with Whity's components, so a Verified plugin's
 * block looks built in while the publisher's JavaScript never runs.
 *
 *     "documents": {
 *       "blocks": {
 *         "delivery_status": {
 *           "label": { "en": "Delivery status", "ar": "حالة التوصيل" },
 *           "description": { "en": "Where an order is", "ar": "أين الطلب" },
 *           "icon": "truck",
 *           "keywords": ["shipping", "order"],
 *           "fields": [
 *             { "key": "order", "label": { "en": "Order", "ar": "الطلب" }, "type": "text", "required": true },
 *             { "key": "stage", "label": { "en": "Stage", "ar": "المرحلة" }, "type": "select",
 *               "options": [{ "value": "packed", "label": { "en": "Packed", "ar": "مُعبأ" } }] }
 *           ],
 *           "view": [{ "type": "keyValue", "items": [{ "label": "Order", "value": "{{data.order}}" }] }],
 *           "print": [{ "type": "keyValue", "items": [{ "label": "Order", "value": "{{data.order}}" }] }],
 *           "allowedIn": ["note", "wiki", "template"]
 *         }
 *       }
 *     }
 *
 * - `fields` are what a person fills in the block's Edit form; the block's
 *   `data` holds one value per key.
 * - `view` is drawn in the editor and `print` by Whity Render, both in the
 *   display blocks of the screen vocabulary ({@see VIEW_TYPES},
 *   {@see PRINT_TYPES}). Text may name a field as `{{data.<key>}}`.
 * - Without `print` the block is offered in notes and wiki pages only, never
 *   in anything that prints (E.18).
 */
final class DocumentBlockValidator
{
    public const MAX_BLOCKS = 30;
    public const MAX_FIELDS = 20;
    public const FIELD_TYPES = ['text', 'longText', 'number', 'date', 'select'];
    public const KINDS = ['note', 'wiki', 'template', 'letter', 'report', 'label'];
    /** The kinds that print, so a block without a print view is kept out of them (E.18). */
    public const PRINTED_KINDS = ['template', 'letter', 'report', 'label'];
    /** Display blocks a block's editor view may use: no data sources, forms or actions. */
    public const VIEW_TYPES = ['section', 'card', 'row', 'grid', 'divider', 'heading', 'text', 'alert', 'badge', 'stat', 'keyValue', 'list', 'table'];
    /** What Whity Render draws for a block's print view. */
    public const PRINT_TYPES = ['divider', 'heading', 'text', 'keyValue', 'list', 'table'];

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    private const KEYS = ['label', 'description', 'icon', 'keywords', 'fields', 'view', 'print', 'allowedIn'];

    private function __construct()
    {
    }

    /**
     * `documents.blocks` in a manifest: an object of block name to block.
     *
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    public static function check(mixed $blocks, callable $label, array &$errors): void
    {
        if ($blocks === null) {
            return;
        }
        if (!is_array($blocks) || array_is_list($blocks) && $blocks !== []) {
            $errors[] = 'documents.blocks: must be an object of block name to block';
            return;
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            $errors[] = 'documents.blocks: at most ' . self::MAX_BLOCKS;
        }
        foreach ($blocks as $name => $block) {
            self::checkOne((string) $name, $block, "documents.blocks.{$name}", $label, $errors);
        }
    }

    /**
     * One block, as a code plugin declares it or a manifest lists it.
     *
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    public static function checkOne(string $name, mixed $block, string $path, callable $label, array &$errors): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $errors[] = "{$path}: block names are lowercase letters, digits and underscores, up to 40";
        }
        if (!is_array($block) || array_is_list($block) && $block !== []) {
            $errors[] = "{$path}: must be an object";
            return;
        }
        foreach (array_keys($block) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                $errors[] = "{$path}: unknown key '{$key}'";
            }
        }
        $label($block['label'] ?? null, "{$path}.label", 60, true, $errors);
        $label($block['description'] ?? null, "{$path}.description", 120, false, $errors);
        if (array_key_exists('icon', $block) && (!is_string($block['icon']) || preg_match('/^[a-z0-9-]{1,40}$/', $block['icon']) !== 1)) {
            $errors[] = "{$path}.icon: an icon name, lowercase letters, digits and hyphens";
        }
        if (array_key_exists('keywords', $block)) {
            $keywords = $block['keywords'];
            if (!is_array($keywords) || !array_is_list($keywords) || count($keywords) > 10
                || array_filter($keywords, static fn (mixed $k): bool => !is_string($k) || $k === '' || mb_strlen($k) > 40) !== []) {
                $errors[] = "{$path}.keywords: up to 10 words of up to 40 characters";
            }
        }

        $keys = self::checkFields($block['fields'] ?? null, "{$path}.fields", $label, $errors);
        self::checkTree($block['view'] ?? null, "{$path}.view", self::VIEW_TYPES, $keys, true, $errors);
        $prints = array_key_exists('print', $block);
        if ($prints) {
            self::checkTree($block['print'], "{$path}.print", self::PRINT_TYPES, $keys, true, $errors);
        }

        if (array_key_exists('allowedIn', $block)) {
            $kinds = $block['allowedIn'];
            if (!is_array($kinds) || !array_is_list($kinds) || $kinds === []
                || array_diff(array_map('strval', $kinds), self::KINDS) !== []) {
                $errors[] = "{$path}.allowedIn: a list of " . implode(', ', self::KINDS);
            } elseif (!$prints && array_intersect(array_map('strval', $kinds), self::PRINTED_KINDS) !== []) {
                $errors[] = "{$path}.allowedIn: a block without a print view is for notes and wiki pages only";
            }
        }
    }

    /**
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     * @return list<string> the field keys
     */
    private static function checkFields(mixed $fields, string $path, callable $label, array &$errors): array
    {
        if ($fields === null) {
            return [];
        }
        if (!is_array($fields) || !array_is_list($fields)) {
            $errors[] = "{$path}: a list of {key, label, type, required?, options?}";
            return [];
        }
        if (count($fields) > self::MAX_FIELDS) {
            $errors[] = "{$path}: at most " . self::MAX_FIELDS;
        }
        $keys = [];
        foreach ($fields as $i => $field) {
            $at = "{$path}.{$i}";
            if (!is_array($field)) {
                $errors[] = "{$at}: must be an object";
                continue;
            }
            foreach (array_keys($field) as $key) {
                if (!in_array($key, ['key', 'label', 'type', 'required', 'options'], true)) {
                    $errors[] = "{$at}: unknown key '{$key}'";
                }
            }
            $key = $field['key'] ?? null;
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                $errors[] = "{$at}.key: lowercase letters, digits and underscores, up to 40";
            } elseif (in_array($key, $keys, true)) {
                $errors[] = "{$at}.key: '{$key}' is used twice";
            } else {
                $keys[] = $key;
            }
            $label($field['label'] ?? null, "{$at}.label", 60, true, $errors);
            $type = $field['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::FIELD_TYPES, true)) {
                $errors[] = "{$at}.type: one of " . implode(', ', self::FIELD_TYPES);
            }
            if (array_key_exists('required', $field) && !is_bool($field['required'])) {
                $errors[] = "{$at}.required: true or false";
            }
            $options = $field['options'] ?? null;
            if ($type === 'select') {
                if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > 30) {
                    $errors[] = "{$at}.options: a select needs 1 to 30 options";
                } else {
                    foreach ($options as $j => $option) {
                        if (!is_array($option) || !is_string($option['value'] ?? null) || $option['value'] === '') {
                            $errors[] = "{$at}.options.{$j}: {value, label}";
                            continue;
                        }
                        $label($option['label'] ?? null, "{$at}.options.{$j}.label", 60, true, $errors);
                    }
                }
            } elseif ($options !== null) {
                $errors[] = "{$at}.options: only a select has options";
            }
        }

        return $keys;
    }

    /**
     * A view or print view: display blocks of the allowed types, checked with
     * the screen vocabulary's own rules, naming only declared fields.
     *
     * @param list<string> $types
     * @param list<string> $keys
     * @param list<string> $errors
     */
    private static function checkTree(mixed $tree, string $path, array $types, array $keys, bool $required, array &$errors): void
    {
        if ($tree === null && !$required) {
            return;
        }
        if (!is_array($tree) || !array_is_list($tree) || $tree === []) {
            $errors[] = "{$path}: a non-empty list of blocks";
            return;
        }
        foreach (self::typesIn($tree) as $type) {
            if (!in_array($type, $types, true)) {
                $errors[] = "{$path}: '{$type}' can't be used here; use " . implode(', ', $types);
            }
        }
        $result = BlockValidator::validate($tree);
        foreach ($result['errors'] as $error) {
            $errors[] = "{$path}: {$error}";
        }
        foreach (self::references($tree) as $ref) {
            if (!in_array($ref, $keys, true)) {
                $errors[] = "{$path}: {{data.{$ref}}} names no field of this block";
            }
        }
    }

    /**
     * @param array<mixed> $tree
     * @return list<string>
     */
    private static function typesIn(array $tree): array
    {
        $types = [];
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (is_string($node['type'] ?? null)) {
                $types[] = $node['type'];
            }
            if (is_array($node['children'] ?? null)) {
                array_push($types, ...self::typesIn($node['children']));
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Field keys named as {{data.key}} anywhere in the tree's strings.
     *
     * @return list<string>
     */
    public static function references(mixed $tree): array
    {
        $found = [];
        $walk = static function (mixed $value) use (&$walk, &$found): void {
            if (is_string($value)) {
                if (preg_match_all('/\{\{\s*data\.([a-z][a-z0-9_]{0,39})\s*\}\}/', $value, $m) > 0) {
                    array_push($found, ...$m[1]);
                }
            } elseif (is_array($value)) {
                foreach ($value as $v) {
                    $walk($v);
                }
            }
        };
        $walk($tree);

        return array_values(array_unique($found));
    }
}
