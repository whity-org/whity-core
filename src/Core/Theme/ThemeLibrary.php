<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

/**
 * The themes a workspace can choose (TH.13, TH.14): Whity's own, shipped in
 * `themes/`, then the ones plugins on in the workspace ship (SDK 1.125,
 * PluginThemesInterface), then the ones it imported or installed from the
 * store. Applying any of them is free. An id already taken by an earlier
 * source is not offered again.
 */
final class ThemeLibrary
{
    public const DEFAULT_ID = 'whity.default';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $builtIn = null;

    /**
     * @param (\Closure(): list<array{plugin: string, theme: array<string, mixed>}>)|null $pluginThemes
     *        The plugins' themes, already checked (PluginLoader::getPluginThemes).
     */
    public function __construct(
        private readonly ?ThemeRepository $repository = null,
        private readonly ?\Closure $pluginThemes = null,
    ) {
    }

    /**
     * Whity's own themes, by id, default first.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function builtIn(): array
    {
        if (self::$builtIn === null) {
            $themes = [];
            $files = glob(__DIR__ . '/themes/*.json') ?: [];
            sort($files);
            foreach ($files as $file) {
                $theme = json_decode((string) file_get_contents($file), true);
                if (is_array($theme) && ThemeValidator::validate($theme) === [] && str_starts_with((string) $theme['id'], 'whity.')) {
                    $themes[$theme['id']] = $theme;
                }
            }
            if (isset($themes[self::DEFAULT_ID])) {
                $themes = [self::DEFAULT_ID => $themes[self::DEFAULT_ID]] + $themes;
            }
            self::$builtIn = $themes;
        }

        return self::$builtIn;
    }

    /**
     * Every theme the workspace can choose, with where it came from.
     *
     * @return list<array{theme: array<string, mixed>, source: string, signature_verified: bool, plugin: ?string}>
     */
    public function available(int $tenantId): array
    {
        $out = [];
        $seen = [];
        foreach (self::builtIn() as $id => $theme) {
            $out[] = ['theme' => $theme, 'source' => 'whity', 'signature_verified' => true, 'plugin' => null];
            $seen[$id] = true;
        }
        foreach ($this->pluginThemes() as $entry) {
            $id = (string) $entry['theme']['id'];
            if (!isset($seen[$id])) {
                $out[] = ['theme' => $entry['theme'], 'source' => 'plugin', 'signature_verified' => false, 'plugin' => $entry['plugin']];
                $seen[$id] = true;
            }
        }
        if ($this->repository !== null && $tenantId > 0) {
            foreach ($this->repository->forTenant($tenantId) as $row) {
                if (!isset($seen[(string) $row['theme']['id']])) {
                    $out[] = $row + ['plugin' => null];
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null The theme, or null when the workspace has none by that id. */
    public function find(int $tenantId, string $id): ?array
    {
        if (isset(self::builtIn()[$id])) {
            return self::builtIn()[$id];
        }
        foreach ($this->pluginThemes() as $entry) {
            if ($entry['theme']['id'] === $id) {
                return $entry['theme'];
            }
        }
        if ($this->repository === null || $tenantId <= 0) {
            return null;
        }
        foreach ($this->repository->forTenant($tenantId) as $row) {
            if ($row['theme']['id'] === $id) {
                return $row['theme'];
            }
        }

        return null;
    }

    /** @return list<array{plugin: string, theme: array<string, mixed>}> */
    private function pluginThemes(): array
    {
        if ($this->pluginThemes === null) {
            return [];
        }
        try {
            return ($this->pluginThemes)();
        } catch (\Throwable $e) {
            error_log('[ThemeLibrary] plugin themes unavailable: ' . $e->getMessage());

            return [];
        }
    }
}
