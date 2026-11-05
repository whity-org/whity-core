<?php

declare(strict_types=1);

namespace Whity\Core\Mail\OAuth;

use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;

/**
 * Where SMTP OAuth2 state lives (WHIT-901): `app_settings`, the same home and the
 * same pattern as the SMTP password. Mail is configured once for the instance, so
 * there is no tenant column to scope by, and the handler only lets the system
 * tenant reach it.
 *
 * None of these keys is in {@see SettingsRegistry}, which is what keeps them off
 * GET /settings and out of the settings catalogue plugins can read.
 *
 * The access token is cached ENCRYPTED here rather than held in memory. Workers
 * are separate processes, so a memory cache would mean one refresh per worker and
 * a fresh one after every restart.
 */
final class SmtpOAuthStore
{
    public const CLIENT_SECRET_KEY = 'mail.oauth.client_secret_encrypted';
    public const REFRESH_TOKEN_KEY = 'mail.oauth.refresh_token_encrypted';
    public const ACCESS_TOKEN_KEY = 'mail.oauth.access_token_encrypted';
    public const STATUS_KEY = 'mail.oauth.status';
    public const REFRESH_LEASE_KEY = 'mail.oauth.refresh_lease';

    public function __construct(
        private readonly GlobalSettingsRepository $globals,
        private readonly EncryptedSecretStore $secrets,
    ) {
    }

    /** @param array<string, string> $global The merged global settings. */
    public function credentials(array $global): SmtpOAuthCredentials
    {
        return new SmtpOAuthCredentials(
            (string) ($global[SettingsRegistry::MAIL_OAUTH_PROVIDER] ?? ''),
            (string) ($global[SettingsRegistry::MAIL_OAUTH_MICROSOFT_TENANT] ?? ''),
            trim((string) ($global[SettingsRegistry::MAIL_OAUTH_CLIENT_ID] ?? '')),
            $this->nonEmpty(self::CLIENT_SECRET_KEY),
            $this->nonEmpty(self::REFRESH_TOKEN_KEY),
        );
    }

    /** @throws \RuntimeException When the value does not decrypt. */
    public function decrypt(string $ciphertext): string
    {
        return $this->secrets->decrypt($ciphertext);
    }

    public function setClientSecret(#[\SensitiveParameter] string $secret): void
    {
        $this->globals->set(self::CLIENT_SECRET_KEY, $this->secrets->encrypt($secret));
    }

    public function setRefreshToken(#[\SensitiveParameter] string $token): void
    {
        $this->globals->set(self::REFRESH_TOKEN_KEY, $this->secrets->encrypt($token));
    }

    public function clearClientSecret(): void
    {
        $this->globals->delete(self::CLIENT_SECRET_KEY);
    }

    public function clearRefreshToken(): void
    {
        $this->globals->delete(self::REFRESH_TOKEN_KEY);
    }

    /** Drop what was derived from the old credentials once they change. */
    public function forgetDerivedState(): void
    {
        $this->globals->delete(self::ACCESS_TOKEN_KEY);
        $this->globals->delete(self::STATUS_KEY);
    }

    /**
     * Swap in a refresh token the provider rotated, unless a person replaced the
     * stored one while the refresh was running. Theirs always wins.
     *
     * @return string|null The new ciphertext, or null when the swap lost.
     */
    public function rotateRefreshToken(string $expectedCiphertext, #[\SensitiveParameter] string $token): ?string
    {
        $ciphertext = $this->secrets->encrypt($token);

        return $this->globals->compareAndSet(self::REFRESH_TOKEN_KEY, $expectedCiphertext, $ciphertext)
            ? $ciphertext
            : null;
    }

    /** The raw cache row, so a waiting worker can tell it changed without decrypting. */
    public function cachedTokenRow(): ?string
    {
        return $this->globals->get(self::ACCESS_TOKEN_KEY);
    }

    /**
     * The cached access token for this exact connection, or null. A row that does
     * not decrypt counts as no cache: the next refresh replaces it.
     *
     * @return array{token: string, expires_at: int}|null
     */
    public function cachedToken(string $binding): ?array
    {
        $row = $this->cachedTokenRow();
        if ($row === null || $row === '') {
            return null;
        }

        try {
            $decoded = json_decode($this->secrets->decrypt($row), true);
        } catch (\RuntimeException) {
            return null;
        }

        if (!is_array($decoded)
            || ($decoded['binding'] ?? null) !== $binding
            || !is_string($decoded['token'] ?? null)
            || !is_int($decoded['expires_at'] ?? null)
        ) {
            return null;
        }

        return ['token' => $decoded['token'], 'expires_at' => $decoded['expires_at']];
    }

    public function cacheToken(#[\SensitiveParameter] string $token, int $expiresAt, string $binding): void
    {
        $this->globals->set(self::ACCESS_TOKEN_KEY, $this->secrets->encrypt((string) json_encode([
            'token' => $token,
            'expires_at' => $expiresAt,
            'binding' => $binding,
        ])));
    }

    /** @return array{state: SmtpOAuthState, at: int, binding: string}|null */
    public function status(): ?array
    {
        $row = $this->globals->get(self::STATUS_KEY);
        $decoded = $row === null ? null : json_decode($row, true);
        if (!is_array($decoded) || !is_string($decoded['state'] ?? null)) {
            return null;
        }

        $state = SmtpOAuthState::tryFrom($decoded['state']);
        if ($state === null) {
            return null;
        }

        return [
            'state' => $state,
            'at' => (int) ($decoded['at'] ?? 0),
            'binding' => (string) ($decoded['binding'] ?? ''),
        ];
    }

    public function recordStatus(SmtpOAuthState $state, int $at, string $binding): void
    {
        $this->globals->set(self::STATUS_KEY, (string) json_encode([
            'state' => $state->value,
            'at' => $at,
            'binding' => $binding,
        ]));
    }

    public function clearStatus(): void
    {
        $this->globals->delete(self::STATUS_KEY);
    }

    public function claimRefreshLease(int $ttlSeconds, int $now): ?string
    {
        return $this->globals->claimLease(self::REFRESH_LEASE_KEY, $ttlSeconds, $now);
    }

    public function releaseRefreshLease(string $lease): void
    {
        $this->globals->releaseLease(self::REFRESH_LEASE_KEY, $lease);
    }

    private function nonEmpty(string $key): ?string
    {
        $value = $this->globals->get($key);

        return $value === null || $value === '' ? null : $value;
    }
}
