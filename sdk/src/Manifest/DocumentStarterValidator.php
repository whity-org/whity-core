<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * Checks `documents.starters` (SDK 1.81): starter templates a workspace
 * plugin ships. The host seeds each into a workspace when the plugin goes
 * live there, publishes it and, when it names one of the plugin's slots that
 * has no template yet, binds it to that slot. The workspace can then edit it
 * like any template; the plugin never overwrites an edited copy.
 *
 *     "documents": {
 *       "slots": { "receipt": { "label": {...}, "record": "order" } },
 *       "starters": {
 *         "receipt": {
 *           "label": { "en": "Order receipt", "ar": "إيصال الطلب" },
 *           "slot": "receipt",
 *           "lang": "en",
 *           "page": { "size": "A4", "marginMm": 16, "letterhead": true },
 *           "ownFields": [{ "key": "note", "label": "Note" }],
 *           "blocks": [{ "id": "b1", "type": "heading", "level": 1, "content": [...] }]
 *         }
 *       }
 *     }
 *
 * `blocks` are the editor's blocks (docs/design/handoff/editor). They are
 * checked here for shape only: a list of objects, each with a unique string
 * id and a string type. What a block means is the editor's to decide.
 */
final class DocumentStarterValidator
{
    public const MAX_STARTERS = 20;
    public const MAX_BLOCKS = 500;
    public const MAX_OWN_FIELDS = 50;
    public const LANGS = ['en', 'ar'];

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    private const FIELD_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    private const KEYS = ['label', 'kind', 'lang', 'slot', 'record', 'page', 'blocks', 'ownFields'];

    private function __construct()
    {
    }

    /**
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    public static function check(mixed $starters, mixed $slots, mixed $records, callable $label, array &$errors): void
    {
        if ($starters === null) {
            return;
        }
        if (!is_array($starters) || array_is_list($starters) && $starters !== []) {
            $errors[] = 'documents.starters: must be an object of starter name to starter';
            return;
        }
        if (count($starters) > self::MAX_STARTERS) {
            $errors[] = 'documents.starters: at most ' . self::MAX_STARTERS;
        }
        $slots = is_array($slots) ? $slots : [];
        $records = is_array($records) ? $records : [];
        $slotsTaken = [];

        foreach ($starters as $name => $starter) {
            $path = "documents.starters.{$name}";
            if (preg_match(self::NAME_PATTERN, (string) $name) !== 1) {
                $errors[] = "{$path}: starter names are lowercase letters, digits and underscores, up to 40";
            }
            if (!is_array($starter) || array_is_list($starter) && $starter !== []) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            foreach (array_keys($starter) as $key) {
                if (!in_array($key, self::KEYS, true)) {
                    $errors[] = "{$path}: unknown key '{$key}'";
                }
            }
            $label($starter['label'] ?? null, "{$path}.label", 200, true, $errors);

            $kind = $starter['kind'] ?? 'template';
            if (!is_string($kind) || !in_array($kind, DocumentSlotValidator::KINDS, true)) {
                $errors[] = "{$path}.kind: one of " . implode(', ', DocumentSlotValidator::KINDS);
            }
            $lang = $starter['lang'] ?? 'en';
            if (!is_string($lang) || !in_array($lang, self::LANGS, true)) {
                $errors[] = "{$path}.lang: one of " . implode(', ', self::LANGS);
            }

            $slot = $starter['slot'] ?? null;
            if ($slot !== null) {
                if (!is_string($slot) || !array_key_exists($slot, $slots)) {
                    $errors[] = "{$path}.slot: must be a slot this manifest declares under documents.slots";
                } else {
                    if (isset($slotsTaken[$slot])) {
                        $errors[] = "{$path}.slot: '{$slot}' already has a starter ({$slotsTaken[$slot]})";
                    }
                    $slotsTaken[$slot] = (string) $name;
                    $declared = $slots[$slot];
                    $kinds = is_array($declared) && is_array($declared['kinds'] ?? null) ? $declared['kinds'] : ['template'];
                    if (is_string($kind) && !in_array($kind, $kinds, true)) {
                        $errors[] = "{$path}.kind: slot '{$slot}' takes " . implode(' or ', array_map('strval', $kinds));
                    }
                    if (array_key_exists('record', $starter) && is_array($declared) && $starter['record'] !== ($declared['record'] ?? null)) {
                        $errors[] = "{$path}.record: must be the record of slot '{$slot}', or left out";
                    }
                }
            }
            if (array_key_exists('record', $starter)) {
                $record = $starter['record'];
                if (!is_string($record) || !array_key_exists($record, $records)) {
                    $errors[] = "{$path}.record: must be a record type this manifest declares";
                }
            }

            if (!is_array($starter['page'] ?? null) || array_is_list($starter['page']) && $starter['page'] !== []) {
                $errors[] = "{$path}.page: required, an object such as {\"size\": \"A4\", \"marginMm\": 16, \"letterhead\": true}";
            }

            self::checkBlocks($starter['blocks'] ?? null, "{$path}.blocks", $errors);
            self::checkOwnFields($starter['ownFields'] ?? [], "{$path}.ownFields", $errors);
        }
    }

    /** @param list<string> $errors */
    private static function checkBlocks(mixed $blocks, string $path, array &$errors): void
    {
        if (!is_array($blocks) || !array_is_list($blocks) || $blocks === []) {
            $errors[] = "{$path}: required, a non-empty list of editor blocks";
            return;
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            $errors[] = "{$path}: at most " . self::MAX_BLOCKS . ' blocks';
            return;
        }
        $ids = [];
        foreach ($blocks as $i => $block) {
            if (!is_array($block) || array_is_list($block)
                || !is_string($block['id'] ?? null) || $block['id'] === ''
                || !is_string($block['type'] ?? null) || $block['type'] === '') {
                $errors[] = "{$path}.{$i}: each block is an object with a string id and a string type";
                return;
            }
            if (isset($ids[$block['id']])) {
                $errors[] = "{$path}.{$i}: block id '{$block['id']}' is used twice";
                return;
            }
            $ids[$block['id']] = true;
        }
    }

    /**
     * A sample is text, or text per language (`{"en": ..., "ar": ...}`): the
     * host seeds the one in the starter's language, so an Arabic starter's
     * samples are Arabic.
     */
    private static function validSample(mixed $sample): bool
    {
        if (is_string($sample)) {
            return mb_strlen($sample) <= 200;
        }
        if (!is_array($sample) || $sample === [] || array_diff(array_keys($sample), self::LANGS) !== []) {
            return false;
        }
        foreach ($sample as $text) {
            if (!is_string($text) || mb_strlen($text) > 200) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $errors */
    private static function checkOwnFields(mixed $fields, string $path, array &$errors): void
    {
        if (!is_array($fields) || !array_is_list($fields)) {
            $errors[] = "{$path}: a list of {key, label, sample?}";
            return;
        }
        if (count($fields) > self::MAX_OWN_FIELDS) {
            $errors[] = "{$path}: at most " . self::MAX_OWN_FIELDS;
            return;
        }
        $keys = [];
        foreach ($fields as $i => $field) {
            if (!is_array($field)
                || !is_string($field['key'] ?? null) || preg_match(self::FIELD_PATTERN, $field['key']) !== 1
                || !is_string($field['label'] ?? null) || $field['label'] === '' || mb_strlen($field['label']) > 80
                || (array_key_exists('sample', $field) && !self::validSample($field['sample']))
                || array_diff(array_keys($field), ['key', 'label', 'sample']) !== []) {
                $errors[] = "{$path}.{$i}: {key, label, sample?}: key lowercase letters, digits and _ (up to 40), label up to 80, "
                    . 'sample text or {"en": ..., "ar": ...} up to 200 each';
                return;
            }
            if (isset($keys[$field['key']])) {
                $errors[] = "{$path}.{$i}: key '{$field['key']}' is used twice";
                return;
            }
            $keys[$field['key']] = true;
        }
    }
}
