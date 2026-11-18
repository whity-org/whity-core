<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

use Whity\Core\Branding\BrandingService;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Theme\WorkspaceTheme;

/**
 * The host side of {@see WorkspaceTheme} (SDK 1.126): the theme of the
 * workspace in the request's context (D2), read-only. With no workspace it
 * answers for the installation.
 */
final class HostWorkspaceTheme implements WorkspaceTheme
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly SettingsService $settings,
        private readonly ThemeLibrary $library,
    ) {
    }

    public function current(): array
    {
        $branding = $this->branding->effective($this->workspace());
        $theme = $branding->theme ?? [];
        $fonts = is_array($theme['fonts'] ?? null) ? $theme['fonts'] : [];

        return [
            'id' => is_string($theme['id'] ?? null) ? $theme['id'] : ThemeLibrary::DEFAULT_ID,
            'version' => is_string($theme['version'] ?? null) ? $theme['version'] : '1.0.0',
            'mainColor' => $branding->mainColor,
            'light' => self::strings($theme['light'] ?? []),
            'dark' => self::strings($theme['dark'] ?? []),
            'vars' => self::strings($theme['vars'] ?? []),
            'fonts' => [
                'body' => is_string($fonts['body'] ?? null) ? $fonts['body'] : ThemeFonts::DEFAULT,
                'heading' => is_string($fonts['heading'] ?? null) ? $fonts['heading'] : ThemeFonts::DEFAULT,
            ],
        ];
    }

    public function installed(): array
    {
        $workspace = $this->workspace();
        $values = $workspace === 0 ? $this->settings->getGlobal() : $this->settings->effective($workspace);
        $chosen = trim((string) ($values[SettingsRegistry::BRANDING_THEME] ?? ''));
        $current = $chosen !== '' && $this->library->find($workspace, $chosen) !== null ? $chosen : ThemeLibrary::DEFAULT_ID;

        $out = [];
        foreach ($this->library->available($workspace) as $entry) {
            $id = (string) $entry['theme']['id'];
            $out[] = [
                'id' => $id,
                'version' => (string) $entry['theme']['version'],
                'name' => self::strings($entry['theme']['name'] ?? []),
                'source' => $entry['source'],
                'plugin' => $entry['plugin'],
                'current' => $id === $current,
            ];
        }

        return $out;
    }

    private function workspace(): int
    {
        $workspace = TenantContext::getTenantId();

        return $workspace !== null && $workspace > 0 ? $workspace : 0;
    }

    /** @return array<string, string> */
    private static function strings(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }

        return array_filter($map, static fn (mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }
}
