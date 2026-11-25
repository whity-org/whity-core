<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * The theme files a plugin lists in `plugin.json`'s `themes` (SDK 1.163,
 * decision D4, TH.13): paths inside the plugin to theme files in the format of
 * `schemas/theme/v1.json`.
 *
 * ```json
 * "themes": ["themes/harbour.json", "themes/dunes.json"]
 * ```
 *
 * Checked here: each is a relative path inside the plugin ending in `.json`,
 * listed once. The same check serves `workspaceTemplates` and
 * `workspaceTemplateExtensions` (SDK 1.170). What a file says is checked by `whity-cli plugin:validate` and
 * by the host when it loads the plugin, theme by theme. Pure and static.
 */
final class ThemeFilesValidator
{
    public const MAX_THEMES = 20;

    private const PATH_PATTERN = '#^(?!/)(?!.*(?:^|/)\.\.?(?:/|$))[A-Za-z0-9_./-]{1,200}\.json$#';

    private function __construct()
    {
    }

    /** @return list<string> each naming its path; empty when valid */
    public static function errors(mixed $themes, string $path = 'themes'): array
    {
        if ($themes === null) {
            return [];
        }
        if (!is_array($themes) || !array_is_list($themes)) {
            return ["{$path}: must be a list of file paths"];
        }
        if (count($themes) > self::MAX_THEMES) {
            return ["{$path}: at most " . self::MAX_THEMES . ' files'];
        }

        $errors = [];
        $seen = [];
        foreach ($themes as $i => $file) {
            if (!is_string($file) || preg_match(self::PATH_PATTERN, $file) !== 1) {
                $errors[] = "{$path}[{$i}]: a path inside the plugin ending in .json, such as " . ($path === 'themes' ? 'themes/harbour.json' : 'templates/clinic.json');
            } elseif (isset($seen[$file])) {
                $errors[] = "{$path}[{$i}]: \"{$file}\" is listed twice";
            } else {
                $seen[$file] = true;
            }
        }

        return $errors;
    }
}
