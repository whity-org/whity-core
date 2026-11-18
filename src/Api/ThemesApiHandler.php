<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Theme\ThemeCompiler;
use Whity\Core\Theme\ThemeFonts;
use Whity\Core\Theme\ThemeLibrary;
use Whity\Core\Theme\ThemeRepository;
use Whity\Core\Theme\ThemeValidator;

/**
 * The themes a workspace can choose, and importing a theme file (WHIT-1225,
 * TH.13, TH.14). Applying a theme is free: choosing one is the
 * `branding_theme` setting through `PATCH /api/settings`, like the main
 * colour. Making themes is Whity Studio's (paid); core only takes a finished
 * file.
 *
 *   GET    /api/v1/themes            settings:read   the list, each with a preview
 *   POST   /api/v1/themes            settings:write  import a theme file (JSON body)
 *   DELETE /api/v1/themes/{themeId}  settings:write  remove an imported theme
 */
final class ThemesApiHandler
{
    private const MAX_BYTES = 32768;

    public function __construct(
        private readonly ThemeRepository $repository,
        private readonly SettingsService $settings,
        // SDK 1.126: the themes plugins ship (PluginLoader::getPluginThemes).
        private readonly ?\Closure $pluginThemes = null,
    ) {
    }

    public function list(Request $request): Response
    {
        // The system tenant lists Whity's own themes for the installation's
        // theme (the global branding_theme); importing needs a workspace.
        return $this->inWorkspace(function (int $tenantId): Response {
            $values = $tenantId === 0 ? $this->settings->getGlobal() : $this->settings->effective($tenantId);
            $chosen = trim((string) ($values[SettingsRegistry::BRANDING_THEME] ?? ''));
            $seed = trim((string) ($values[SettingsRegistry::BRANDING_MAIN_COLOR] ?? ''));
            $seed = preg_match(ThemeValidator::HEX_RE, $seed) === 1 ? strtolower($seed) : null;
            $overrides = json_decode((string) ($values[SettingsRegistry::BRANDING_THEME_OVERRIDES] ?? ''), true);
            $overrides = is_array($overrides) && ThemeValidator::validateOverrides($overrides) === [] ? $overrides : [];

            $library = new ThemeLibrary($this->repository, $this->pluginThemes);
            $current = $chosen !== '' && $library->find($tenantId, $chosen) !== null ? $chosen : ThemeLibrary::DEFAULT_ID;
            $items = [];
            foreach ($library->available($tenantId) as $entry) {
                $theme = $entry['theme'];
                $compiled = ThemeCompiler::compile($theme, $seed, $overrides);
                $items[] = [
                    'id' => $theme['id'],
                    'version' => $theme['version'],
                    'name' => $theme['name'],
                    'description' => $theme['description'] ?? null,
                    'author' => $theme['author']['name'] ?? null,
                    'source' => $entry['source'],
                    'plugin' => $entry['plugin'],
                    'signature_verified' => $entry['signature_verified'],
                    'current' => $theme['id'] === $current,
                    // The main colour replaces this theme's own colour (layer 4).
                    'main_color_replaces_seed' => $seed !== null && isset($theme['colour']['seeds']['primary']),
                    'fonts' => ['body' => ThemeFonts::name($compiled['fonts']['body']), 'heading' => ThemeFonts::name($compiled['fonts']['heading'])],
                    'preview' => [
                        'light' => self::swatch($compiled['light'], 'light'),
                        'dark' => self::swatch($compiled['dark'], 'dark'),
                        'radius' => $compiled['vars']['wy-radius-control'] ?? '10px',
                        'card_radius' => $compiled['vars']['wy-radius-card'] ?? '12px',
                    ],
                    'adjustments' => $compiled['adjustments'],
                ];
            }

            return Response::json(['data' => ['current' => $current, 'themes' => $items]], 200);
        }, true);
    }

    public function import(Request $request): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($request): Response {
            $body = $request->getBody();
            if (strlen($body) > self::MAX_BYTES) {
                return Response::error('The theme file is too large.', 413);
            }
            $decoded = json_decode($body, true);
            $theme = is_array($decoded) && is_array($decoded['theme'] ?? null) ? $decoded['theme'] : $decoded;
            $errors = ThemeValidator::validate($theme);
            if ($errors === [] && str_starts_with((string) $theme['id'], 'whity.')) {
                $errors[] = 'Theme ids starting with "whity." are Whity\'s own. Use your own name, like acme.harbour.';
            }
            if ($errors !== []) {
                return Response::error('This theme file can\'t be used.', 422, ['theme' => implode(' ', array_slice($errors, 0, 5))]);
            }
            $this->repository->save($tenantId, $theme, 'import', false, self::profileId($request));

            return Response::json(['data' => ['id' => $theme['id'], 'version' => $theme['version']]], 201);
        });
    }

    /** @param array<string, mixed> $params */
    public function delete(Request $request, array $params = []): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($params): Response {
            $id = (string) ($params['themeId'] ?? '');
            if (preg_match(ThemeValidator::ID_RE, $id) !== 1 || str_starts_with($id, 'whity.')) {
                return Response::error('Only themes this workspace imported can be removed.', 422);
            }
            if (!$this->repository->delete($tenantId, $id)) {
                return Response::error('Theme not found', 404);
            }
            // A removed theme leaves the workspace on the installation's (section 5).
            if (($this->settings->effective($tenantId)[SettingsRegistry::BRANDING_THEME] ?? '') === $id) {
                $this->settings->setTenant($tenantId, SettingsRegistry::BRANDING_THEME, null);
            }

            return Response::json(['data' => ['deleted' => true]], 200);
        });
    }

    /**
     * The colours a picker card shows: the page, a card, text and the main button.
     *
     * @param array<string, string> $tokens
     * @return array<string, string>
     */
    private static function swatch(array $tokens, string $mode): array
    {
        $base = ThemeCompiler::base($mode);
        $out = [];
        foreach (['background', 'card', 'foreground', 'muted-foreground', 'primary', 'primary-foreground', 'border', 'tertiary'] as $role) {
            $out[$role] = $tokens[$role] ?? $base[$role] ?? ($mode === 'dark' ? '#333333' : '#e5e5e5');
        }

        return $out;
    }

    private function inWorkspace(callable $then, bool $allowSystem = false): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || ($tenantId === 0 && !$allowSystem)) {
                return Response::error('Open a workspace to choose its theme.', 403);
            }

            return $then($tenantId);
        } catch (Throwable $e) {
            error_log('[ThemesApiHandler] ' . $e->getMessage());

            return Response::error('The request could not be processed', 500);
        }
    }

    private static function profileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
    }
}
