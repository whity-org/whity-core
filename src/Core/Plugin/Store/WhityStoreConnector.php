<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Store;

use Closure;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * Connects this installation to a Whity plugin store through Whity ID (WHIT-1003).
 *
 * An operator presses "Connect to Whity store" in the plugin settings and signs in to Whity ID
 * once. That registers this installation as a Whity ID client (RFC 7591), and the operator's
 * consent gives it a refresh token for the store's resource (RFC 8707) with the
 * `store:download` scope. From then on every catalogue, install and update call asks for a
 * fresh short-lived access token and presents it to the store.
 *
 * What is kept, all install-wide in `app_settings` (never in a registry key, so the settings
 * API never returns any of it):
 * - `plugins.store_connect.client`: this installation's client_id, per issuer + redirect URI,
 *   reused when it connects again so Whity ID lists one installation, not one per connect.
 * - `plugins.store_connect.connection`: the store, issuer, endpoints and the connected account.
 * - `plugins.store_connect.refresh_token`: encrypted with the platform secret store.
 * - `plugins.store_connect.access_token`: the cached access token, encrypted.
 * - `plugins.store_connect.pending`: an unfinished sign-in (state, PKCE verifier, nonce),
 *   encrypted, single use, ten minutes.
 *
 * Refresh tokens rotate, and Whity ID revokes the whole chain when a spent one is presented
 * again. So only one worker refreshes at a time (a lease in app_settings), the new refresh
 * token is written with compare-and-set, and the others wait for the cached token.
 */
final class WhityStoreConnector
{
    public const SCOPE = 'store:download';

    public const CLIENT_KEY = 'plugins.store_connect.client';
    public const CONNECTION_KEY = 'plugins.store_connect.connection';
    public const REFRESH_TOKEN_KEY = 'plugins.store_connect.refresh_token';
    public const ACCESS_TOKEN_KEY = 'plugins.store_connect.access_token';
    public const PENDING_KEY = 'plugins.store_connect.pending';
    public const LEASE_KEY = 'plugins.store_connect.refresh_lease';

    /** The issuer an installation trusts when WHITY_ID_ISSUERS is not set. */
    public const DEFAULT_ISSUER = 'https://id.whity.dev';

    private const PENDING_SECONDS = 600;
    private const REFRESH_AHEAD_SECONDS = 30;
    private const LEASE_SECONDS = 20;
    private const WAIT_SECONDS = 8;

    private Closure $getJson;
    private Closure $postForm;
    private Closure $postJson;
    private Closure $clock;
    private Closure $sleep;

    /**
     * @param list<string> $issuers   Whity ID issuers this installation may connect through.
     * @param string       $redirectUri Where Whity ID sends the operator back (the web page).
     * @param Closure(string, array<string,string>): ?array<mixed> $getJson
     * @param Closure(string, array<string,string>): ?array<mixed> $postForm
     * @param Closure(string, array<string,mixed>): ?array<mixed>  $postJson
     */
    public function __construct(
        private readonly GlobalSettingsRepository $globals,
        private readonly EncryptedSecretStore $secrets,
        private readonly array $issuers,
        private readonly string $redirectUri,
        private readonly string $installationName,
        Closure $getJson,
        Closure $postForm,
        Closure $postJson,
        ?Closure $clock = null,
        ?Closure $sleep = null,
    ) {
        $this->getJson = $getJson;
        $this->postForm = $postForm;
        $this->postJson = $postJson;
        $this->clock = $clock ?? static fn (): int => time();
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * The issuers allowed by `WHITY_ID_ISSUERS` (comma separated), else Whity ID's own.
     *
     * @return list<string>
     */
    public static function issuersFromEnv(): array
    {
        $raw = $_ENV['WHITY_ID_ISSUERS'] ?? getenv('WHITY_ID_ISSUERS');
        $raw = is_string($raw) && trim($raw) !== '' ? $raw : self::DEFAULT_ISSUER;

        return array_values(array_filter(array_map(
            static fn (string $issuer): string => rtrim(trim($issuer), '/'),
            explode(',', $raw),
        ), static fn (string $issuer): bool => str_starts_with($issuer, 'https://')));
    }

    // ── Connecting ──────────────────────────────────────────────────────────

    /**
     * Begin connecting to the store at this (already allowlisted) https origin. Returns the
     * Whity ID URL to send the operator's browser to.
     *
     * @throws WhityStoreConnectException
     */
    public function start(string $storeOrigin, ?int $actor): string
    {
        $host = (string) parse_url($storeOrigin, PHP_URL_HOST);
        $meta = $this->fetchJson($storeOrigin . '/api/v1/plugin-store/auth');
        $resource = is_string($meta['resource'] ?? null) ? $meta['resource'] : '';
        $servers = is_array($meta['authorization_servers'] ?? null) ? $meta['authorization_servers'] : [];
        $issuer = is_string($servers[0] ?? null) ? rtrim($servers[0], '/') : '';

        if ($resource === '' || $issuer === '') {
            throw new WhityStoreConnectException('This store does not accept Whity ID sign-in.', 422);
        }
        if (!str_starts_with($resource, $storeOrigin . '/')) {
            throw new WhityStoreConnectException('The store named a resource on another host.', 502);
        }
        if (!in_array($issuer, $this->issuers, true)) {
            throw new WhityStoreConnectException('The store uses a sign-in service this installation does not trust.', 422);
        }

        $endpoints = $this->discover($issuer);
        [$applicationType] = $this->redirectKind();
        $clientId = $this->clientFor($issuer, $endpoints['registration_endpoint'], $applicationType);

        $state = self::random();
        $verifier = self::random(48);
        $nonce = self::random();

        $this->globals->set(self::PENDING_KEY, $this->secrets->encrypt((string) json_encode([
            'state' => hash('sha256', $state),
            'verifier' => $verifier,
            'nonce' => $nonce,
            'client_id' => $clientId,
            'issuer' => $issuer,
            'endpoints' => $endpoints,
            'resource' => $resource,
            'store_host' => $host,
            'actor' => $actor,
            'expires_at' => ($this->clock)() + self::PENDING_SECONDS,
        ])));

        return $endpoints['authorization_endpoint'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid profile email ' . self::SCOPE,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => $resource,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Finish connecting with the code Whity ID sent back. Single use: the pending sign-in is
     * gone whether this succeeds or not.
     *
     * @return array<string, mixed> The new status.
     * @throws WhityStoreConnectException
     */
    public function complete(string $code, string $state, ?int $actor): array
    {
        $pending = $this->pending();
        $this->globals->delete(self::PENDING_KEY);

        if ($pending === null
            || !hash_equals((string) $pending['state'], hash('sha256', $state))
            || (int) $pending['expires_at'] < ($this->clock)()
        ) {
            throw new WhityStoreConnectException('This sign-in has expired or was not started here. Connect again.', 422);
        }
        if ($pending['actor'] !== null && $actor !== null && (int) $pending['actor'] !== $actor) {
            throw new WhityStoreConnectException('Finish connecting as the person who started it.', 403);
        }

        $response = $this->call($this->postForm, $pending['endpoints']['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $pending['client_id'],
            'code_verifier' => $pending['verifier'],
            'resource' => $pending['resource'],
        ]);
        $accessToken = $response['access_token'] ?? null;
        $refreshToken = $response['refresh_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '' || !is_string($refreshToken) || $refreshToken === '') {
            throw new WhityStoreConnectException('Whity ID did not connect this installation.', 502);
        }

        $account = $this->accountFrom($response, $pending);
        $now = ($this->clock)();

        $this->globals->set(self::CONNECTION_KEY, (string) json_encode([
            'store_host' => $pending['store_host'],
            'issuer' => $pending['issuer'],
            'client_id' => $pending['client_id'],
            'resource' => $pending['resource'],
            'token_endpoint' => $pending['endpoints']['token_endpoint'],
            'revocation_endpoint' => $pending['endpoints']['revocation_endpoint'],
            'account' => $account,
            'connected_at' => $now,
            'connected_by' => $actor,
            'needs_reconnect' => false,
        ], JSON_UNESCAPED_SLASHES));
        $this->globals->set(self::REFRESH_TOKEN_KEY, $this->secrets->encrypt($refreshToken));
        $this->cacheAccessToken($accessToken, $now + self::lifetime($response), (string) $pending['client_id']);

        return $this->status();
    }

    /**
     * Disconnect: revoke the refresh token at Whity ID (best effort; the person can also
     * disconnect it from their Whity ID Security page) and forget everything but the client
     * registration, which a later connect reuses.
     */
    public function disconnect(): void
    {
        $connection = $this->connection();
        $refresh = $this->refreshToken();
        if ($connection !== null && $refresh !== null && is_string($connection['revocation_endpoint'] ?? null)) {
            try {
                ($this->postForm)($connection['revocation_endpoint'], [
                    'token' => $refresh,
                    'token_type_hint' => 'refresh_token',
                    'client_id' => (string) $connection['client_id'],
                ]);
            } catch (\Throwable $e) {
                error_log('[WhityStore] revoke failed: ' . $e->getMessage());
            }
        }

        foreach ([self::CONNECTION_KEY, self::REFRESH_TOKEN_KEY, self::ACCESS_TOKEN_KEY, self::PENDING_KEY] as $key) {
            $this->globals->delete($key);
        }
    }

    /**
     * What the plugin settings show.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $connection = $this->connection();
        if ($connection === null) {
            return ['connected' => false];
        }

        return [
            'connected' => true,
            'store_host' => $connection['store_host'],
            'issuer' => $connection['issuer'],
            'account' => $connection['account'] ?? null,
            'connected_at' => gmdate('c', (int) ($connection['connected_at'] ?? 0)),
            'needs_reconnect' => (bool) ($connection['needs_reconnect'] ?? false),
        ];
    }

    // ── Tokens ──────────────────────────────────────────────────────────────

    /**
     * A fresh access token for this store host, or null when this installation is not
     * connected to it (or the connection needs an operator to sign in again).
     */
    public function accessTokenFor(string $host): ?string
    {
        $deadline = ($this->clock)() + self::WAIT_SECONDS;

        while (true) {
            $connection = $this->connection();
            if ($connection === null || strtolower((string) $connection['store_host']) !== strtolower($host)
                || ($connection['needs_reconnect'] ?? false)
            ) {
                return null;
            }

            $seen = $this->globals->get(self::ACCESS_TOKEN_KEY);
            $cached = $this->cachedAccessToken((string) $connection['client_id']);
            if ($cached !== null && $cached['expires_at'] - self::REFRESH_AHEAD_SECONDS > ($this->clock)()) {
                return $cached['token'];
            }

            $lease = $this->globals->claimLease(self::LEASE_KEY, self::LEASE_SECONDS, ($this->clock)());
            if ($lease !== null) {
                try {
                    return $this->refreshHoldingLease();
                } finally {
                    $this->globals->releaseLease(self::LEASE_KEY, $lease);
                }
            }

            // Another worker is refreshing; a token with life left carries this call.
            if ($cached !== null && $cached['expires_at'] > ($this->clock)()) {
                return $cached['token'];
            }
            if (($this->clock)() >= $deadline) {
                return null;
            }
            ($this->sleep)(250_000);
            if ($this->globals->get(self::ACCESS_TOKEN_KEY) !== $seen) {
                continue;
            }
        }
    }

    private function refreshHoldingLease(): ?string
    {
        // Read again under the lease: the last holder may have just finished.
        $connection = $this->connection();
        if ($connection === null || ($connection['needs_reconnect'] ?? false)) {
            return null;
        }
        $clientId = (string) $connection['client_id'];
        $cached = $this->cachedAccessToken($clientId);
        if ($cached !== null && $cached['expires_at'] - self::REFRESH_AHEAD_SECONDS > ($this->clock)()) {
            return $cached['token'];
        }

        $ciphertext = $this->globals->get(self::REFRESH_TOKEN_KEY);
        if ($ciphertext === null || $ciphertext === '') {
            return null;
        }
        try {
            $refresh = $this->secrets->decrypt($ciphertext);
        } catch (\RuntimeException) {
            return null;
        }

        try {
            $response = ($this->postForm)((string) $connection['token_endpoint'], [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id' => $clientId,
                'resource' => (string) $connection['resource'],
            ]);
        } catch (\Throwable $e) {
            error_log('[WhityStore] refresh failed: ' . $e->getMessage());
            $response = null;
        }

        $token = is_array($response) ? ($response['access_token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            if (is_array($response) && ($response['error'] ?? null) === 'invalid_grant') {
                // Revoked, lapsed or disconnected from Whity ID: only a person can fix that.
                $connection['needs_reconnect'] = true;
                $this->globals->set(self::CONNECTION_KEY, (string) json_encode($connection, JSON_UNESCAPED_SLASHES));
                $this->globals->delete(self::ACCESS_TOKEN_KEY);
            }

            return $cached !== null && $cached['expires_at'] > ($this->clock)() ? $cached['token'] : null;
        }

        $rotated = $response['refresh_token'] ?? null;
        if (is_string($rotated) && $rotated !== '' && $rotated !== $refresh
            && !$this->globals->compareAndSet(self::REFRESH_TOKEN_KEY, $ciphertext, $this->secrets->encrypt($rotated))
        ) {
            // Disconnected or reconnected while this ran: do not use a token from the old chain.
            return null;
        }

        $this->cacheAccessToken($token, ($this->clock)() + self::lifetime($response), $clientId);

        return $token;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, registration_endpoint: string, revocation_endpoint: ?string}
     * @throws WhityStoreConnectException
     */
    private function discover(string $issuer): array
    {
        $doc = $this->fetchJson($issuer . '/.well-known/oauth-authorization-server');
        if (($doc['issuer'] ?? null) !== $issuer) {
            throw new WhityStoreConnectException('The sign-in service did not describe itself.', 502);
        }

        $endpoint = static function (mixed $url) use ($issuer): ?string {
            return is_string($url) && str_starts_with($url, $issuer . '/') ? $url : null;
        };
        $authorize = $endpoint($doc['authorization_endpoint'] ?? null);
        $token = $endpoint($doc['token_endpoint'] ?? null);
        $register = $endpoint($doc['registration_endpoint'] ?? null);
        if ($authorize === null || $token === null || $register === null) {
            throw new WhityStoreConnectException('The sign-in service cannot connect installations.', 502);
        }

        return [
            'authorization_endpoint' => $authorize,
            'token_endpoint' => $token,
            'registration_endpoint' => $register,
            'revocation_endpoint' => $endpoint($doc['revocation_endpoint'] ?? null),
        ];
    }

    /**
     * `web` for an https installation, `native` for one on this machine's loopback.
     *
     * @return array{0: string}
     * @throws WhityStoreConnectException
     */
    private function redirectKind(): array
    {
        $scheme = strtolower((string) parse_url($this->redirectUri, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($this->redirectUri, PHP_URL_HOST));
        if ($scheme === 'https') {
            return ['web'];
        }
        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
            return ['native'];
        }

        throw new WhityStoreConnectException(
            'Connecting needs this installation to be served over https (set APP_URL or FRONTEND_URL).',
            422
        );
    }

    /** @throws WhityStoreConnectException */
    private function clientFor(string $issuer, string $registrationEndpoint, string $applicationType): string
    {
        $stored = json_decode((string) $this->globals->get(self::CLIENT_KEY), true);
        if (is_array($stored) && ($stored['issuer'] ?? null) === $issuer
            && ($stored['redirect_uri'] ?? null) === $this->redirectUri
            && is_string($stored['client_id'] ?? null) && $stored['client_id'] !== ''
        ) {
            return $stored['client_id'];
        }

        $base = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $this->redirectUri);
        $registered = $this->call($this->postJson, $registrationEndpoint, array_filter([
            'client_name' => $this->installationName,
            'redirect_uris' => [$this->redirectUri],
            'application_type' => $applicationType,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'client_uri' => str_starts_with($base, 'https://') ? $base : null,
            'software_id' => 'whity-core',
        ], static fn ($value): bool => $value !== null));

        $clientId = $registered['client_id'] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new WhityStoreConnectException('Whity ID did not register this installation.', 502);
        }

        $this->globals->set(self::CLIENT_KEY, (string) json_encode([
            'issuer' => $issuer,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $clientId,
        ], JSON_UNESCAPED_SLASHES));

        return $clientId;
    }

    /**
     * The connected account, from the ID token that came straight back from the token endpoint
     * over TLS (OIDC Core 3.1.3.7: its signature need not be checked then). The issuer,
     * audience and nonce still are.
     *
     * @param array<mixed> $response
     * @param array<string, mixed> $pending
     * @return array{sub: ?string, name: ?string, email: ?string}
     */
    private function accountFrom(array $response, array $pending): array
    {
        $claims = [];
        $idToken = $response['id_token'] ?? null;
        if (is_string($idToken) && substr_count($idToken, '.') === 2) {
            $payload = json_decode((string) base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/'), true), true);
            $audience = is_array($payload) ? ($payload['aud'] ?? null) : null;
            if (is_array($payload)
                && ($payload['iss'] ?? null) === $pending['issuer']
                && ($payload['nonce'] ?? null) === $pending['nonce']
                && ($audience === $pending['client_id'] || (is_array($audience) && in_array($pending['client_id'], $audience, true)))
            ) {
                $claims = $payload;
            }
        }

        return [
            'sub' => is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            'name' => is_string($claims['name'] ?? null) ? mb_substr($claims['name'], 0, 200) : null,
            'email' => is_string($claims['email'] ?? null) ? mb_substr($claims['email'], 0, 254) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function connection(): ?array
    {
        $raw = $this->globals->get(self::CONNECTION_KEY);
        $decoded = $raw === null ? null : json_decode($raw, true);

        return is_array($decoded) && is_string($decoded['store_host'] ?? null) ? $decoded : null;
    }

    private function refreshToken(): ?string
    {
        $ciphertext = $this->globals->get(self::REFRESH_TOKEN_KEY);
        if ($ciphertext === null || $ciphertext === '') {
            return null;
        }
        try {
            return $this->secrets->decrypt($ciphertext);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function pending(): ?array
    {
        $ciphertext = $this->globals->get(self::PENDING_KEY);
        if ($ciphertext === null || $ciphertext === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->secrets->decrypt($ciphertext), true);
        } catch (\RuntimeException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{token: string, expires_at: int}|null */
    private function cachedAccessToken(string $clientId): ?array
    {
        $ciphertext = $this->globals->get(self::ACCESS_TOKEN_KEY);
        if ($ciphertext === null || $ciphertext === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->secrets->decrypt($ciphertext), true);
        } catch (\RuntimeException) {
            return null;
        }
        if (!is_array($decoded) || ($decoded['client_id'] ?? null) !== $clientId
            || !is_string($decoded['token'] ?? null) || !is_int($decoded['expires_at'] ?? null)
        ) {
            return null;
        }

        return ['token' => $decoded['token'], 'expires_at' => $decoded['expires_at']];
    }

    private function cacheAccessToken(string $token, int $expiresAt, string $clientId): void
    {
        $this->globals->set(self::ACCESS_TOKEN_KEY, $this->secrets->encrypt((string) json_encode([
            'token' => $token,
            'expires_at' => $expiresAt,
            'client_id' => $clientId,
        ])));
    }

    /** @param array<mixed> $response */
    private static function lifetime(array $response): int
    {
        $lifetime = $response['expires_in'] ?? 300;

        return max(30, is_numeric($lifetime) ? (int) $lifetime : 300);
    }

    /**
     * @return array<mixed>
     * @throws WhityStoreConnectException
     */
    private function fetchJson(string $url): array
    {
        try {
            $doc = ($this->getJson)($url, []);
        } catch (\Throwable $e) {
            error_log('[WhityStore] fetch refused/failed: ' . $e->getMessage());
            $doc = null;
        }
        if (!is_array($doc)) {
            throw new WhityStoreConnectException('Could not reach ' . (string) parse_url($url, PHP_URL_HOST) . '.', 502);
        }

        return $doc;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     * @throws WhityStoreConnectException
     */
    private function call(Closure $post, string $url, array $body): array
    {
        try {
            $response = $post($url, $body);
        } catch (\Throwable $e) {
            error_log('[WhityStore] request refused/failed: ' . $e->getMessage());
            $response = null;
        }
        if (!is_array($response)) {
            throw new WhityStoreConnectException('Could not reach Whity ID.', 502);
        }
        if (is_string($response['error'] ?? null)) {
            error_log('[WhityStore] Whity ID refused: ' . $response['error'] . ' ' . (string) ($response['error_description'] ?? ''));
            throw new WhityStoreConnectException('Whity ID refused: ' . (string) ($response['error_description'] ?? $response['error']), 502);
        }

        return $response;
    }

    /** @param int<1, max> $bytes */
    private static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
