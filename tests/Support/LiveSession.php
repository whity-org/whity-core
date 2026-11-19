<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Whity\Auth\JwtParser;

/**
 * Records the `sessions` row a real sign-in would have recorded for a refresh
 * token a test minted by hand. handleRefresh() rotates a refresh token only
 * inside a live session row (#2265), so a bare minted token is refused, as it
 * would be in production.
 */
final class LiveSession
{
    /** Record a live session for $refreshToken and hand the token back. */
    public static function forRefresh(PDO $pdo, JwtParser $jwt, string $refreshToken): string
    {
        $claims = $jwt->parse($refreshToken);
        if ($claims === null || !is_string($claims['jti'] ?? null)) {
            return $refreshToken;
        }

        $profileId = (int) ($claims['profile_id'] ?? 0);
        $tenantId = (int) ($claims['active_tenant_id'] ?? 0);
        if ($profileId <= 0 || $tenantId < 0) {
            // A malformed claim set a test hands in on purpose: the refresh is
            // refused on the claims, and no row could reference it anyway.
            return $refreshToken;
        }

        $pdo->prepare(
            'INSERT INTO sessions (profile_id, tenant_id, refresh_jti, access_jti, expires_at)
             VALUES (?, ?, ?, NULL, ?)'
        )->execute([
            $profileId,
            $tenantId,
            $claims['jti'],
            date('Y-m-d H:i:s', is_int($claims['exp'] ?? null) ? $claims['exp'] : time() + 604800),
        ]);

        return $refreshToken;
    }
}
