<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\LiveSession;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\SessionService;
use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Request;

/**
 * #2264 / #2191 (owner 2026-11-19: 30-second grace): a refresh token that was
 * already rotated away and comes back ends the whole session, unless it is the
 * token the last rotation replaced and that rotation was under 30 seconds ago
 * (two tabs racing), which only gets a plain 401.
 *
 * Before this, reuse was audited and nothing else: whoever held the newest
 * token of a stolen family kept the session for as long as they kept refreshing.
 */
final class RefreshReuseRevokesSessionRealEngineTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-refresh-reuse-family-min-32-bytes';
    private const PROFILE_ID = 900_264;
    private const TENANT_ID = 1;

    private PDO $pdo;
    private JwtParser $jwt;
    private AuthHandler $auth;

    protected function setUp(): void
    {
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
        $this->jwt = new JwtParser(self::SECRET);
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'Example Bakery', datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'Awss Haddad', 'x', false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([self::PROFILE_ID]);
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, 1, 'active', datetime('now'))"
        )->execute([self::PROFILE_ID, self::TENANT_ID]);

        $this->auth = new AuthHandler(
            $this->pdo,
            $this->jwt,
            new TokenValidator($this->jwt, $this->pdo),
            null,
            null,
            null,
            new AuditLogger($this->pdo)
        );
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    public function testEveryRefreshTokenOfASessionNamesItsFamily(): void
    {
        $first = $this->refresh($this->signIn());
        self::assertSame(200, $first['status']);
        $family = $this->claims($first['refresh_token'])['fam'] ?? null;
        self::assertIsString($family);
        self::assertSame($family, $this->sessionRow()['family']);

        $second = $this->refresh($first['refresh_token']);
        self::assertSame(200, $second['status']);
        self::assertSame($family, $this->claims($second['refresh_token'])['fam'] ?? null);
    }

    public function testAReuseWithinThirtySecondsOfItsRotationIsAPlain401(): void
    {
        $t1 = $this->signIn();
        $t2 = $this->refresh($t1);
        self::assertSame(200, $t2['status']);

        self::assertSame(401, $this->refresh($t1)['status'], 'the replaced token never works again');
        self::assertSame('grace', $this->lastReuseOutcome());
        self::assertNull($this->sessionRow()['revoked_at'], 'the session carries on');

        self::assertSame(200, $this->refresh($t2['refresh_token'])['status'], 'the newest token still refreshes');
    }

    public function testALaterReuseRevokesTheWholeSession(): void
    {
        $t1 = $this->signIn();
        $t2 = $this->refresh($t1);
        self::assertSame(200, $t2['status']);
        $this->ageLastRotation(31);

        self::assertSame(401, $this->refresh($t1)['status']);
        self::assertSame('revoked', $this->lastReuseOutcome());

        $row = $this->sessionRow();
        self::assertNotNull($row['revoked_at'], 'the session row is stamped');
        self::assertTrue($this->isRevoked((string) $this->claims($t2['access_token'])['jti']), 'the live access token dies now');
        self::assertSame(401, $this->refresh($t2['refresh_token'])['status'], 'the newest refresh token is dead too');
    }

    public function testAnOlderTokenFindsItsSessionByFamilyEvenRightAfterARotation(): void
    {
        $t1 = $this->signIn(bin2hex(random_bytes(16)));
        $t2 = $this->refresh($t1);
        $t3 = $this->refresh($t2['refresh_token']);
        self::assertSame(200, $t3['status']);

        // T1 is not the token the last rotation replaced, so the grace does
        // not cover it, however recent that rotation was.
        self::assertSame(401, $this->refresh($t2['refresh_token'])['status']);
        self::assertSame('grace', $this->lastReuseOutcome());
        self::assertSame(401, $this->refresh($t1)['status']);
        self::assertSame('revoked', $this->lastReuseOutcome());
        self::assertNotNull($this->sessionRow()['revoked_at']);
        self::assertSame(401, $this->refresh($t3['refresh_token'])['status']);
    }

    public function testATokenFromBeforeFamiliesIsFoundAsThePreviousRefreshToken(): void
    {
        $t1 = $this->signIn();
        self::assertArrayNotHasKey('fam', $this->claims($t1), 'minted as before this change');
        $t2 = $this->refresh($t1);
        self::assertSame(200, $t2['status']);
        $this->ageLastRotation(60);

        self::assertSame(401, $this->refresh($t1)['status']);
        self::assertSame('revoked', $this->lastReuseOutcome());
        self::assertSame(401, $this->refresh($t2['refresh_token'])['status']);
    }

    public function testOfTwoRotationsOfOneTokenOnlyTheFirstWins(): void
    {
        $t1 = $this->signIn();
        $jti = (string) $this->claims($t1)['jti'];
        $sessions = new SessionService($this->pdo);
        $expires = date('Y-m-d H:i:s', time() + 604800);

        self::assertTrue($sessions->rotate($jti, 'a-1', 'r-1', $expires, null, 'fam-x'));
        self::assertFalse($sessions->rotate($jti, 'a-2', 'r-2', $expires, null, 'fam-x'), 'the racing request gets nothing');
        $row = $this->sessionRow();
        self::assertSame('r-1', $row['refresh_jti']);
        self::assertSame($jti, $row['previous_refresh_jti']);
    }

    public function testSignOutThenReuseChangesNothingFurther(): void
    {
        $t1 = $this->signIn();
        $t2 = $this->refresh($t1);
        $this->pdo->exec("UPDATE sessions SET revoked_at = datetime('now')");
        $this->ageLastRotation(60);

        self::assertSame(401, $this->refresh($t1)['status']);
        self::assertSame('already_revoked', $this->lastReuseOutcome());
        self::assertFalse($this->isRevoked((string) $this->claims($t2['access_token'])['jti']));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** A sign-in; with $family, as one made after this change records it. */
    private function signIn(?string $family = null): string
    {
        $claims = [
            'profile_id'       => self::PROFILE_ID,
            'active_tenant_id' => self::TENANT_ID,
            'email'            => 'awss@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
        ];
        if ($family !== null) {
            $claims['fam'] = $family;
        }
        $token = LiveSession::forRefresh($this->pdo, $this->jwt, $this->jwt->create($claims, 604800, 'refresh'));
        if ($family !== null) {
            $this->pdo->prepare('UPDATE sessions SET family = ?')->execute([$family]);
        }

        return $token;
    }

    /** @return array{status: int, access_token: string, refresh_token: string} */
    private function refresh(string $refreshToken): array
    {
        $response = $this->auth->handleRefresh(new Request(
            'POST',
            '/api/v1/auth/refresh',
            ['X-Auth-Mode' => 'token', 'Content-Type' => 'application/json'],
            (string) json_encode(['refresh_token' => $refreshToken])
        ));
        $body = json_decode($response->getBody(), true);

        return [
            'status'        => $response->getStatusCode(),
            'access_token'  => is_array($body) && is_string($body['access_token'] ?? null) ? $body['access_token'] : '',
            'refresh_token' => is_array($body) && is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : '',
        ];
    }

    /** @return array<string, mixed> */
    private function claims(string $token): array
    {
        $claims = $this->jwt->parse($token);
        self::assertIsArray($claims);

        return $claims;
    }

    /** @return array<string, mixed> */
    private function sessionRow(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM sessions');
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'one sign-in is one session row');

        return $rows[0];
    }

    private function ageLastRotation(int $seconds): void
    {
        $this->pdo->prepare('UPDATE sessions SET rotated_at = ?')
            ->execute([date('Y-m-d H:i:s', time() - $seconds)]);
    }

    private function isRevoked(string $jti): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ?');
        $stmt->execute([$jti]);

        return $stmt->fetchColumn() !== false;
    }

    private function lastReuseOutcome(): ?string
    {
        $stmt = $this->pdo->query(
            "SELECT metadata FROM audit_log WHERE action = 'auth.refresh_token_reuse_detected' ORDER BY id DESC LIMIT 1"
        );
        self::assertNotFalse($stmt);
        $metadata = $stmt->fetchColumn();
        $decoded = is_string($metadata) ? json_decode($metadata, true) : null;

        return is_array($decoded) && is_string($decoded['outcome'] ?? null) ? $decoded['outcome'] : null;
    }
}
