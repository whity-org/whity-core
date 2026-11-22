<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

use ZipArchive;

/**
 * A theme as a store-ready package (TH.25, 30-themes 11): a theme-only
 * plugin zip that ships the theme through the plugin theme interface
 * (SDK 1.125), the way the workspace templates ship theirs. The publisher
 * downloads it and submits it to the store; a direct upload comes when the
 * Whity ID store module has a publisher API.
 *
 * The package is the plugin installer's own format: one top-level folder
 * named after the plugin, one plugin class, nothing else that runs. The
 * class's only job is to return the theme file next to it. Nothing a person
 * typed is written into code: the class and namespace come from the theme id
 * (letters and digits only), and the name and version are read from the
 * theme file at runtime. `manifest.json` lists every file with its sha256,
 * and the same theme always makes the same bytes.
 */
final class ThemePackage
{
    /** The SDK minor that added plugin themes. */
    public const SDK_CONSTRAINT = '^1.125';

    /** Fixed file times, so the zip's hash depends only on its contents. */
    private const MTIME = 1_735_689_600; // 2025-01-01T00:00:00Z

    /**
     * @param array<string, mixed> $theme A valid theme file (ThemeValidator).
     * @return array{plugin: string, filename: string, media_type: string, sha256: string, size_bytes: int, content: string}
     */
    public static function build(array $theme): array
    {
        $id = (string) $theme['id'];
        $version = (string) $theme['version'];
        $base = self::studly($id);
        $plugin = $base . 'Theme';
        $themeFile = 'src/themes/' . preg_replace('/[^a-z0-9.-]/', '', $id) . '.json';

        $files = [
            'composer.json' => self::json([
                'name' => 'whity-theme/' . str_replace('.', '-', $id),
                'description' => 'A Whity theme: ' . self::plain((string) ($theme['name']['en'] ?? $id)) . '. Tokens only; no code runs beyond returning the theme file.',
                'type' => 'whity-plugin',
                'version' => $version,
                'license' => 'proprietary',
                'require' => ['php' => '>=8.4', 'whity/plugin-sdk' => self::SDK_CONSTRAINT],
                'autoload' => ['psr-4' => ["WhityTheme\\{$base}\\" => 'src/']],
            ]),
            $themeFile => self::json($theme),
            "src/{$plugin}Plugin.php" => self::code($base, $plugin, basename($themeFile)),
            'README.md' => self::readme($theme, $plugin),
        ];
        ksort($files);
        $manifest = [
            'kind' => 'theme',
            'plugin' => $plugin,
            'theme' => $id,
            'version' => $version,
            'name' => $theme['name'],
            'sdk' => self::SDK_CONSTRAINT,
            'files' => array_map(static fn (string $content): string => hash('sha256', $content), $files),
        ];
        $files['manifest.json'] = self::json($manifest);

        $content = self::zip($plugin, $files);

        return [
            'plugin' => $plugin,
            'filename' => $plugin . '-' . $version . '.zip',
            'media_type' => 'application/zip',
            'sha256' => hash('sha256', $content),
            'size_bytes' => strlen($content),
            'content' => $content,
        ];
    }

    /** `acme.harbour-blue` → `AcmeHarbourBlue`; a leading digit gets `Theme`. */
    private static function studly(string $id): string
    {
        $out = str_replace(' ', '', ucwords((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($id))));

        return preg_match('/^[A-Za-z]/', $out) === 1 ? $out : 'Theme' . $out;
    }

    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[\x00-\x1f]/', ' ', $text)));
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** The plugin class: identifiers only; everything a person wrote is read from the JSON. */
    private static function code(string $base, string $plugin, string $themeFile): string
    {
        $sdk = self::SDK_CONSTRAINT;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace WhityTheme\\{$base};

            use Whity\\Sdk\\Contribute\\PluginThemesInterface;
            use Whity\\Sdk\\PluginDisplayNameInterface;
            use Whity\\Sdk\\PluginInterface;
            use Whity\\Sdk\\PluginRequirementsInterface;

            /**
             * A theme, packaged by Whity's theme editor for the store. It returns
             * the theme file next to it and does nothing else.
             */
            final class {$plugin}Plugin implements PluginInterface, PluginDisplayNameInterface, PluginRequirementsInterface, PluginThemesInterface
            {
                private const FILE = __DIR__ . '/themes/{$themeFile}';

                public function getName(): string
                {
                    return '{$plugin}';
                }

                /** @return array<string, string> */
                public function getDisplayName(): array
                {
                    \$name = \$this->theme()['name'] ?? null;

                    return is_array(\$name) ? array_filter(\$name, 'is_string') : ['en' => '{$plugin}'];
                }

                public function getVersion(): string
                {
                    return (string) (\$this->theme()['version'] ?? '0.0.0');
                }

                public function getSdkConstraint(): string
                {
                    return '{$sdk}';
                }

                public function getCoreConstraint(): string
                {
                    return '';
                }

                public function getPluginDependencies(): array
                {
                    return [];
                }

                public function getPermissions(): array
                {
                    return [];
                }

                public function getMigrations(): array
                {
                    return [];
                }

                public function getHooks(): array
                {
                    return [];
                }

                public function getRoutes(): array
                {
                    return [];
                }

                /** @return list<array<string, mixed>> */
                public function getThemes(): array
                {
                    \$theme = \$this->theme();

                    return \$theme === [] ? [] : [\$theme];
                }

                /** @return array<string, mixed> */
                private function theme(): array
                {
                    \$decoded = json_decode((string) @file_get_contents(self::FILE), true);

                    return is_array(\$decoded) && !array_is_list(\$decoded) ? \$decoded : [];
                }
            }

            PHP;
    }

    /** @param array<string, mixed> $theme */
    private static function readme(array $theme, string $plugin): string
    {
        $name = self::plain((string) ($theme['name']['en'] ?? $theme['id']));

        return "# {$name}\n\n"
            . "A Whity theme ({$theme['id']} {$theme['version']}), packaged by the theme editor as the plugin {$plugin}.\n\n"
            . "It is tokens only: colours, type, corners, spacing, shadows and motion, checked by Whity's guard rails when it is used.\n"
            . "The plugin's code only returns `src/themes/` to Whity. `manifest.json` lists every file with its sha256.\n\n"
            . "Submit this file to the Whity store as its publisher. Installing and applying a theme is free.\n";
    }

    /** @param array<string, string> $files */
    private static function zip(string $plugin, array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'whity_theme_pkg_');
        if ($path === false) {
            throw new \RuntimeException('Could not make the theme package.');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not make the theme package.');
            }
            $zip->addEmptyDir($plugin);
            $zip->setMtimeName($plugin . '/', self::MTIME);
            foreach ($files as $name => $content) {
                $entry = $plugin . '/' . $name;
                $zip->addFromString($entry, $content);
                $zip->setMtimeName($entry, self::MTIME);
                $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0644 << 16);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
