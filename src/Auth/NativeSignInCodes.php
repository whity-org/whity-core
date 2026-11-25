<?php

declare(strict_types=1);

namespace Whity\Auth;

use PDO;

/**
 * One-time codes for a native app's sign-in return (WHIT-1406). The SSO
 * callback issues one and sends it to the app's loopback listener; the app
 * exchanges it once, within a minute, with the PKCE verifier whose S256
 * challenge it gave at `/start`. Only the code's hash is stored, and the row
 * goes with the first exchange attempt, so a second attempt finds nothing.
 */
final class NativeSignInCodes
{
    public const TTL_SECONDS = 60;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock Now, as Unix seconds; tests move it. */
    public function __construct(private readonly PDO $pdo, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Mint a code for a person the provider just signed in, and return the
     * code itself: 32 random bytes, base64url, shown to no one but the app.
     */
    public function issue(
        int $profileId,
        string $email,
        int $providerId,
        int $providerTenantId,
        bool $tenantTrust,
        string $codeChallenge,
        int $ttlSeconds = self::TTL_SECONDS,
    ): string {
        $this->purgeExpired();
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $stmt = $this->pdo->prepare(
            'INSERT INTO native_sign_in_codes
                (code_hash, profile_id, email, provider_id, provider_tenant_id, tenant_trust, code_challenge, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, hash('sha256', $code));
        $stmt->bindValue(2, $profileId, PDO::PARAM_INT);
        $stmt->bindValue(3, $email);
        $stmt->bindValue(4, $providerId, PDO::PARAM_INT);
        $stmt->bindValue(5, $providerTenantId, PDO::PARAM_INT);
        $stmt->bindValue(6, $tenantTrust, PDO::PARAM_BOOL);
        $stmt->bindValue(7, $codeChallenge);
        $stmt->bindValue(8, ($this->clock)() + $ttlSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return $code;
    }

    /**
     * Take the row for `$code` out of the table and return it, or null when
     * there is none. Single use under a race too: the DELETE must remove
     * exactly one row for the caller to get it. Expiry is for the caller to
     * check ({@see self::isExpired()}), so an expired code is still spent.
     *
     * @return array{profile_id: int, email: string, provider_id: int, provider_tenant_id: int, tenant_trust: bool, code_challenge: string, expires_at: int}|null
     */
    public function consume(string $code): ?array
    {
        $hash = hash('sha256', $code);
        $select = $this->pdo->prepare(
            'SELECT profile_id, email, provider_id, provider_tenant_id, tenant_trust, code_challenge, expires_at
               FROM native_sign_in_codes WHERE code_hash = ?'
        );
        $select->execute([$hash]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $delete = $this->pdo->prepare('DELETE FROM native_sign_in_codes WHERE code_hash = ?');
        $delete->execute([$hash]);
        if ($delete->rowCount() !== 1) {
            return null;
        }

        return [
            'profile_id'         => (int) $row['profile_id'],
            'email'              => (string) $row['email'],
            'provider_id'        => (int) $row['provider_id'],
            'provider_tenant_id' => (int) $row['provider_tenant_id'],
            'tenant_trust'       => filter_var($row['tenant_trust'], FILTER_VALIDATE_BOOLEAN),
            'code_challenge'     => (string) $row['code_challenge'],
            'expires_at'         => (int) $row['expires_at'],
        ];
    }

    /** @param array{expires_at: int} $row */
    public function isExpired(array $row): bool
    {
        return $row['expires_at'] <= ($this->clock)();
    }

    /** The PKCE S256 challenge for a verifier, as RFC 7636 spells it. */
    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function purgeExpired(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM native_sign_in_codes WHERE expires_at < ?');
        $stmt->bindValue(1, ($this->clock)() - 3600, PDO::PARAM_INT);
        $stmt->execute();
    }
}
