<?php

declare(strict_types=1);

namespace Whity\Core\Mail\OAuth;

use Closure;
use Psr\Log\LoggerInterface;
use Whity\Core\Http\HttpClient;
use Whity\Core\Settings\SettingsService;

/**
 * The access-token lifecycle behind XOAUTH2 mail (WHIT-901).
 *
 * - A token is refreshed {@see self::REFRESH_AHEAD_SECONDS} before it expires.
 * - One worker refreshes at a time, under a lease in `app_settings` that works the
 *   same on PostgreSQL and SQLite. While that runs, the others keep using a token
 *   that still has life in it, or wait briefly for the new one, so a busy queue
 *   never becomes a stampede on the provider.
 * - A failed refresh is written down as an enumerated {@see SmtpOAuthState}. One
 *   only a person can fix stops further calls for
 *   {@see self::PERSON_RETRY_SECONDS}, and a temporary one for
 *   {@see self::TEMPORARY_RETRY_SECONDS}.
 *
 * Nothing here logs or throws a token, a secret, or the provider's own error text.
 * The log gets the state code and nothing else.
 */
final class SmtpOAuthTokenManager
{
    public const REFRESH_AHEAD_SECONDS = 120;
    /** Below this a token is not worth using: it could expire during the dialogue. */
    private const MIN_USABLE_SECONDS = 10;
    private const DEFAULT_LIFETIME_SECONDS = 3600;
    private const MIN_LIFETIME_SECONDS = 60;

    /** Longer than the token request's own timeout, so a live holder keeps the lease. */
    private const LEASE_SECONDS = 30;
    private const WAIT_SECONDS = 12;
    private const WAIT_STEP_MICROSECONDS = 250_000;

    public const PERSON_RETRY_SECONDS = 900;
    public const TEMPORARY_RETRY_SECONDS = 60;

    private const NEEDS_RECONNECT_ERRORS = ['invalid_grant'];
    private const CLIENT_REJECTED_ERRORS = ['invalid_client', 'unauthorized_client', 'invalid_scope', 'access_denied'];

    /** @var Closure(): int */
    private Closure $clock;
    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param (Closure(): int)|null $clock Test seam.
     * @param (Closure(int): void)|null $sleep Test seam, in microseconds.
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SmtpOAuthStore $store,
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        ?Closure $clock = null,
        ?Closure $sleep = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * A token good enough to send with.
     *
     * @param string|null $rejected A token the mail server just refused. It is
     *        never handed back, which is what forces the refresh on a retry.
     * @throws OAuthTokenException When no usable token can be had.
     */
    public function accessToken(#[\SensitiveParameter] ?string $rejected = null): string
    {
        $deadline = ($this->clock)() + self::WAIT_SECONDS;

        while (true) {
            $credentials = $this->credentials();
            if (!$credentials->isComplete()) {
                throw new OAuthTokenException(SmtpOAuthState::NotConnected);
            }

            $seenRow = $this->store->cachedTokenRow();
            $cached = $this->usableCachedToken($credentials, $rejected);
            if ($cached !== null && $this->isFresh($cached['expires_at'])) {
                return $cached['token'];
            }

            $lease = $this->store->claimRefreshLease(self::LEASE_SECONDS, ($this->clock)());
            if ($lease !== null) {
                try {
                    return $this->refreshHoldingLease($rejected);
                } finally {
                    $this->store->releaseRefreshLease($lease);
                }
            }

            // Another worker is refreshing. A token with life left carries this send.
            if ($cached !== null) {
                return $cached['token'];
            }

            $this->waitForAnotherWorker($seenRow, $deadline);
        }
    }

    /** Record that a mail server refused a freshly refreshed token. */
    public function recordSmtpRejected(): void
    {
        $this->record(SmtpOAuthState::SmtpRejected, $this->credentials()->binding());
    }

    /** Record a delivered message. Writes only on a change, not on every send. */
    public function recordConnected(): void
    {
        $binding = $this->credentials()->binding();
        $status = $this->store->status();
        if ($status !== null && $status['state'] === SmtpOAuthState::Connected && $status['binding'] === $binding) {
            return;
        }
        $this->store->recordStatus(SmtpOAuthState::Connected, ($this->clock)(), $binding);
    }

    /**
     * Forget a recorded failure so the next send asks the provider again. A person
     * pressing "send test" has probably just fixed something on the provider's side.
     */
    public function clearFailure(): void
    {
        $status = $this->store->status();
        if ($status !== null && $status['state'] !== SmtpOAuthState::Connected) {
            $this->store->clearStatus();
        }
    }

    /**
     * What GET /mail/status reports. Secrets appear only as whether they exist.
     *
     * @return array{
     *     provider: string,
     *     has_client_id: bool,
     *     has_client_secret: bool,
     *     has_refresh_token: bool,
     *     state: string,
     *     state_since: int|null
     * }
     */
    public function describe(string $host, string $encryption): array
    {
        $credentials = $this->credentials();
        $state = SmtpOAuthState::NotConnected;
        $since = null;

        if ($credentials->isComplete()) {
            $state = self::connectionProblem($credentials->provider, $host, $encryption) ?? SmtpOAuthState::NotYetUsed;
            $status = $this->store->status();
            if ($state === SmtpOAuthState::NotYetUsed && $status !== null && $status['binding'] === $credentials->binding()) {
                $state = $status['state'];
                $since = $status['at'];
            }
        }

        return [
            'provider' => $credentials->provider,
            'has_client_id' => $credentials->clientId !== '',
            'has_client_secret' => $credentials->clientSecretCiphertext !== null,
            'has_refresh_token' => $credentials->refreshTokenCiphertext !== null,
            'state' => $state->value,
            'state_since' => $since,
        ];
    }

    /** Whether the chosen host and encryption are fit to receive this provider's token. */
    public static function connectionProblem(string $provider, string $host, string $encryption): ?SmtpOAuthState
    {
        if (!SmtpOAuthProvider::allowsSmtpHost($provider, $host)) {
            return SmtpOAuthState::SmtpHostNotAllowed;
        }
        if ($encryption === 'none' && !SmtpOAuthProvider::isLoopback($host)) {
            return SmtpOAuthState::InsecureConnection;
        }

        return null;
    }

    public function provider(): string
    {
        return $this->credentials()->provider;
    }

    private function credentials(): SmtpOAuthCredentials
    {
        return $this->store->credentials($this->settings->getGlobal());
    }

    /** @return array{token: string, expires_at: int}|null */
    private function usableCachedToken(SmtpOAuthCredentials $credentials, #[\SensitiveParameter] ?string $rejected): ?array
    {
        $cached = $this->store->cachedToken($credentials->binding());
        if ($cached === null
            || $cached['token'] === $rejected
            || ($this->clock)() >= $cached['expires_at'] - self::MIN_USABLE_SECONDS
        ) {
            return null;
        }

        return $cached;
    }

    private function isFresh(int $expiresAt): bool
    {
        return ($this->clock)() < $expiresAt - self::REFRESH_AHEAD_SECONDS;
    }

    private function waitForAnotherWorker(?string $seenRow, int $deadline): void
    {
        while (($this->clock)() < $deadline) {
            ($this->sleep)(self::WAIT_STEP_MICROSECONDS);
            if ($this->store->cachedTokenRow() !== $seenRow) {
                return;
            }
            // Try the lease as well: the holder may have failed or died.
            $lease = $this->store->claimRefreshLease(self::LEASE_SECONDS, ($this->clock)());
            if ($lease !== null) {
                $this->store->releaseRefreshLease($lease);
                return;
            }
        }

        throw new OAuthTokenException(SmtpOAuthState::TokenServiceUnavailable);
    }

    private function refreshHoldingLease(#[\SensitiveParameter] ?string $rejected): string
    {
        // Read again now the lease is held: the last holder may have just finished.
        $credentials = $this->credentials();
        if (!$credentials->isComplete()) {
            throw new OAuthTokenException(SmtpOAuthState::NotConnected);
        }

        $cached = $this->usableCachedToken($credentials, $rejected);
        if ($cached !== null && $this->isFresh($cached['expires_at'])) {
            return $cached['token'];
        }

        try {
            $this->assertNotHeldBack($credentials->binding());

            return $this->refresh($credentials);
        } catch (OAuthTokenException $e) {
            // A token nearing its expiry still works, so a failed early refresh
            // costs nothing yet. The recorded status has already told the operator.
            if ($cached !== null) {
                return $cached['token'];
            }
            throw $e;
        }
    }

    private function assertNotHeldBack(string $binding): void
    {
        $status = $this->store->status();
        if ($status === null || $status['binding'] !== $binding) {
            return;
        }

        $age = ($this->clock)() - $status['at'];
        $state = $status['state'];
        if (($state->needsPerson() && $age < self::PERSON_RETRY_SECONDS)
            || ($state === SmtpOAuthState::TokenServiceUnavailable && $age < self::TEMPORARY_RETRY_SECONDS)
        ) {
            throw new OAuthTokenException($state);
        }
    }

    private function refresh(SmtpOAuthCredentials $credentials): string
    {
        $binding = $credentials->binding();

        try {
            $params = [
                'grant_type' => 'refresh_token',
                'client_id' => $credentials->clientId,
                'client_secret' => $this->store->decrypt((string) $credentials->clientSecretCiphertext),
                'refresh_token' => $this->store->decrypt((string) $credentials->refreshTokenCiphertext),
            ];
        } catch (\RuntimeException) {
            throw $this->fail(SmtpOAuthState::CredentialsUnreadable, $binding);
        }

        $scope = SmtpOAuthProvider::refreshScope($credentials->provider);
        if ($scope !== null) {
            $params['scope'] = $scope;
        }

        try {
            $response = $this->http->postForm(
                SmtpOAuthProvider::tokenEndpoint($credentials->provider, $credentials->microsoftTenant),
                $params,
            );
        } catch (\Throwable) {
            $response = null;
        }

        $token = $response['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw $this->fail($this->stateForError($response), $binding);
        }

        $lifetime = $response['expires_in'] ?? self::DEFAULT_LIFETIME_SECONDS;
        $lifetime = is_numeric($lifetime) ? (int) $lifetime : self::DEFAULT_LIFETIME_SECONDS;
        $expiresAt = ($this->clock)() + max(self::MIN_LIFETIME_SECONDS, $lifetime);

        // Microsoft rotates refresh tokens; Google normally does not.
        $after = $credentials;
        $rotated = $response['refresh_token'] ?? null;
        if (is_string($rotated) && $rotated !== '' && $rotated !== $params['refresh_token']) {
            $ciphertext = $this->store->rotateRefreshToken((string) $credentials->refreshTokenCiphertext, $rotated);
            if ($ciphertext === null) {
                throw new OAuthTokenException(SmtpOAuthState::TokenServiceUnavailable);
            }
            $after = $credentials->withRefreshTokenCiphertext($ciphertext);
        }

        // Someone may have changed the credentials while the request ran. A token
        // from the old ones is not cached and not used: the next send starts clean.
        if ($this->credentials()->binding() !== $after->binding()) {
            throw new OAuthTokenException(SmtpOAuthState::TokenServiceUnavailable);
        }

        $this->store->cacheToken($token, $expiresAt, $after->binding());

        // A recovered connection stops showing the old failure.
        $status = $this->store->status();
        if ($status !== null && $status['state'] !== SmtpOAuthState::Connected) {
            $this->store->clearStatus();
        }

        return $token;
    }

    /** @param array<string, mixed>|null $response */
    private function stateForError(?array $response): SmtpOAuthState
    {
        $error = $response['error'] ?? null;
        if (in_array($error, self::NEEDS_RECONNECT_ERRORS, true)) {
            return SmtpOAuthState::NeedsReconnect;
        }
        if (in_array($error, self::CLIENT_REJECTED_ERRORS, true)) {
            return SmtpOAuthState::ClientRejected;
        }

        return SmtpOAuthState::TokenServiceUnavailable;
    }

    private function fail(SmtpOAuthState $state, string $binding): OAuthTokenException
    {
        $this->record($state, $binding);

        return new OAuthTokenException($state);
    }

    /** Write the state down, and raise it in the log once rather than on every send. */
    private function record(SmtpOAuthState $state, string $binding): void
    {
        $previous = $this->store->status();
        $this->store->recordStatus($state, ($this->clock)(), $binding);

        if ($previous === null || $previous['state'] !== $state || $previous['binding'] !== $binding) {
            $this->logger->error('[mail] SMTP OAuth2 connection needs attention', ['state' => $state->value]);
        }
    }
}
