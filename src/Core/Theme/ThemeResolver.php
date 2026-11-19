<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

use Whity\Core\Settings\SettingsRegistry;

/**
 * A workspace's resolved theme (30-themes.md section 5): the chosen theme (or
 * the installation's, or Whity's), with the workspace's main colour as the
 * primary seed and its colour overrides on top, compiled with the guard
 * rails. A person's own light or dark, text size and animations are applied
 * by the app; their Higher contrast (TH.12) is the `high` set, written under
 * `[data-contrast="high"]`.
 */
final class ThemeResolver
{
    /**
     * @param array<string, string> $settings The workspace's effective settings.
     * @return array{
     *   theme: array<string, mixed>|null,
     *   high: array{light: array<string, string>, dark: array<string, string>},
     *   mainColor: ?string
     * }
     */
    public static function resolve(array $settings, ThemeLibrary $library, int $tenantId): array
    {
        $chosen = trim((string) ($settings[SettingsRegistry::BRANDING_THEME] ?? ''));
        $theme = ($chosen !== '' ? $library->find($tenantId, $chosen) : null)
            ?? ThemeLibrary::builtIn()[ThemeLibrary::DEFAULT_ID]
            ?? ['schemaVersion' => 1, 'id' => ThemeLibrary::DEFAULT_ID, 'version' => '1.0.0', 'name' => ['en' => 'Whity']];
        $missing = $chosen !== '' && $theme['id'] !== $chosen;

        $seed = trim((string) ($settings[SettingsRegistry::BRANDING_MAIN_COLOR] ?? ''));
        $seed = preg_match(ThemeValidator::HEX_RE, $seed) === 1 ? strtolower($seed) : null;
        $overrides = [];
        $rawOverrides = trim((string) ($settings[SettingsRegistry::BRANDING_THEME_OVERRIDES] ?? ''));
        if ($rawOverrides !== '') {
            $decoded = json_decode($rawOverrides, true);
            if (is_array($decoded) && ThemeValidator::validateOverrides($decoded) === []) {
                $overrides = $decoded;
            }
        }

        $compiled = ThemeCompiler::compile($theme, $seed, $overrides);
        $high = ThemeCompiler::compile($theme, $seed, $overrides, 'high');
        if ($missing) {
            $compiled['adjustments'][] = ['mode' => 'light', 'role' => 'theme', 'change' => 'theme-missing'];
        }

        $isDefault = $compiled['light'] === [] && $compiled['dark'] === [] && $compiled['vars'] === [] && !$missing;

        return [
            'theme' => $isDefault ? null : [
                'id' => (string) $theme['id'],
                'version' => (string) $theme['version'],
                'light' => $compiled['light'],
                'dark' => $compiled['dark'],
                'vars' => $compiled['vars'],
                'fonts' => $compiled['fonts'],
                'adjustments' => $compiled['adjustments'],
            ],
            'high' => ['light' => self::diff($high['light'], $compiled['light']), 'dark' => self::diff($high['dark'], $compiled['dark'])],
            'mainColor' => $seed === null ? null : \Whity\Core\Branding\TenantTheme::derive($seed)['color'],
        ];
    }

    /**
     * @param array<string, string> $high
     * @param array<string, string> $standard
     * @return array<string, string>
     */
    private static function diff(array $high, array $standard): array
    {
        return array_filter($high, static fn (string $v, string $k): bool => ($standard[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
    }
}
