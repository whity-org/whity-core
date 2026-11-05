<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * A plugin's card on the home page (SDK 1.62, WHIT-872; design 2.9 H.4), from
 * `homeCard` in `plugin.json` or {@see \Whity\Sdk\PluginHomeCardInterface}. Each
 * plugin may add one.
 *
 * ```json
 * "homeCard": {
 *   "label": { "en": "Stock", "ar": "المخزون" },
 *   "permission": "inventory:read",
 *   "source": "/api/inventory/home-card",
 *   "screen": "inventory",
 *   "icon": "package"
 * }
 * ```
 *
 * The data comes from exactly one of:
 *
 *  - `source`: a GET route of this plugin, gated on this same `permission`. It
 *    answers with the person's own scope, because a card shows only what they may
 *    see: a branch manager's Stock card counts their branch. Its body is
 *    `{"data": {"value": "3", "caption": "items below reorder level", "lines":
 *    [{"label": "Flour", "value": "4 / 6"}], "chart": [{"label": "W1", "value": 4120}]}}`,
 *    with at most {@see self::MAX_LINES} lines and {@see self::MAX_CHART_POINTS}
 *    points. Only `value` is required. A plugin writes these in the language asked
 *    for, and amounts as it wants them shown.
 *  - `record`: a record type in this manifest (a workspace plugin, which has no
 *    routes of its own). The host counts its records, and `caption` names them.
 *
 * `screen` is where the card opens, and names a screen in this manifest.
 *
 * The host also checks what this file can't know: that a `source` is a GET route
 * this plugin actually registered under this permission. A bad card is dropped with
 * a logged reason, and the plugin keeps loading.
 */
final class HomeCardValidator
{
    public const MAX_LINES = 5;
    public const MAX_CHART_POINTS = 12;

    private const LABEL_MAX = 60;
    private const KEYS = ['label', 'caption', 'permission', 'source', 'record', 'screen', 'icon'];
    private const PERMISSION_PATTERN = '/^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*)+$/';
    private const SOURCE_PATTERN = '#^/api/[A-Za-z0-9_\-/]+$#';
    private const ICON_PATTERN = '/^[a-z0-9-]{1,64}$/';

    private function __construct()
    {
    }

    /**
     * Check one declaration's own shape.
     *
     * @return list<string> what is wrong, each naming its path; empty when valid
     */
    public static function errors(mixed $card): array
    {
        if (!is_array($card) || array_is_list($card)) {
            return ['homeCard: must be an object'];
        }

        $errors = [];
        foreach (array_keys($card) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                $errors[] = "homeCard.{$key}: unknown key";
            }
        }

        foreach (['label' => true, 'caption' => false] as $key => $required) {
            if (!isset($card[$key]) && !$required) {
                continue;
            }
            foreach (['en', 'ar'] as $language) {
                $text = $card[$key][$language] ?? null;
                if (!is_string($text) || trim($text) === '' || mb_strlen($text) > self::LABEL_MAX) {
                    $errors[] = "homeCard.{$key}.{$language}: required, at most " . self::LABEL_MAX . ' characters';
                }
            }
        }

        if (!is_string($card['permission'] ?? null) || preg_match(self::PERMISSION_PATTERN, $card['permission']) !== 1) {
            $errors[] = 'homeCard.permission: required, a permission slug';
        }

        $source = $card['source'] ?? null;
        $record = $card['record'] ?? null;
        if (($source === null) === ($record === null)) {
            $errors[] = 'homeCard: exactly one of source and record is required';
        }
        if ($source !== null && (!is_string($source) || preg_match(self::SOURCE_PATTERN, $source) !== 1)) {
            $errors[] = 'homeCard.source: must be a path under /api/ with no parameters';
        }
        if ($record !== null && (!is_string($record) || $record === '')) {
            $errors[] = 'homeCard.record: must name a record type';
        }

        if (isset($card['screen']) && (!is_string($card['screen']) || $card['screen'] === '')) {
            $errors[] = 'homeCard.screen: must name a screen';
        }
        if (isset($card['icon']) && (!is_string($card['icon']) || preg_match(self::ICON_PATTERN, $card['icon']) !== 1)) {
            $errors[] = 'homeCard.icon: lowercase letters, digits and dashes';
        }

        return $errors;
    }

    /**
     * Check `homeCard` in a manifest, including what it names in that manifest.
     *
     * @param list<string> $errors collects, by reference, what is wrong
     */
    public static function check(mixed $card, mixed $records, mixed $screens, array &$errors): void
    {
        if ($card === null) {
            return;
        }

        $found = self::errors($card);
        array_push($errors, ...$found);
        if ($found !== [] || !is_array($card)) {
            return;
        }

        if (isset($card['record']) && !isset((is_array($records) ? $records : [])[$card['record']])) {
            $errors[] = "homeCard.record: {$card['record']} is not a record type in this manifest";
        }
        if (isset($card['screen'])) {
            $ids = is_array($screens) ? array_column(array_filter($screens, 'is_array'), 'id') : [];
            if (!in_array($card['screen'], $ids, true)) {
                $errors[] = "homeCard.screen: {$card['screen']} is not a screen in this manifest";
            }
        }
    }
}
