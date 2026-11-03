<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Exception\PluginAlreadyInstalled;
use Whity\Core\Exception\PluginExtractionUnsafe;
use Whity\Core\Exception\PluginIncompatible;
use Whity\Core\Exception\PluginNameUnsafe;
use Whity\Core\Exception\PluginPackageInvalid;
use Whity\Core\Http\HttpFetcher;
use Whity\Core\Plugin\PluginOperatorGate;
use Whity\Core\Plugin\PluginRecords;
use Whity\Core\Plugin\StoreCatalogueEntry;
use Whity\Core\PluginInstaller;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * POST /api/plugins/install-from-store — fetch a plugin package from a TRUSTED
 * plugin store and install it (landing DISABLED) through the SAME hardened
 * {@see PluginInstaller} pipeline as a manual upload.
 *
 * SECURITY — this endpoint makes the SERVER fetch an operator-supplied URL, so
 * SSRF is the headline risk. Three layers, checked in order (all reachable
 * WITHOUT a live store — every negative path returns before any outbound call):
 *   0. MASTER SWITCH (WC-feature-flags-audit) — `plugins.store_enabled`
 *      (default 'true'). An additional global kill-switch, same shape as
 *      `auth.sso_enabled`: lets an operator instantly disable the WHOLE store
 *      integration (install-from-store + the catalogue browser) without losing
 *      the allowlist below. Checked FIRST, before the allowlist itself.
 *   1. PRIMARY — an operator ALLOWLIST of trusted store hosts
 *      (`plugins.store_allowed_hosts`). Empty ⇒ the feature is OFF (403). The
 *      store URL's host must EXACTLY match an allowlisted host, so the server can
 *      only be aimed at hosts the operator vetted, never arbitrary internal ones.
 *   2. DEFENSE-IN-DEPTH — {@see HttpFetcher} enforces https + public-IP-only + no
 *      redirects + TLS verification on the resolved URL (its documented DNS-rebind
 *      TOCTOU is why the allowlist, not the IP guard, is the primary control).
 * The fetched bytes are NEVER trusted: they run the full PluginInstaller
 * validation (zip-slip/bomb, name allowlist, SDK gate, isolated introspection),
 * identical to an upload. The store token is sent as a bearer and never logged.
 * Internal errors never leak to the client (WC-186).
 */
final class InstallFromStoreApiHandler
{
    /** @var \Closure(string, array<string, string>): ?string */
    private \Closure $fetchPackage;

    /** @var \Closure(string): ?array<string, mixed> */
    private \Closure $fetchJson;

    /**
     * @param \Closure(string, array<string, string>): ?string|null $fetchPackage
     *   Injectable package fetcher (url, headers) => raw bytes|null. Defaults to a
     *   size-capped, SSRF-guarded HttpFetcher; stubbed in tests.
     * @param \Closure(string): ?array<string, mixed>|null $fetchJson
     *   Injectable JSON fetcher (url) => decoded array|null, used to read a store's
     *   public catalogue. Defaults to the same SSRF-guarded HttpFetcher; stubbed
     *   in tests.
     */
    public function __construct(
        private readonly string $pluginDir,
        private readonly SettingsService $settings,
        private readonly ?PluginLoader $pluginLoader = null,
        private readonly ?AuditLogger $auditLogger = null,
        ?\Closure $fetchPackage = null,
        ?\Closure $fetchJson = null,
        private readonly ?PluginRecords $records = null,
    ) {
        $this->fetchPackage = $fetchPackage ?? static fn (string $url, array $headers): ?string
            => (new HttpFetcher(timeoutSeconds: 15, maxBytes: PluginInstaller::MAX_UPLOAD_BYTES))
                ->getBinary($url, $headers);
        $this->fetchJson = $fetchJson ?? static fn (string $url): ?array
            => (new HttpFetcher(timeoutSeconds: 15))->getJson($url);
    }

    /**
     * @param array<string, string> $params Route params (unused; none in the path).
     */
    public function install(Request $request, array $params = []): Response
    {
        $refusal = PluginOperatorGate::refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $body = json_decode($request->getBody(), true);
        if (!is_array($body)) {
            return Response::error('A JSON body is required.', 400);
        }

        $storeUrl = trim((string) ($body['store_url'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));
        $version = trim((string) ($body['version'] ?? ''));
        $token = (string) ($body['token'] ?? '');

        if ($storeUrl === '' || $slug === '' || $version === '') {
            return Response::error('store_url, slug and version are required.', 422);
        }
        // Safe path segments — never let slug/version inject into the download path.
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $slug) !== 1) {
            return Response::error('Invalid slug.', 422);
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/', $version) !== 1) {
            return Response::error('Invalid version.', 422);
        }

        // ── SSRF allowlist (primary control) ─────────────────────────────────
        [$origin, $originError] = $this->resolveStoreOrigin($storeUrl);
        if ($originError !== null) {
            return $originError;
        }

        // ── Fetch (SSRF-guarded, token-authenticated) ────────────────────────
        $downloadUrl = $origin
            . '/api/v1/plugin-store/plugins/' . rawurlencode($slug)
            . '/versions/' . rawurlencode($version) . '/download';

        $headers = [];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $bytes = ($this->fetchPackage)($downloadUrl, $headers);
        } catch (\Throwable $e) {
            // HttpFetcher guard refusal / transport error — logged, never leaked.
            error_log('[InstallFromStore] fetch refused/failed: ' . $e->getMessage());
            return Response::error('The store package could not be fetched.', 502);
        }
        if ($bytes === null || $bytes === '') {
            return Response::error('The store rejected the request or the package was not found.', 502);
        }

        // What the store says it reaches, for the consent record. Display data only.
        $listed = $this->records !== null ? $this->catalogueEntry((string) $origin, $slug) : null;

        // ── Install via the hardened pipeline (bytes never trusted) ───────────
        $installer = new PluginInstaller($this->pluginDir, $this->pluginLoader, $this->auditLogger);
        try {
            $entry = $installer->installFromBytes($bytes);
        } catch (PluginPackageInvalid | PluginNameUnsafe | PluginExtractionUnsafe $e) {
            return Response::error($e->clientMessage(), 400, $e->clientDetails());
        } catch (PluginIncompatible $e) {
            return Response::error($e->clientMessage(), 422, $e->clientDetails());
        } catch (PluginAlreadyInstalled $e) {
            return Response::error($e->clientMessage(), 409, $e->clientDetails());
        } catch (\Throwable $e) {
            error_log('[InstallFromStore] install failed: ' . $e->getMessage());
            return Response::error('Failed to install the plugin.', 500);
        }

        $inspected = $installer->lastInspected();
        if ($inspected !== null && $this->records !== null) {
            $domains = StoreCatalogueEntry::domains($listed['outside_domains'] ?? null);
            $this->records->recordInstall(
                $inspected['name'],
                'store',
                (string) parse_url((string) $origin, PHP_URL_HOST),
                $slug,
                $inspected['version'],
                $inspected['sha256'],
                $inspected['permissions'],
                $domains,
                PluginOperatorGate::actorProfile($request)
            );
            // S.3: "Allow and continue" is the consent, kept with the person and the list.
            $this->auditLogger?->record('plugin.installed', [
                'target_type' => 'plugin',
                'metadata' => [
                    'plugin' => $inspected['name'],
                    'version' => $inspected['version'],
                    'sha256' => $inspected['sha256'],
                    'store' => $origin,
                    'permissions' => $inspected['permissions'],
                    'outside_domains' => $domains,
                ],
            ]);
        }

        return Response::json(['data' => $entry], 201);
    }

    /**
     * GET /api/plugins/updates - newer versions of store-installed plugins, and
     * updates waiting for approval. An operator's list; a workspace gets none.
     *
     * @param array<string, string> $params
     */
    public function availableUpdates(Request $request, array $params = []): Response
    {
        if ($this->records === null || !PluginOperatorGate::isOperator()) {
            return Response::json(['data' => [], 'meta' => ['stores_unreachable' => 0]]);
        }

        $catalogues = [];
        $unreachable = [];
        $updates = [];
        foreach ($this->records->installs() as $name => $install) {
            if (($install['source'] ?? null) !== 'store' || !is_string($install['store_host'] ?? null)) {
                continue;
            }
            $host = $install['store_host'];
            if (!array_key_exists($host, $catalogues)) {
                $catalogues[$host] = $this->catalogue('https://' . $host);
                if ($catalogues[$host] === null) {
                    $unreachable[$host] = true;
                }
            }
            $listed = null;
            foreach ($catalogues[$host] ?? [] as $entry) {
                if ($entry['slug'] === ($install['store_slug'] ?? null)) {
                    $listed = $entry;
                }
            }
            $latest = is_string($listed['latest_version'] ?? null) ? $listed['latest_version'] : null;
            if ($latest !== null && version_compare($latest, (string) $install['version'], '>')) {
                $updates[] = [
                    'plugin' => (string) $name,
                    'installed_version' => (string) $install['version'],
                    'latest_version' => $latest,
                ];
            }
        }

        return Response::json(['data' => $updates, 'meta' => ['stores_unreachable' => count($unreachable)]]);
    }

    /**
     * POST /api/plugins/{name}/update {token?} - fetch the store's latest version.
     *
     * Nothing updates by itself: this is a person asking for it, because an update
     * is new code on the server. The package goes through the same checks as an
     * install and waits beside the old version. With no new access it applies
     * straight away. With new permissions or outside domains it waits for approval,
     * and the old version keeps running (S.6).
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $refusal = PluginOperatorGate::refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $name = $params['name'] ?? '';
        $install = $this->records?->install($name);
        if ($this->records === null || $install === null) {
            return Response::error('Plugin not found', 404);
        }
        if ($install['source'] !== 'store' || !is_string($install['store_host']) || !is_string($install['store_slug'])) {
            return Response::error('This plugin was not installed from a store.', 422);
        }
        if ($this->records->removal($name) !== null) {
            return Response::error('This plugin was uninstalled. Reinstall it first.', 409);
        }

        [$origin, $originError] = $this->resolveStoreOrigin('https://' . $install['store_host']);
        if ($originError !== null) {
            return $originError;
        }

        $listed = $this->catalogueEntry((string) $origin, $install['store_slug']);
        $latest = is_string($listed['latest_version'] ?? null) ? $listed['latest_version'] : null;
        if ($latest === null) {
            return Response::error('The store has no version of this plugin.', 502);
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/', $latest) !== 1) {
            return Response::error('Invalid version.', 502);
        }
        if (!version_compare($latest, (string) $install['version'], '>')) {
            return Response::error('This plugin is up to date.', 409);
        }

        $body = json_decode($request->getBody(), true);
        $token = is_array($body) ? (string) ($body['token'] ?? '') : '';
        $headers = $token !== '' ? ['Authorization' => 'Bearer ' . $token] : [];
        $downloadUrl = $origin
            . '/api/v1/plugin-store/plugins/' . rawurlencode($install['store_slug'])
            . '/versions/' . rawurlencode($latest) . '/download';

        try {
            $bytes = ($this->fetchPackage)($downloadUrl, $headers);
        } catch (\Throwable $e) {
            error_log('[InstallFromStore] update fetch refused/failed: ' . $e->getMessage());
            return Response::error('The store package could not be fetched.', 502);
        }
        if ($bytes === null || $bytes === '') {
            return Response::error('The store rejected the request or the package was not found.', 502);
        }

        $installer = new PluginInstaller($this->pluginDir, $this->pluginLoader, $this->auditLogger);
        try {
            $staged = $installer->stageUpdate($bytes, $name);
        } catch (PluginPackageInvalid | PluginNameUnsafe | PluginExtractionUnsafe $e) {
            return Response::error($e->clientMessage(), 400, $e->clientDetails());
        } catch (PluginIncompatible $e) {
            return Response::error($e->clientMessage(), 422, $e->clientDetails());
        } catch (\Throwable $e) {
            error_log('[InstallFromStore] update failed: ' . $e->getMessage());
            return Response::error('The update could not be prepared.', 500);
        }

        // The package decides what version it is, not the catalogue, and it may
        // never take a plugin backwards.
        if (!version_compare($staged['version'], (string) $install['version'], '>')) {
            $installer->discardStagedUpdate($name);
            return Response::error('The package is not newer than what is installed.', 422);
        }

        $domains = StoreCatalogueEntry::domains($listed['outside_domains'] ?? null);
        $newPermissions = array_values(array_diff($staged['permissions'], PluginRecords::decodeList($install['permissions'])));
        $newDomains = array_values(array_diff($domains, PluginRecords::decodeList($install['outside_domains'])));

        if ($newPermissions !== [] || $newDomains !== []) {
            $this->records->setPending($name, $staged['version'], $staged['sha256'], $staged['permissions'], $domains);
            $this->auditLogger?->record('plugin.update.waiting', [
                'target_type' => 'plugin',
                'metadata' => [
                    'plugin' => $name,
                    'from' => $install['version'],
                    'to' => $staged['version'],
                    'new_permissions' => $newPermissions,
                    'new_domains' => $newDomains,
                ],
            ]);

            return Response::json(['data' => [
                'status' => 'waiting_approval',
                'version' => $staged['version'],
                'new_permissions' => $newPermissions,
                'new_domains' => $newDomains,
            ]]);
        }

        return $this->applyUpdate($installer, $name, $install, $staged['version'], $staged['sha256'], $staged['permissions'], $domains);
    }

    /**
     * POST /api/plugins/{name}/update/approve - accept the waiting update's new access.
     *
     * @param array<string, string> $params
     */
    public function approveUpdate(Request $request, array $params): Response
    {
        $refusal = PluginOperatorGate::refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $name = $params['name'] ?? '';
        $install = $this->records?->install($name);
        if ($this->records === null || $install === null || !is_string($install['pending_version'] ?? null)) {
            return Response::error('No update is waiting for this plugin.', 404);
        }

        return $this->applyUpdate(
            new PluginInstaller($this->pluginDir, $this->pluginLoader, $this->auditLogger),
            $name,
            $install,
            $install['pending_version'],
            is_string($install['pending_sha256'] ?? null) ? $install['pending_sha256'] : null,
            PluginRecords::decodeList($install['pending_permissions'] ?? null),
            PluginRecords::decodeList($install['pending_domains'] ?? null),
        );
    }

    /**
     * POST /api/plugins/{name}/update/reject - drop the waiting update and stay on this version.
     *
     * @param array<string, string> $params
     */
    public function rejectUpdate(Request $request, array $params): Response
    {
        $refusal = PluginOperatorGate::refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $name = $params['name'] ?? '';
        $install = $this->records?->install($name);
        if ($this->records === null || $install === null || !is_string($install['pending_version'] ?? null)) {
            return Response::error('No update is waiting for this plugin.', 404);
        }

        (new PluginInstaller($this->pluginDir, $this->pluginLoader, $this->auditLogger))->discardStagedUpdate($name);
        $this->records->clearPending($name);
        $this->auditLogger?->record('plugin.update.rejected', [
            'target_type' => 'plugin',
            'metadata' => ['plugin' => $name, 'version' => $install['pending_version']],
        ]);

        return Response::json(['data' => ['status' => 'rejected']]);
    }

    /**
     * Swap in the staged version, which lands turned off (see
     * {@see PluginInstaller::applyStagedUpdate()}), and record it.
     *
     * @param array<string, mixed> $install
     * @param list<string> $permissions
     * @param list<string> $domains
     */
    private function applyUpdate(
        PluginInstaller $installer,
        string $name,
        array $install,
        string $version,
        ?string $sha256,
        array $permissions,
        array $domains,
    ): Response {
        $loader = $this->pluginLoader;
        try {
            if ($loader !== null) {
                foreach ($loader->getPluginMetadata() as $meta) {
                    if ($meta['name'] === $name) {
                        $loader->disablePlugin($meta['id']);
                    }
                }
            }
            $installer->applyStagedUpdate($name);
        } catch (PluginPackageInvalid $e) {
            return Response::error($e->clientMessage(), 409, $e->clientDetails());
        } catch (\Throwable $e) {
            error_log('[InstallFromStore] update apply failed: ' . $e->getMessage());
            return Response::error('The update could not be applied.', 500);
        }

        $this->records?->recordUpdated($name, $version, $sha256, $permissions, $domains);
        $this->auditLogger?->record('plugin.updated', [
            'target_type' => 'plugin',
            'metadata' => [
                'plugin' => $name,
                'from' => $install['version'],
                'to' => $version,
                'sha256' => $sha256,
                'permissions' => $permissions,
                'outside_domains' => $domains,
            ],
        ]);

        return Response::json(['data' => [
            'status' => 'updated',
            'version' => $version,
            'state' => 'disabled',
            'worker_restart_required' => $loader?->isWorkerRecyclePending() ?? false,
        ]]);
    }

    /**
     * A store's catalogue, normalised, or null when it can't be read.
     *
     * @return list<array<string, mixed>>|null
     */
    private function catalogue(string $origin): ?array
    {
        try {
            $payload = ($this->fetchJson)($origin . '/api/v1/plugin-store/plugins');
        } catch (\Throwable $e) {
            error_log('[StoreBrowse] catalogue fetch refused/failed: ' . $e->getMessage());
            return null;
        }
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn (mixed $raw): ?array => is_array($raw) ? StoreCatalogueEntry::normalize($raw) : null,
            $payload['data']
        )));
    }

    /** @return array<string, mixed>|null */
    private function catalogueEntry(string $origin, string $slug): ?array
    {
        foreach ($this->catalogue($origin) ?? [] as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * GET /api/plugins/store/allowed — the trusted store hosts the operator has
     * configured, so the admin UI can offer a store picker and know whether the
     * feature is on. Read-only, no outbound request.
     *
     * @param array<string, string> $params
     */
    public function allowedStores(Request $request, array $params = []): Response
    {
        $hosts = $this->allowedHosts();

        return Response::json([
            'data' => [
                // Both gates must pass: the operator master switch AND a
                // non-empty allowlist. Reported together so the admin UI shows
                // one clear signal regardless of which gate is actually closed.
                'enabled' => $this->storeEnabled() && $hosts !== [],
                'hosts' => $hosts,
                // WHIT-869: whether this caller's tenant may install at all. The
                // page combines it with the plugins:install permission.
                'can_install' => PluginOperatorGate::isOperator(),
            ],
        ]);
    }

    /**
     * GET /api/plugins/store/catalog?store_url=…&q=… — browse (and search) a
     * trusted store's PUBLIC catalogue on the caller's behalf. The host must be
     * on the allowlist (same SSRF control as install); the browser never talks to
     * the store directly. `q` filters by slug/name/description/tags (substring,
     * case-insensitive). Never leaks internal errors.
     *
     * @param array<string, string> $params
     */
    public function browseCatalog(Request $request, array $params = []): Response
    {
        // Query params: at runtime Request::fromGlobals() strips the query from
        // getPath() (path only), so the reliable source is PHP's native $_GET.
        // In unit tests the query is carried in the path, so parse that first and
        // fall back to $_GET for the live request.
        $query = [];
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $query);
        } else {
            $query = $_GET;
        }
        // parse_str/$_GET can yield arrays (e.g. `?q[]=x`); accept only scalar strings.
        $storeUrlRaw = $query['store_url'] ?? '';
        $searchRaw = $query['q'] ?? '';
        $storeUrl = is_string($storeUrlRaw) ? trim($storeUrlRaw) : '';
        $search = is_string($searchRaw) ? strtolower(trim($searchRaw)) : '';

        if ($storeUrl === '') {
            return Response::error('store_url is required.', 422);
        }

        [$origin, $originError] = $this->resolveStoreOrigin($storeUrl);
        if ($originError !== null) {
            return $originError;
        }

        try {
            $payload = ($this->fetchJson)($origin . '/api/v1/plugin-store/plugins');
        } catch (\Throwable $e) {
            error_log('[StoreBrowse] catalogue fetch refused/failed: ' . $e->getMessage());
            return Response::error('The store catalogue could not be fetched.', 502);
        }
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return Response::error('The store returned an unexpected catalogue response.', 502);
        }

        /** @var list<array<string, mixed>> $plugins */
        $plugins = array_values(array_filter($payload['data'], 'is_array'));

        if ($search !== '') {
            $plugins = array_values(array_filter(
                $plugins,
                static fn (array $p): bool => self::matchesSearch($p, $search)
            ));
        }

        // A store's fields are display data; only checked ones go on (StoreCatalogueEntry).
        $plugins = array_values(array_filter(array_map(StoreCatalogueEntry::normalize(...), $plugins)));

        return Response::json([
            'data' => $plugins,
            'store_url' => $origin,
            'count' => count($plugins),
        ]);
    }

    /**
     * Whether a catalogue entry matches a lower-cased search term across its
     * slug, name, description and tags.
     *
     * @param array<string, mixed> $plugin
     */
    private static function matchesSearch(array $plugin, string $needle): bool
    {
        $haystacks = [
            (string) ($plugin['slug'] ?? ''),
            (string) ($plugin['name'] ?? ''),
            (string) ($plugin['description'] ?? ''),
            (string) ($plugin['author'] ?? ''),
        ];
        if (isset($plugin['tags']) && is_array($plugin['tags'])) {
            foreach ($plugin['tags'] as $tag) {
                $haystacks[] = (string) $tag;
            }
        }
        foreach ($haystacks as $h) {
            if ($h !== '' && str_contains(strtolower($h), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate that $storeUrl is a BARE https origin whose host is on the operator
     * allowlist — the single SSRF gate shared by install + browse. Rejecting any
     * path/query/fragment/userinfo and any non-443 port keeps the fetch on the
     * vetted host AND blocks aiming the server at other TCP ports of that host
     * (e.g. a co-located Redis).
     *
     * @return array{0: ?string, 1: ?Response} [normalized `https://host` origin, error-to-return]
     */
    private function resolveStoreOrigin(string $storeUrl): array
    {
        // Master switch FIRST (WC-feature-flags-audit): checked before the
        // allowlist itself, and before anything else in this method, so a
        // disabled instance never even evaluates the allowlist — mirrors
        // the document render routes' flag-first pattern.
        if (!$this->storeEnabled()) {
            return [null, Response::error(
                'Installing from a store is disabled on this instance.',
                403
            )];
        }

        $allowed = $this->allowedHosts();
        if ($allowed === []) {
            return [null, Response::error(
                'Installing from a store is disabled (no trusted store hosts are configured).',
                403
            )];
        }

        $parts = parse_url($storeUrl);
        if (!is_array($parts)) {
            return [null, Response::error('The store URL is malformed.', 422)];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        $path = (string) ($parts['path'] ?? '');
        if ($scheme !== 'https' || $host === '' || !in_array($host, $allowed, true)) {
            return [null, Response::error('The store host is not in the trusted allowlist.', 403)];
        }
        if (($port !== null && $port !== 443)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')
        ) {
            return [null, Response::error('The store URL must be a bare https origin (no path, query, port or credentials).', 422)];
        }

        return ['https://' . $host, null];
    }

    /**
     * The operator master switch (WC-feature-flags-audit): read fresh per
     * request, like {@see SsoAuthHandler::ssoEnabled()}. Default 'true' — see
     * {@see SettingsRegistry::PLUGINS_STORE_ENABLED} for why the default is
     * opt-out rather than opt-in.
     */
    private function storeEnabled(): bool
    {
        return ($this->settings->getGlobal()[SettingsRegistry::PLUGINS_STORE_ENABLED] ?? 'true') === 'true';
    }

    /**
     * @return list<string> Lower-cased trusted store hosts (empty ⇒ feature off).
     */
    private function allowedHosts(): array
    {
        $raw = (string) ($this->settings->getGlobal()[SettingsRegistry::PLUGINS_STORE_ALLOWED_HOSTS] ?? '');
        $hosts = array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', $raw),
        );

        return array_values(array_filter($hosts, static fn (string $h): bool => $h !== ''));
    }
}
