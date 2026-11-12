<?php

declare(strict_types=1);

namespace Whity\Core\Taskaat;

use Closure;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * One workspace's connection to Taskaat (T.3): tasks, boards, checks, evidence and decisions live
 * in Taskaat, and Whity reads and writes them with the workspace's token. Whity never keeps a
 * second copy of a task.
 *
 * HOW IT CONNECTS. Taskaat is an OAuth 2.1 authorization server with dynamic client registration
 * (RFC 7591) and PKCE (S256), public clients only. A person with `tasks:manage` presses Connect;
 * this registers Whity as a client (Taskaat dedupes on name and redirect URIs, so a reconnect gets
 * the same client), sends the person to Taskaat's consent page, and trades the code that comes
 * back for an access token (30 days) and a refresh token (365 days, rotated on use).
 *
 * WHERE IT LIVES. `tenant_settings`, under keys the settings catalogue does not know, so they never
 * appear in the settings API (SettingsService::effective lists catalogue keys only):
 * - `taskaat.connection`: who connected and when, the client id, and whether it needs a person to
 *   connect again. Plain JSON: nothing secret.
 * - `taskaat.refresh_token` and `taskaat.access_token`: encrypted with the platform secret store.
 * - `taskaat.pending`: the sign-in in flight (state hash, PKCE verifier), encrypted, single use,
 *   ten minutes.
 *
 * Every read and write names the tenant, so one workspace's token can never be used for another.
 */
final class TaskaatConnection
{
    public const DEFAULT_ORIGIN = 'https://app.taskaat.dev';
    private const MCP_PATH = '/api/mcp';

    private const CONNECTION_KEY = 'taskaat.connection';
    private const REFRESH_TOKEN_KEY = 'taskaat.refresh_token';
    private const ACCESS_TOKEN_KEY = 'taskaat.access_token';
    private const PENDING_KEY = 'taskaat.pending';

    private const PENDING_SECONDS = 600;
    /** Taskaat's access tokens last 30 days; refresh a day before they lapse. */
    private const REFRESH_AHEAD_SECONDS = 86400;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(string, array<string, string>): ?array<mixed> $getJson
     * @param Closure(string, array<string, string>): ?array<mixed> $postForm
     * @param Closure(string, array<string, mixed>): ?array<mixed> $postJson
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly TenantSettingsRepository $settings,
        private readonly EncryptedSecretStore $secrets,
        private readonly string $origin,
        private readonly string $redirectUri,
        private readonly string $clientName,
        private readonly Closure $getJson,
        private readonly Closure $postForm,
        private readonly Closure $postJson,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Taskaat's origin: `TASKAAT_URL` when it is https, else app.taskaat.dev. */
    public static function originFromEnv(): string
    {
        $value = $_ENV['TASKAAT_URL'] ?? getenv('TASKAAT_URL');
        $value = is_string($value) ? rtrim(trim($value), '/') : '';

        return str_starts_with($value, 'https://') ? $value : self::DEFAULT_ORIGIN;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * Taskaat's MCP endpoint, where every read and write goes. Taskaat's own path on Taskaat's
     * origin, not a Whity route, so Whity's /v1 prefix does not apply.
     */
    public function mcpEndpoint(): string
    {
        return $this->origin . self::MCP_PATH;
    }

    /**
     * "Open in Taskaat": the same task on its board in Taskaat's web app.
     */
    public function taskLink(string $projectSlug, string $taskId): string
    {
        return $this->origin . '/dashboard/' . rawurlencode($projectSlug) . '?task=' . rawurlencode($taskId);
    }

    /** "Open in Taskaat" for a whole board (a Taskaat project). */
    public function projectLink(string $projectSlug): string
    {
        return $this->origin . '/dashboard/' . rawurlencode($projectSlug);
    }

    // ── Connecting ──────────────────────────────────────────────────────────

    /**
     * Begin connecting this workspace. Returns Taskaat's consent page URL to send the person to.
     *
     * @throws TaskaatException
     */
    public function start(int $tenantId, ?int $actor): string
    {
        if ($this->redirectUri === '') {
            throw new TaskaatException('Whity does not know its own web address, so Taskaat cannot send you back. Set FRONTEND_URL.', 503);
        }

        $endpoints = $this->discover();
        $clientId = $this->register($endpoints['registration_endpoint']);

        $state = self::random();
        $verifier = self::random(48);

        $this->settings->set($tenantId, self::PENDING_KEY, $this->secrets->encrypt((string) json_encode([
            'state' => hash('sha256', $state),
            'verifier' => $verifier,
            'client_id' => $clientId,
            'token_endpoint' => $endpoints['token_endpoint'],
            'actor' => $actor,
            'expires_at' => ($this->clock)() + self::PENDING_SECONDS,
        ])));

        return $endpoints['authorization_endpoint'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Finish connecting with the code Taskaat sent back. Single use: the pending sign-in is gone
     * whether this succeeds or not.
     *
     * @return array<string, mixed> The new status.
     * @throws TaskaatException
     */
    public function complete(int $tenantId, string $code, string $state, ?int $actor): array
    {
        $pending = $this->pending($tenantId);
        $this->settings->delete($tenantId, self::PENDING_KEY);

        if ($pending === null
            || !hash_equals((string) $pending['state'], hash('sha256', $state))
            || (int) $pending['expires_at'] < ($this->clock)()
        ) {
            throw new TaskaatException('This sign-in has expired or was not started here. Connect again.', 422);
        }
        if ($pending['actor'] !== null && $actor !== null && (int) $pending['actor'] !== $actor) {
            throw new TaskaatException('Finish connecting as the person who started it.', 403);
        }

        $response = $this->call($this->postForm, (string) $pending['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => (string) $pending['client_id'],
            'code_verifier' => (string) $pending['verifier'],
        ]);
        $accessToken = $response['access_token'] ?? null;
        $refreshToken = $response['refresh_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '' || !is_string($refreshToken) || $refreshToken === '') {
            throw new TaskaatException('Taskaat did not connect this workspace. Try again.', 502);
        }

        $now = ($this->clock)();
        $this->settings->set($tenantId, self::CONNECTION_KEY, (string) json_encode([
            'origin' => $this->origin,
            'client_id' => (string) $pending['client_id'],
            'token_endpoint' => (string) $pending['token_endpoint'],
            'connected_at' => $now,
            'connected_by' => $actor,
            'needs_reconnect' => false,
        ], JSON_UNESCAPED_SLASHES));
        $this->settings->set($tenantId, self::REFRESH_TOKEN_KEY, $this->secrets->encrypt($refreshToken));
        $this->cacheAccessToken($tenantId, $accessToken, $now + self::lifetime($response));

        return $this->status($tenantId);
    }

    /**
     * Forget the connection. Taskaat has no revocation endpoint; the person removes Whity from
     * Taskaat's Settings › Connected apps to revoke the token there too (the status says so).
     */
    public function disconnect(int $tenantId): void
    {
        foreach ([self::CONNECTION_KEY, self::REFRESH_TOKEN_KEY, self::ACCESS_TOKEN_KEY, self::PENDING_KEY] as $key) {
            $this->settings->delete($tenantId, $key);
        }
    }

    /**
     * What the Tasks page and settings show.
     *
     * @return array<string, mixed>
     */
    public function status(int $tenantId): array
    {
        $connection = $this->connection($tenantId);
        if ($connection === null) {
            return ['connected' => false, 'taskaat_url' => $this->origin];
        }

        return [
            'connected' => true,
            'taskaat_url' => (string) ($connection['origin'] ?? $this->origin),
            'connected_at' => gmdate('c', (int) ($connection['connected_at'] ?? 0)),
            'connected_by' => isset($connection['connected_by']) ? (int) $connection['connected_by'] : null,
            'needs_reconnect' => (bool) ($connection['needs_reconnect'] ?? false),
        ];
    }

    public function isConnected(int $tenantId): bool
    {
        return $this->connection($tenantId) !== null;
    }

    // ── Tokens ──────────────────────────────────────────────────────────────

    /**
     * A usable access token for this workspace, or null when it is not connected or a person has
     * to connect again. `$forceRefresh` after Taskaat answered 401 to the cached token.
     */
    public function accessToken(int $tenantId, bool $forceRefresh = false): ?string
    {
        $connection = $this->connection($tenantId);
        if ($connection === null || ($connection['needs_reconnect'] ?? false)) {
            return null;
        }

        $cached = $this->cachedAccessToken($tenantId);
        if (!$forceRefresh && $cached !== null && $cached['expires_at'] - self::REFRESH_AHEAD_SECONDS > ($this->clock)()) {
            return $cached['token'];
        }

        $refreshed = $this->refresh($tenantId, $connection);
        if ($refreshed !== null) {
            return $refreshed;
        }

        // A token with life left still carries this call; a forced refresh means it was refused.
        return !$forceRefresh && $cached !== null && $cached['expires_at'] > ($this->clock)() ? $cached['token'] : null;
    }

    /** @param array<string, mixed> $connection */
    private function refresh(int $tenantId, array $connection): ?string
    {
        $all = $this->settings->allForTenant($tenantId);
        $ciphertext = $all[self::REFRESH_TOKEN_KEY] ?? null;
        if (!is_string($ciphertext) || $ciphertext === '') {
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
                'client_id' => (string) $connection['client_id'],
            ]);
        } catch (\Throwable $e) {
            error_log('[Taskaat] refresh failed: ' . $e->getMessage());
            $response = null;
        }

        $token = is_array($response) ? ($response['access_token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            if (is_array($response) && ($response['error'] ?? null) === 'invalid_grant') {
                // Another request may have rotated the refresh token a moment ago: use its result.
                $now = $this->settings->allForTenant($tenantId);
                if (($now[self::REFRESH_TOKEN_KEY] ?? null) !== $ciphertext) {
                    return $this->cachedAccessToken($tenantId)['token'] ?? null;
                }
                // Revoked or lapsed in Taskaat: only a person can fix that.
                $connection['needs_reconnect'] = true;
                $this->settings->set($tenantId, self::CONNECTION_KEY, (string) json_encode($connection, JSON_UNESCAPED_SLASHES));
                $this->settings->delete($tenantId, self::ACCESS_TOKEN_KEY);
            }

            return null;
        }

        $rotated = $response['refresh_token'] ?? null;
        if (is_string($rotated) && $rotated !== '' && $rotated !== $refresh) {
            $this->settings->set($tenantId, self::REFRESH_TOKEN_KEY, $this->secrets->encrypt($rotated));
        }
        $this->cacheAccessToken($tenantId, $token, ($this->clock)() + self::lifetime($response));

        return $token;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Taskaat's OAuth endpoints, from its metadata (RFC 8414), on its own origin only.
     *
     * @return array{authorization_endpoint: string, token_endpoint: string, registration_endpoint: string}
     * @throws TaskaatException
     */
    private function discover(): array
    {
        try {
            $meta = ($this->getJson)($this->origin . '/.well-known/oauth-authorization-server', []);
        } catch (\Throwable $e) {
            error_log('[Taskaat] discovery failed: ' . $e->getMessage());
            $meta = null;
        }
        if (!is_array($meta)) {
            throw new TaskaatException('Taskaat is not answering. Try again in a minute.', 502);
        }

        $endpoints = [];
        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $name) {
            $url = $meta[$name] ?? null;
            if (!is_string($url) || !str_starts_with($url, $this->origin . '/')) {
                throw new TaskaatException('Taskaat sent sign-in details this workspace cannot use.', 502);
            }
            $endpoints[$name] = $url;
        }

        return $endpoints;
    }

    /** @throws TaskaatException */
    private function register(string $registrationEndpoint): string
    {
        $response = $this->call($this->postJson, $registrationEndpoint, [
            'client_name' => $this->clientName,
            'redirect_uris' => [$this->redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);
        $clientId = $response['client_id'] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new TaskaatException('Taskaat did not accept Whity as an app. Try again.', 502);
        }

        return $clientId;
    }

    /** @return array<string, mixed>|null */
    private function connection(int $tenantId): ?array
    {
        $raw = $this->settings->allForTenant($tenantId)[self::CONNECTION_KEY] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) && is_string($decoded['client_id'] ?? null) ? $decoded : null;
    }

    /** @return array<string, mixed>|null */
    private function pending(int $tenantId): ?array
    {
        $raw = $this->settings->allForTenant($tenantId)[self::PENDING_KEY] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->secrets->decrypt($raw), true);
        } catch (\RuntimeException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{token: string, expires_at: int}|null */
    private function cachedAccessToken(int $tenantId): ?array
    {
        $raw = $this->settings->allForTenant($tenantId)[self::ACCESS_TOKEN_KEY] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->secrets->decrypt($raw), true);
        } catch (\RuntimeException) {
            return null;
        }
        if (!is_array($decoded) || !is_string($decoded['token'] ?? null) || !is_int($decoded['expires_at'] ?? null)) {
            return null;
        }

        return ['token' => $decoded['token'], 'expires_at' => $decoded['expires_at']];
    }

    private function cacheAccessToken(int $tenantId, string $token, int $expiresAt): void
    {
        $this->settings->set($tenantId, self::ACCESS_TOKEN_KEY, $this->secrets->encrypt((string) json_encode([
            'token' => $token,
            'expires_at' => $expiresAt,
        ])));
    }

    /** @param array<mixed> $response */
    private static function lifetime(array $response): int
    {
        $seconds = $response['expires_in'] ?? null;

        return is_int($seconds) && $seconds > 0 ? $seconds : 3600;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     * @throws TaskaatException
     */
    private function call(Closure $post, string $url, array $body): array
    {
        try {
            $response = $post($url, $body);
        } catch (\Throwable $e) {
            error_log('[Taskaat] ' . $url . ' failed: ' . $e->getMessage());
            $response = null;
        }
        if (!is_array($response)) {
            throw new TaskaatException('Taskaat is not answering. Try again in a minute.', 502);
        }
        if (isset($response['error'])) {
            error_log('[Taskaat] ' . $url . ' refused: ' . (is_string($response['error']) ? $response['error'] : 'error')
                . (is_string($response['error_description'] ?? null) ? ' (' . $response['error_description'] . ')' : ''));
            throw new TaskaatException('Taskaat refused to connect this workspace. Connect again.', 422);
        }

        return $response;
    }

    /** @param positive-int $bytes */
    private static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
