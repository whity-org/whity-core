<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Store;

use Closure;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Sdk\Licensing\LicenceState;
use Whity\Sdk\Licensing\StoreLicence;

/**
 * The host's answer to "is this plugin licensed here?" (SDK 1.85, store licensing PR-4).
 *
 * The answer comes from the store this installation is connected to through Whity ID:
 * 1. Fetch the store's signed statement (`GET /api/v1/plugin-store/licences/{slug}`, or the
 *    `licence` on `GET …/plugins/{slug}` for a store on an older host) with a fresh Whity ID token.
 * 2. Verify it: EdDSA with the store's key (pinned for app.whity.dev, otherwise the keys the
 *    store published the first time they were needed), `aud` this installation's client_id,
 *    `plugin` the slug asked about, and `exp` in the future.
 * 3. Cache it in app_settings and answer from it for an hour, or until it is 30 minutes from
 *    expiring.
 *
 * OFFLINE WINDOW. When the store cannot be reached and the cached statement has expired, a
 * licensed or grace answer turns into `grace` until the earlier of the statement's own
 * `grace_until` and the last successful check plus the grace window (7 days). After that the
 * answer is `unlicensed`. An installation that never checked successfully is `unlicensed`.
 */
final class HostStoreLicence implements StoreLicence
{
    public const CACHE_PREFIX = 'plugins.store_licence.';
    public const KEYS_KEY = 'plugins.store_licence_keys';

    private const REFRESH_SECONDS = 3600;
    private const REFRESH_BEFORE_EXP = 1800;

    /**
     * Licence keys Whity pins for its own store (base64url Ed25519 public keys). A key here is
     * trusted without ever being fetched, so a store host that is compromised later cannot
     * swap it.
     *
     * @var array<string, list<string>>
     */
    public const PINNED_KEYS = [
        'app.whity.dev' => ['KqAUuBgi6Eg-c7Wt8hqcW-poEEFXujx1zwIsPm9ahIQ'],
    ];

    private Closure $getJson;
    private Closure $clock;

    /**
     * @param Closure(string, array<string, string>): ?array<mixed> $getJson
     * @param array<string, list<string>> $pinned host => base64url public keys
     */
    public function __construct(
        private readonly GlobalSettingsRepository $globals,
        private readonly WhityStoreConnector $connector,
        Closure $getJson,
        private readonly array $pinned = self::PINNED_KEYS,
        private readonly int $graceDays = 7,
        private readonly bool $selfLicensed = false,
        ?Closure $clock = null,
    ) {
        $this->getJson = $getJson;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** True when this installation is the store itself (PLUGIN_STORE_SELF_LICENSED). */
    public static function selfLicensedFromEnv(): bool
    {
        $raw = $_ENV['PLUGIN_STORE_SELF_LICENSED'] ?? getenv('PLUGIN_STORE_SELF_LICENSED');

        return is_string($raw) && in_array(strtolower(trim($raw)), ['1', 'true', 'yes'], true);
    }

    public function status(string $slug): LicenceState
    {
        if ($this->selfLicensed) {
            return LicenceState::licensed('This installation is the Whity store, so its own plugins need no licence.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $slug) !== 1) {
            return LicenceState::unlicensed('That is not a plugin in the store.');
        }

        $connection = $this->connector->status();
        if (!($connection['connected'] ?? false) || !is_string($connection['store_host'] ?? null)) {
            return LicenceState::unlicensed('Connect this installation to the Whity store to licence this plugin.');
        }
        $host = $connection['store_host'];
        $clientId = $this->connector->clientId();
        $now = ($this->clock)();
        $cached = $this->cached($slug, $host, (string) $clientId);

        if ($cached !== null && $cached['checked_at'] > $now - self::REFRESH_SECONDS
            && $cached['claims']['exp'] - $now > self::REFRESH_BEFORE_EXP
        ) {
            return $this->answer($cached['claims'], false, $cached['checked_at']);
        }

        $fresh = $clientId === null ? null : $this->fetch($host, $slug, $clientId);
        if ($fresh !== null) {
            $this->globals->set(self::CACHE_PREFIX . $slug, (string) json_encode([
                'host' => $host,
                'jws' => $fresh['jws'],
                'checked_at' => $now,
            ], JSON_UNESCAPED_SLASHES));

            return $this->answer($fresh['claims'], false, $now);
        }

        return $this->offline($cached, $now);
    }

    /**
     * @param array{claims: array<string, mixed>, checked_at: int}|null $cached
     */
    private function offline(?array $cached, int $now): LicenceState
    {
        if ($cached === null) {
            return LicenceState::unlicensed('The Whity store could not confirm this licence. Check the connection under Plugins, Store.', true);
        }
        $claims = $cached['claims'];
        if ($claims['exp'] > $now) {
            return $this->answer($claims, true, $cached['checked_at']);
        }
        if (!in_array($claims['state'] ?? null, [LicenceState::LICENSED, LicenceState::GRACE], true)) {
            return LicenceState::unlicensed('This plugin has no licence here.', true, self::iso($cached['checked_at']));
        }

        $until = $cached['checked_at'] + $this->graceDays * 86400;
        $statedGrace = is_string($claims['grace_until'] ?? null) ? strtotime($claims['grace_until']) : false;
        if ($statedGrace !== false) {
            $until = min($until, $statedGrace);
        }
        if ($now >= $until) {
            return LicenceState::unlicensed('The Whity store has not confirmed this licence for too long. Check the connection under Plugins, Store.', true, self::iso($cached['checked_at']));
        }

        return LicenceState::grace(
            'The Whity store cannot be reached, so the last confirmed licence carries on for now.',
            self::iso($until),
            true,
            self::iso($cached['checked_at']),
        );
    }

    /** @param array<string, mixed> $claims */
    private function answer(array $claims, bool $offline, int $checkedAt): LicenceState
    {
        return match ($claims['state'] ?? null) {
            LicenceState::LICENSED => LicenceState::licensed(
                ($claims['free'] ?? false) === true ? 'This plugin is free.' : 'Licensed.',
                is_string($claims['valid_until'] ?? null) ? $claims['valid_until'] : null,
                $offline,
                self::iso($checkedAt),
            ),
            LicenceState::GRACE => LicenceState::grace(
                'The licence needs attention: a payment failed or the period ended. It keeps working until the date shown.',
                is_string($claims['grace_until'] ?? null) ? $claims['grace_until'] : self::iso($checkedAt),
                $offline,
                self::iso($checkedAt),
            ),
            default => LicenceState::unlicensed('This plugin has no licence here. Buy it or start a trial under Plugins, Store.', $offline, self::iso($checkedAt)),
        };
    }

    /** @return array{jws: string, claims: array<string, mixed>}|null */
    private function fetch(string $host, string $slug, string $clientId): ?array
    {
        $token = $this->connector->accessTokenFor($host);
        if ($token === null) {
            return null;
        }
        $headers = ['Authorization' => 'Bearer ' . $token];
        $origin = 'https://' . $host . '/api/v1/plugin-store';

        foreach ([$origin . '/licences/' . rawurlencode($slug), $origin . '/plugins/' . rawurlencode($slug)] as $url) {
            try {
                $payload = ($this->getJson)($url, $headers);
            } catch (\Throwable $e) {
                error_log('[StoreLicence] fetch refused/failed: ' . $e->getMessage());
                $payload = null;
            }
            $jws = is_array($payload) && is_array($payload['data'] ?? null) ? ($payload['data']['licence'] ?? null) : null;
            if (is_string($jws) && ($claims = $this->verify($jws, $host, $slug, $clientId)) !== null) {
                return ['jws' => $jws, 'claims' => $claims];
            }
        }

        return null;
    }

    /**
     * @return array{claims: array<string, mixed>, checked_at: int}|null
     */
    private function cached(string $slug, string $host, string $clientId): ?array
    {
        $raw = $this->globals->get(self::CACHE_PREFIX . $slug);
        $row = $raw === null ? null : json_decode($raw, true);
        if (!is_array($row) || ($row['host'] ?? null) !== $host || !is_string($row['jws'] ?? null) || !is_int($row['checked_at'] ?? null)) {
            return null;
        }
        // Verified again on every read, so a row written by hand cannot grant a licence.
        $claims = $this->verify($row['jws'], $host, $slug, $clientId, ignoreExpiry: true);

        return $claims === null ? null : ['claims' => $claims, 'checked_at' => $row['checked_at']];
    }

    /** @return array<string, mixed>|null */
    private function verify(string $jws, string $host, string $slug, string $clientId, bool $ignoreExpiry = false): ?array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode((string) self::unb64u($parts[0]), true);
        $claims = json_decode((string) self::unb64u($parts[1]), true);
        $signature = self::unb64u($parts[2]);
        if (!is_array($header) || !is_array($claims) || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ($header['alg'] ?? null) !== 'EdDSA' || ($header['typ'] ?? null) !== 'whity-licence+jwt'
        ) {
            return null;
        }

        $verified = false;
        foreach ($this->keysFor($host) as $key) {
            if (sodium_crypto_sign_verify_detached($signature, $parts[0] . '.' . $parts[1], $key)) {
                $verified = true;
                break;
            }
        }
        if (!$verified
            || ($claims['aud'] ?? null) !== $clientId
            || ($claims['plugin'] ?? null) !== $slug
            || !is_int($claims['exp'] ?? null)
            || (!$ignoreExpiry && $claims['exp'] <= ($this->clock)())
        ) {
            return null;
        }

        return $claims;
    }

    /** @return list<non-empty-string> */
    private function keysFor(string $host): array
    {
        $encoded = $this->pinned[$host] ?? null;
        if ($encoded === null) {
            $known = json_decode((string) $this->globals->get(self::KEYS_KEY), true);
            $encoded = is_array($known) && is_array($known[$host] ?? null) ? $known[$host] : null;
            if ($encoded === null) {
                // Trusted on first use, over the same TLS the store's catalogue comes over.
                $encoded = $this->fetchKeys($host);
                if ($encoded !== []) {
                    $all = is_array($known) ? $known : [];
                    $all[$host] = $encoded;
                    $this->globals->set(self::KEYS_KEY, (string) json_encode($all, JSON_UNESCAPED_SLASHES));
                }
            }
        }

        $keys = [];
        foreach ($encoded as $value) {
            $key = is_string($value) ? self::unb64u($value) : null;
            if ($key !== null && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function fetchKeys(string $host): array
    {
        try {
            $payload = ($this->getJson)('https://' . $host . '/api/v1/plugin-store/plugins', []);
        } catch (\Throwable) {
            return [];
        }
        $keys = is_array($payload) && is_array($payload['auth']['licence_keys'] ?? null) ? $payload['auth']['licence_keys'] : [];

        return array_values(array_filter(array_map(
            static fn (mixed $jwk): ?string => is_array($jwk) && ($jwk['crv'] ?? null) === 'Ed25519' && is_string($jwk['x'] ?? null) ? $jwk['x'] : null,
            $keys,
        )));
    }

    private static function iso(int $timestamp): string
    {
        return gmdate('c', $timestamp);
    }

    private static function unb64u(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
