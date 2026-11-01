<?php

declare(strict_types=1);

namespace Whity\Core\Document\Slots;

/**
 * The workspace's background-issuing allow-list (WHIT-742 slice 3,
 * DOCUMENT-AUTOMATION.md 3.7): the tenant setting
 * `documents.background_issuing`, a JSON list of the plugins allowed to
 * issue documents with no person to act for, e.g. ["Invoicing"].
 *
 * Off for every plugin until a holder of documents:publish allows it. Even
 * then a plugin issues only from its own slots, with only the permissions it
 * declares.
 */
final class BackgroundIssuing
{
    private const PLUGIN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    private function __construct()
    {
    }

    /** Why the stored value is malformed, or null when it is valid. '' is "none". */
    public static function validate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $list = json_decode($value, true);
        if (!is_array($list) || !array_is_list($list)) {
            return 'documents.background_issuing must be a JSON list of plugin names, e.g. ["Invoicing"].';
        }
        foreach ($list as $plugin) {
            if (!is_string($plugin) || preg_match(self::PLUGIN, $plugin) !== 1) {
                return 'documents.background_issuing: each entry is a plugin name.';
            }
        }
        if (count(array_unique($list)) !== count($list)) {
            return 'documents.background_issuing: no repeats.';
        }

        return null;
    }

    /**
     * The allowed plugins, or none when the stored value is malformed.
     *
     * @return list<string>
     */
    public static function parse(string $value): array
    {
        if ($value === '' || self::validate($value) !== null) {
            return [];
        }
        /** @var list<string> $list */
        $list = json_decode($value, true);

        return $list;
    }

    /** @param list<string> $plugins */
    public static function encode(array $plugins): string
    {
        $plugins = array_values(array_unique($plugins));
        sort($plugins);

        return $plugins === [] ? '' : json_encode($plugins, JSON_THROW_ON_ERROR);
    }
}
