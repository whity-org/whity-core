<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Theme\ThemeValidator;
use Whity\Sdk\Manifest\ManifestValidator;

/**
 * Check a plugin's `plugin.json` before it is installed (SDK 1.163, decision
 * D4): `whity-cli plugin validate <path>`.
 *
 * Runs {@see ManifestValidator} (the same check the host runs when it loads a
 * manifest plugin, and what the builder and the store run) and then what needs
 * the plugin's files: each theme file `themes` lists is read and checked with
 * the host's {@see ThemeValidator}. Needs no database and no running Whity.
 */
final class PluginManifestCheck
{
    private function __construct()
    {
    }

    /**
     * @param string $path the plugin's directory, or its plugin.json
     * @return array{ok: bool, file: string, name: ?string, version: ?string, errors: list<string>}
     */
    public static function run(string $path): array
    {
        $file = is_dir($path) ? rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'plugin.json' : $path;
        $result = ['ok' => false, 'file' => $file, 'name' => null, 'version' => null, 'errors' => []];

        if (!is_file($file)) {
            $result['errors'][] = "There is no plugin.json at {$file}.";

            return $result;
        }
        $raw = (string) file_get_contents($file);
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $result['errors'][] = 'plugin.json is not valid JSON: ' . $e->getMessage();

            return $result;
        }
        if (!is_array($manifest)) {
            $result['errors'][] = 'plugin.json must be a JSON object.';

            return $result;
        }

        $plugin = is_array($manifest['plugin'] ?? null) ? $manifest['plugin'] : [];
        $result['name'] = is_string($plugin['name'] ?? null) ? $plugin['name'] : null;
        $result['version'] = is_string($plugin['version'] ?? null) ? $plugin['version'] : null;

        $errors = ManifestValidator::validate($manifest)['errors'];

        $themes = $manifest['themes'] ?? null;
        if (is_array($themes) && array_is_list($themes)) {
            $dir = dirname($file);
            foreach ($themes as $i => $theme) {
                if (!is_string($theme) || str_contains($theme, '..')) {
                    continue; // ManifestValidator already named it.
                }
                $themeFile = $dir . DIRECTORY_SEPARATOR . $theme;
                $decoded = is_file($themeFile) ? json_decode((string) file_get_contents($themeFile), true) : null;
                if (!is_array($decoded)) {
                    $errors[] = "themes[{$i}]: {$theme} " . (is_file($themeFile) ? "isn't valid JSON" : "doesn't exist");
                    continue;
                }
                foreach (ThemeValidator::validate($decoded) as $error) {
                    $errors[] = "themes[{$i}] ({$theme}): {$error}";
                }
                if (is_string($decoded['id'] ?? null) && str_starts_with($decoded['id'], 'whity.')) {
                    $errors[] = "themes[{$i}] ({$theme}): ids starting with \"whity.\" are Whity's own";
                }
            }
        }

        $result['errors'] = $errors;
        $result['ok'] = $errors === [];

        return $result;
    }
}
