<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Store;

use Whity\Core\Plugin\PluginRecords;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * Store-signed Verified marks for code plugins (WHIT-1014; VERIFIED-PLUGINS-SECURITY.md 4.1).
 *
 * The Whity store (app.whity.dev) marks one exact package Verified after its operator reviews
 * it, and signs a statement of that mark that is good for a day (`typ: whity-mark+jwt`, EdDSA).
 * A code plugin installed from that store is Verified here only while such a statement names its
 * slug, version and package sha256 as recorded at install (`plugin_installs`), and says
 * `verified`.
 *
 * - **Pinned keys only.** The store's mark keys are pinned below, per host. A mark from any other
 *   store, including one on `plugins.store_allowed_hosts`, never makes a plugin Verified: being
 *   allowed to install from a host isn't trusting it to vouch for code.
 * - **Never on a request.** {@see mark()} reads the cached statement only. {@see refresh()}
 *   fetches, from the plugins page and the scheduler (every 15 minutes), so a revocation reaches
 *   every worker within a quarter of an hour of the next fetch, without a network call inside an
 *   MCP request.
 * - **Offline window.** When the store can't be reached, a statement that said `verified` keeps
 *   counting until the earlier of 7 days after the last good fetch and 6 days after the
 *   statement expired; then the plugin is Unverified until the store answers. `revoked` and
 *   `none` count at once.
 *
 * Nothing here writes `plugin_version_marks`, which belongs to {@see \Whity\Core\Plugin\Trust\PluginTrust}
 * and declarative plugins. The cache lives in app_settings, `plugins.store_mark.<plugin>`.
 */
final class StoreMarks
{
    public const CACHE_PREFIX = 'plugins.store_mark.';
    public const TYP = 'whity-mark+jwt';
    public const OFFLINE_DAYS = 7;
    public const PAST_EXPIRY_DAYS = 6;
    public const REFRESH_AFTER = 3600;

    /**
     * The mark keys each store signs with, base64url Ed25519 public keys. app.whity.dev's key is
     * held on that host (PLUGIN_STORE_MARK_KEY); rotating it ships a new key here first.
     */
    public const PINNED_KEYS = [
        'app.whity.dev' => ['ccKZ-SwU_oIUYqA1R8Vyqb_Vi5GR593qccKPsrg31FY'],
    ];

    /** @var \Closure(string): ?array<string, mixed> */
    private \Closure $fetchJson;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param \Closure(string): ?array<string, mixed> $fetchJson GET a URL as JSON; null on failure.
     * @param array<string, list<string>> $pinnedKeys Host => base64url public keys.
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly GlobalSettingsRepository $globals,
        private readonly PluginRecords $records,
        \Closure $fetchJson,
        private readonly array $pinnedKeys = self::PINNED_KEYS,
        ?\Closure $clock = null,
    ) {
        $this->fetchJson = $fetchJson;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The live mark for an installed code plugin, from the cache only.
     *
     * @return array{host: string, reviewed_at: ?string, checked_at: string, offline: bool}|null
     *     Null when the plugin isn't Verified.
     *
     * @phpstan-impure It reads the cache and the clock.
     */
    public function mark(string $pluginName): ?array
    {
        $install = $this->records->install($pluginName);
        $target = $install === null ? null : self::target($install);
        if ($target === null || !isset($this->pinnedKeys[$target['host']])) {
            return null;
        }
        $cached = $this->cached($pluginName);
        if ($cached === null || $cached['host'] !== $target['host']) {
            return null;
        }
        $claims = $this->verify($cached['jws'], $target);
        if ($claims === null || $claims['state'] !== 'verified') {
            return null;
        }

        $now = ($this->clock)();
        $exp = (int) $claims['exp'];
        $offline = $exp <= $now;
        if ($offline && ($now >= $cached['checked_at'] + self::OFFLINE_DAYS * 86400 || $now >= $exp + self::PAST_EXPIRY_DAYS * 86400)) {
            return null;
        }

        return [
            'host' => $target['host'],
            'reviewed_at' => is_string($claims['reviewed_at'] ?? null) ? $claims['reviewed_at'] : null,
            'checked_at' => gmdate('c', $cached['checked_at']),
            'offline' => $offline,
        ];
    }

    /** @phpstan-impure */
    public function isVerified(string $pluginName): bool
    {
        return $this->mark($pluginName) !== null;
    }

    /**
     * Fetch fresh statements for store-installed plugins from pinned stores. A statement that
     * doesn't verify is not cached; a store that can't be reached leaves the cache as it was.
     *
     * @param bool $force Fetch even when the cached statement is recent.
     * @return array{checked: int, failed: int}
     */
    public function refresh(bool $force = false): array
    {
        $checked = 0;
        $failed = 0;
        foreach ($this->records->installs() as $name => $install) {
            $target = self::target($install);
            if ($target === null || !isset($this->pinnedKeys[$target['host']])) {
                continue;
            }
            $cached = $this->cached((string) $name);
            if (!$force && $cached !== null && $cached['host'] === $target['host'] && !$this->isDue($cached, $target)) {
                continue;
            }
            $jws = $this->fetch($target);
            if ($jws === null || $this->verify($jws, $target) === null) {
                $failed++;
                continue;
            }
            $this->globals->set(self::CACHE_PREFIX . $name, (string) json_encode([
                'host' => $target['host'],
                'jws' => $jws,
                'checked_at' => ($this->clock)(),
            ]));
            $checked++;
        }

        return ['checked' => $checked, 'failed' => $failed];
    }

    /** Drop a plugin's cached mark (uninstall, or a new version installed). */
    public function forget(string $pluginName): void
    {
        $this->globals->delete(self::CACHE_PREFIX . $pluginName);
    }

    /**
     * @param array<string, mixed> $install
     * @return array{host: string, slug: string, version: string, sha256: string}|null
     */
    private static function target(array $install): ?array
    {
        $host = strtolower((string) ($install['store_host'] ?? ''));
        $slug = (string) ($install['store_slug'] ?? '');
        $version = (string) ($install['version'] ?? '');
        $sha = strtolower((string) ($install['package_sha256'] ?? ''));
        if (($install['source'] ?? null) !== 'store' || $host === '' || $slug === '' || $version === '' || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            return null;
        }

        return ['host' => $host, 'slug' => $slug, 'version' => $version, 'sha256' => $sha];
    }

    /** @return array{host: string, jws: string, checked_at: int}|null */
    private function cached(string $pluginName): ?array
    {
        $raw = $this->globals->get(self::CACHE_PREFIX . $pluginName);
        $row = $raw === null ? null : json_decode($raw, true);
        if (!is_array($row) || !is_string($row['host'] ?? null) || !is_string($row['jws'] ?? null) || !is_int($row['checked_at'] ?? null)) {
            return null;
        }

        return ['host' => $row['host'], 'jws' => $row['jws'], 'checked_at' => $row['checked_at']];
    }

    /**
     * @param array{host: string, jws: string, checked_at: int} $cached
     * @param array{host: string, slug: string, version: string, sha256: string} $target
     */
    private function isDue(array $cached, array $target): bool
    {
        $now = ($this->clock)();
        if ($now >= $cached['checked_at'] + self::REFRESH_AFTER) {
            return true;
        }
        $claims = $this->verify($cached['jws'], $target);

        return $claims === null || (int) $claims['exp'] - 1800 <= $now;
    }

    /**
     * The statement for this exact version, from the store's public plugin page (public on every
     * host version, unlike the per-version path).
     *
     * @param array{host: string, slug: string, version: string, sha256: string} $target
     */
    private function fetch(array $target): ?string
    {
        try {
            $body = ($this->fetchJson)('https://' . $target['host'] . '/api/v1/plugin-store/plugins/' . rawurlencode($target['slug']));
        } catch (\Throwable) {
            return null;
        }
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
        $versions = is_array($data['versions'] ?? null) ? $data['versions'] : [];
        foreach ($versions as $version) {
            if (is_array($version) && ($version['version'] ?? null) === $target['version'] && is_string($version['mark'] ?? null)) {
                return $version['mark'];
            }
        }

        return null;
    }

    /**
     * The claims of a statement signed by a key pinned for this store and naming exactly this
     * package, or null.
     *
     * @param array{host: string, slug: string, version: string, sha256: string} $target
     * @return array<string, mixed>|null
     */
    private function verify(string $jws, array $target): ?array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode(self::unb64u($parts[0]), true);
        $claims = json_decode(self::unb64u($parts[1]), true);
        $signature = self::unb64u($parts[2]);
        if (
            !is_array($header) || !is_array($claims)
            || ($header['alg'] ?? null) !== 'EdDSA' || ($header['typ'] ?? null) !== self::TYP
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            return null;
        }
        $signed = false;
        foreach ($this->pinnedKeys[$target['host']] ?? [] as $key) {
            $raw = self::unb64u($key);
            if (strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($signature, $parts[0] . '.' . $parts[1], $raw)) {
                $signed = true;
                break;
            }
        }
        if (
            !$signed
            || ($claims['iss'] ?? null) !== 'https://' . $target['host'] . '/api/v1/plugin-store'
            || ($claims['plugin'] ?? null) !== $target['slug']
            || ($claims['version'] ?? null) !== $target['version']
            || !is_string($claims['sha256'] ?? null) || !hash_equals($target['sha256'], strtolower($claims['sha256']))
            || !in_array($claims['state'] ?? null, ['verified', 'revoked', 'none'], true)
            || !is_int($claims['exp'] ?? null)
        ) {
            return null;
        }

        return $claims;
    }

    private static function unb64u(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
