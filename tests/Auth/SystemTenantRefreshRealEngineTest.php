<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;

/**
 * WHIT-1601 / whity-core#1601: a system-tenant (tenant 0) session must refresh.
 *
 * Operators sign in to the system workspace (active_tenant_id = 0). handleRefresh()
 * used to reject `active_tenant_id <= 0`, so every system-tenant refresh returned
 * 401 and operators were signed out at the 15-minute access-token expiry.
 *
 * Real-engine (in-memory SQLite from the genuine migrations). All identities are
 * synthetic (example.test addresses, no real credentials).
 */
final class SystemTenantRefreshRealEngineTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const PASSWORD = 'synthetic-password-for-tests';
    private const SYSTEM_TENANT_ID = 0;
    private const ADMIN_ROLE_ID = 1;

    private PDO $pdo;
    private JwtParser $jwtParser;
    private AuthHandler $authHandler;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();
        // Migration 010 seeds the system tenant (id 0); the admin role is seeded here.
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->authHandler = new AuthHandler($this->pdo, $this->jwtParser, new TokenValidator($this->jwtParser, $this->pdo));
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    /**
     * A system-tenant admin logs in and refreshes: 200 with rotated tokens.
     * Cookie mode: the refresh token arrives in the refresh_token cookie.
     */
    public function testSystemTenantAdminCanRefreshWithCookie(): void
    {
        $this->seedSystemAdmin('sysadmin-cookie@example.test');
        $_COOKIE['refresh_token'] = $this->mintRefresh('sysadmin-cookie@example.test', self::SYSTEM_TENANT_ID);

        $response = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('success', $body['status']);
    }

    /**
     * Full flow in token mode: login as a system-tenant admin returns the refresh
     * token in the body, and presenting it to refresh returns 200 with new tokens.
     */
    public function testSystemTenantAdminLoginThenRefreshInTokenMode(): void
    {
        $this->seedSystemAdmin('sysadmin-token@example.test');

        $login = $this->authHandler->handle(new Request(
            'POST',
            '/api/login',
            ['X-Auth-Mode' => 'token'],
            (string) json_encode(['email' => 'sysadmin-token@example.test', 'password' => self::PASSWORD])
        ));
        $this->assertSame(200, $login->getStatusCode(), $login->getBody());
        $loginBody = json_decode($login->getBody(), true);
        $this->assertIsString($loginBody['refresh_token'] ?? null);

        $refresh = $this->authHandler->handleRefresh(new Request(
            'POST',
            '/api/auth/refresh',
            ['Authorization' => 'Bearer ' . $loginBody['refresh_token'], 'X-Auth-Mode' => 'token']
        ));
        $this->assertSame(200, $refresh->getStatusCode(), $refresh->getBody());
        $refreshBody = json_decode($refresh->getBody(), true);
        $this->assertIsString($refreshBody['access_token'] ?? null);
        $this->assertIsString($refreshBody['refresh_token'] ?? null);
        $this->assertNotSame($loginBody['refresh_token'], $refreshBody['refresh_token'], 'Refresh must rotate the token.');
    }

    /**
     * Reuse detection still works for a system-tenant session: the first use of a
     * refresh token rotates it, and presenting the old token again is refused (401)
     * and its jti stays revoked.
     */
    public function testSystemTenantRefreshTokenReuseIsRefused(): void
    {
        $this->seedSystemAdmin('sysadmin-reuse@example.test');
        $old = $this->mintRefresh('sysadmin-reuse@example.test', self::SYSTEM_TENANT_ID);
        $oldClaims = $this->jwtParser->parse($old);
        $this->assertIsArray($oldClaims);
        $oldJti = (string) $oldClaims['jti'];

        $_COOKIE['refresh_token'] = $old;
        $first = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        $this->assertSame(200, $first->getStatusCode());

        $_COOKIE['refresh_token'] = $old;
        $second = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        $this->assertSame(401, $second->getStatusCode(), 'A reused system-tenant refresh token must be refused.');

        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ? LIMIT 1');
        $stmt->execute([$oldJti]);
        $this->assertNotFalse($stmt->fetchColumn(), 'The reused token jti must remain revoked.');
    }

    /**
     * Fail closed: a refresh token with no active_tenant_id claim is refused.
     */
    public function testRefreshWithMissingTenantClaimIsRefused(): void
    {
        $this->seedSystemAdmin('sysadmin-missing@example.test');
        $_COOKIE['refresh_token'] = $this->jwtParser->create([
            'profile_id' => $this->profileIdOf('sysadmin-missing@example.test'),
            'email'      => 'sysadmin-missing@example.test',
            'role'       => 'admin',
            'token_epoch' => 0,
        ], 604800, 'refresh');

        $response = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Fail closed: a negative tenant id is refused, never treated as system authority.
     */
    public function testRefreshWithNegativeTenantClaimIsRefused(): void
    {
        $this->seedSystemAdmin('sysadmin-negative@example.test');
        $_COOKIE['refresh_token'] = $this->mintRefresh('sysadmin-negative@example.test', -1);

        $response = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));

        $this->assertSame(401, $response->getStatusCode());
    }

    private function mintRefresh(string $email, int $tenantId): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $this->profileIdOf($email),
            'active_tenant_id' => $tenantId,
            'email'            => $email,
            'role'             => 'admin',
            'token_epoch'      => 0,
        ], 604800, 'refresh');
    }

    private function profileIdOf(string $email): int
    {
        $stmt = $this->pdo->prepare('SELECT profile_id FROM profile_emails WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Seed a synthetic profile with a verified primary email and an active
     * membership in the system tenant (id 0) as admin.
     */
    private function seedSystemAdmin(string $email): void
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([explode('@', $email)[0], password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([$profileId, $email]);

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([$profileId, self::SYSTEM_TENANT_ID, self::ADMIN_ROLE_ID]);
    }
}
