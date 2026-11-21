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
use Whity\Core\Theme\ThemeImporter;
use Whity\Core\Theme\ThemeLibrary;
use Whity\Core\Theme\ThemeRepository;
use Whity\Core\Theme\ThemeTweaks;
use Whity\Core\Theme\ThemeValidator;

/**
 * The themes a workspace can choose, and importing a theme file (WHIT-1225,
 * TH.13, TH.14). Applying a theme is free: choosing one is the
 * `branding_theme` setting through `PATCH /api/settings`, like the main
 * colour. Making themes is free in core too (TH.25): converting from other
 * tools here, and the editor.
 *
 *   GET    /api/v1/themes            settings:read   the list, each with a preview
 *   GET    /api/v1/themes/export     settings:read   the theme in use as a file (TH.16)
 *   POST   /api/v1/themes            settings:write  import a theme file (JSON body);
 *                                                    `?check=1` only checks it and says
 *                                                    what a newer version changes
 *   POST   /api/v1/themes/convert    settings:write  turn shadcn CSS, a Tailwind @theme block
 *                                                    or Material Theme Builder JSON into a
 *                                                    theme file; nothing is saved (TH.25)
 *   DELETE /api/v1/themes/{themeId}  settings:write  remove an imported theme
 *
 * The free tweaks (TH.15: shape, density, font pair) are part of the
 * `branding_theme_overrides` setting, saved like the colour overrides.
 */
final class ThemesApiHandler
{
    private const MAX_BYTES = 32768;

    public function __construct(
        private readonly ThemeRepository $repository,
        private readonly SettingsService $settings,
        // SDK 1.125: the themes plugins ship (PluginLoader::getPluginThemes).
        private readonly ?\Closure $pluginThemes = null,
    ) {
    }

    public function list(Request $request): Response
    {
        // The system tenant lists Whity's own themes for the installation's
        // theme (the global branding_theme); importing needs a workspace.
        return $this->inWorkspace(function (int $tenantId): Response {
            [$chosen, $seed, $overrides] = $this->adjustments($tenantId);

            $library = new ThemeLibrary($this->repository, $this->pluginThemes);
            $current = $chosen !== '' && $library->find($tenantId, $chosen) !== null ? $chosen : ThemeLibrary::DEFAULT_ID;
            $items = [];
            foreach ($library->available($tenantId) as $entry) {
                // Previews show the workspace's tweaks, as they show its main colour.
                $theme = ThemeTweaks::apply($entry['theme'], $overrides);
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

            // TH.20: each pair with its CSS stacks, so the picker previews it in its own faces.
            $fonts = array_map(static fn (string $id): array => ['id' => $id, 'name' => ThemeFonts::name($id), 'stack' => ThemeFonts::stack($id), 'heading_stack' => ThemeFonts::stack($id, true)], ThemeFonts::ids());

            return Response::json(['data' => [
                'current' => $current,
                'themes' => $items,
                // TH.15: what the workspace picked on top of the theme, null where it follows it.
                'tweaks' => ThemeTweaks::chosen($overrides),
                'options' => ['shape' => ThemeValidator::SHAPES, 'density' => ThemeValidator::DENSITIES, 'font' => $fonts],
                'colour_overrides' => [
                    'light' => is_array($overrides['light'] ?? null) && $overrides['light'] !== [] ? $overrides['light'] : new \stdClass(),
                    'dark' => is_array($overrides['dark'] ?? null) && $overrides['dark'] !== [] ? $overrides['dark'] : new \stdClass(),
                ],
            ]], 200);
        }, true);
    }

    /** TH.16: the theme in use as a theme file, free, with everything the workspace set folded in. */
    public function export(Request $request): Response
    {
        return $this->inWorkspace(function (int $tenantId): Response {
            [$chosen, $seed, $overrides] = $this->adjustments($tenantId);
            $library = new ThemeLibrary($this->repository, $this->pluginThemes);
            $theme = ($chosen !== '' ? $library->find($tenantId, $chosen) : null) ?? $library->find($tenantId, ThemeLibrary::DEFAULT_ID)
                ?? ThemeLibrary::builtIn()[ThemeLibrary::DEFAULT_ID];
            $values = $tenantId === 0 ? $this->settings->getGlobal() : $this->settings->effective($tenantId);
            $file = ThemeTweaks::export($theme, $seed, $overrides, (string) ($values[SettingsRegistry::SITE_NAME] ?? ''));
            if (ThemeValidator::validate($file) !== []) {
                return Response::error('The theme in use could not be exported.', 500);
            }
            $name = preg_replace('/[^a-z0-9.-]/', '', (string) $file['id']) . '-' . (string) $file['version'] . '.json';

            return Response::json(['data' => ['theme' => $file, 'filename' => $name]], 200);
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
            $installed = (new ThemeLibrary($this->repository, $this->pluginThemes))->find($tenantId, (string) $theme['id']);
            if (self::queryParam($request, 'check') === '1') {
                // TH.16: say what a newer version changes before it replaces the old one.
                return Response::json(['data' => [
                    'id' => $theme['id'],
                    'version' => $theme['version'],
                    'installed_version' => $installed['version'] ?? null,
                    'changes' => $installed === null ? [] : ThemeTweaks::changes($installed, $theme),
                ]], 200);
            }
            $this->repository->save($tenantId, $theme, 'import', false, self::profileId($request));

            return Response::json(['data' => ['id' => $theme['id'], 'version' => $theme['version']]], 201);
        });
    }

    /**
     * TH.25 (30-themes 11, 14.7): a theme made with shadcn, Tailwind or
     * Material Theme Builder, as a Whity theme file with what was kept in
     * words and a preview. Nothing is saved; importing it is `POST /themes`.
     */
    public function convert(Request $request): Response
    {
        return $this->inWorkspace(function () use ($request): Response {
            if (strlen($request->getBody()) > ThemeImporter::MAX_BYTES + 1024) {
                return Response::error('This is too long to be a theme. Paste only the colour variables.', 413);
            }
            $body = json_decode($request->getBody(), true);
            $format = is_array($body) && is_string($body['format'] ?? null) ? $body['format'] : '';
            $source = is_array($body) && is_string($body['source'] ?? null) ? $body['source'] : '';
            $name = is_array($body) && is_string($body['name'] ?? null) ? $body['name'] : '';
            try {
                $result = ThemeImporter::convert($format, $source, $name);
            } catch (\InvalidArgumentException $e) {
                return Response::error('This could not be turned into a theme.', 422, ['source' => $e->getMessage()]);
            }
            $compiled = ThemeCompiler::compile($result['theme']);
            if ($compiled['adjustments'] !== []) {
                $result['notes'][] = count($compiled['adjustments']) === 1
                    ? 'One colour is changed when the theme is used, so text on it stays readable.'
                    : count($compiled['adjustments']) . ' colours are changed when the theme is used, so text on them stays readable.';
            }

            return Response::json(['data' => [
                'theme' => $result['theme'],
                'notes' => $result['notes'],
                'preview' => [
                    'light' => self::swatch($compiled['light'], 'light'),
                    'dark' => self::swatch($compiled['dark'], 'dark'),
                    'radius' => $compiled['vars']['wy-radius-control'] ?? '10px',
                    'card_radius' => $compiled['vars']['wy-radius-card'] ?? '12px',
                ],
                'adjustments' => $compiled['adjustments'],
            ]], 200);
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
     * The workspace's choice, main colour and adjustments (colour overrides and tweaks).
     *
     * @return array{0: string, 1: ?string, 2: array<string, mixed>}
     */
    private function adjustments(int $tenantId): array
    {
        $values = $tenantId === 0 ? $this->settings->getGlobal() : $this->settings->effective($tenantId);
        $chosen = trim((string) ($values[SettingsRegistry::BRANDING_THEME] ?? ''));
        $seed = trim((string) ($values[SettingsRegistry::BRANDING_MAIN_COLOR] ?? ''));
        $seed = preg_match(ThemeValidator::HEX_RE, $seed) === 1 ? strtolower($seed) : null;
        $overrides = json_decode((string) ($values[SettingsRegistry::BRANDING_THEME_OVERRIDES] ?? ''), true);
        $overrides = is_array($overrides) && ThemeValidator::validateOverrides($overrides) === [] ? $overrides : [];

        return [$chosen, $seed, $overrides];
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

    private static function queryParam(Request $request, string $name): ?string
    {
        $value = isset($_GET[$name]) && is_string($_GET[$name]) ? $_GET[$name] : null;
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (isset($params[$name]) && is_string($params[$name])) {
                $value = $params[$name];
            }
        }

        return $value;
    }

    private static function profileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
    }
}
