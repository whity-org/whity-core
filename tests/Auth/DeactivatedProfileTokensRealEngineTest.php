<?php

declare(strict_types=1);

namespace Tests\Auth;

use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\ActiveTenantMembershipGuard;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Auth\TotpService;
use Whity\Core\Request;
use Whity\Mcp\Auth\McpTokenService;

/**
 * #2227, #2261: a deactivated profile (`profiles.status = 'inactive'`) keeps
 * no access through any token it already holds, and gets no new session from
 * any path. Deactivation leaves memberships alone, so the checks must read the
 * profile itself.
 *
 * The profile is flipped to inactive WITHOUT an epoch bump here on purpose:
 * that is what proves the status check stands on its own (the epoch bump on
 * deactivation is pinned in UsersApiHandlerRealEngineTest).
 *
 * Real engine (SQLite by default, PostgreSQL with PHPUNIT_PG_DSN).
 */
final class DeactivatedProfileTokensRealEngineTest extends TestCase
{
    private const SECRET   = 'deactivated-profile-tokens-test-secret-32b';
    private const TOTP_KEY = 'deactivated-profile-totp-test-key-32-chars';
    private const PROFILE  = 91001;
    private const TENANT   = 1;
    private const EMAIL    = 'leaver@example.test';

    private PDO $pdo;
    private JwtParser $jwt;
    private TokenValidator $validator;
    /** @var non-empty-string */
    private string $plainSecret;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();
        $this->jwt = new JwtParser(self::SECRET);
        $this->validator = new TokenValidator($this->jwt, $this->pdo);

        $totp = new TotpService(self::TOTP_KEY);
        $plain = $totp->generateSecret();
        if ($plain === '') {
            self::fail('no secret generated');
        }
        $this->plainSecret = $plain;
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'NOW()' : "datetime('now')";

        $this->pdo->exec("INSERT INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', {$now}) ON CONFLICT (id) DO NOTHING");
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_secret,
                two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (?, 'leaver', 'x', true, ?, 0, 0, 'active', {$now}, {$now})"
        )->execute([self::PROFILE, $totp->encryptSecret($this->plainSecret)]);
        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, {$now})"
        )->execute([self::PROFILE, self::EMAIL]);
        $roleStmt = $this->pdo->query("SELECT id FROM roles WHERE name = 'user'");
        self::assertNotFalse($roleStmt);
        $roleId = (int) $roleStmt->fetchColumn();
        foreach ([0, self::TENANT] as $tenant) {
            $this->pdo->prepare(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
                 VALUES (?, ?, ?, 'active', {$now})"
            )->execute([self::PROFILE, $tenant, $roleId]);
        }
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    public function testTheGuardRefusesADeactivatedProfileInAWorkspaceAndInTheSystemTenant(): void
    {
        $guard = new ActiveTenantMembershipGuard($this->pdo);
        $inWorkspace = ['profile_id' => self::PROFILE, 'active_tenant_id' => self::TENANT];
        $inSystem = ['profile_id' => self::PROFILE, 'active_tenant_id' => 0];
        self::assertTrue($guard->allows($inWorkspace));
        self::assertTrue($guard->allows($inSystem));

        $this->deactivate();

        self::assertFalse($guard->allows($inWorkspace));
        self::assertFalse($guard->allows($inSystem));
    }

    public function testAnAccessTokenIssuedBeforeDeactivationStopsWorking(): void
    {
        $access = $this->jwt->create($this->claims(), 900, 'access');
        self::assertNotNull($this->validator->validateAccessTokenFromBearer($access));

        $this->deactivate();

        self::assertNull($this->validator->validateAccessTokenFromBearer($access));
    }

    public function testANinetyDayMcpTokenStopsWorking(): void
    {
        $token = (new McpTokenService($this->pdo, $this->jwt))->issue(self::PROFILE, self::TENANT, 'laptop', ['tools:read']);
        self::assertNotNull($this->validator->validateMcpToken($token));

        $this->deactivate();

        self::assertNull($this->validator->validateMcpToken($token));
    }

    public function testTheTwoStepSignInMintsNoSessionForADeactivatedProfile(): void
    {
        // The temp token was minted while the profile was active; it is
        // deactivated during the 5-minute window.
        $_COOKIE['temp_auth_token'] = $this->jwt->create([
            'profile_id'       => self::PROFILE,
            'active_tenant_id' => self::TENANT,
            'email'            => self::EMAIL,
        ], 300, 'temp');
        $this->deactivate();

        $handler = new AuthHandler($this->pdo, $this->jwt, $this->validator, null, new TotpService(self::TOTP_KEY));
        $response = $handler->handle2fa(new Request(
            'POST',
            '/api/v1/login/2fa',
            ['X-Auth-Mode' => 'token'],
            (string) json_encode(['code' => TOTP::create($this->plainSecret)->now()])
        ));

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('Invalid credentials', $body['error'] ?? null);
        self::assertArrayNotHasKey('access_token', $body);
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        return [
            'profile_id'       => self::PROFILE,
            'active_tenant_id' => self::TENANT,
            'email'            => self::EMAIL,
            'role'             => 'user',
            'token_epoch'      => 0,
        ];
    }

    private function deactivate(): void
    {
        $this->pdo->prepare("UPDATE profiles SET status = 'inactive' WHERE id = ?")->execute([self::PROFILE]);
    }
}
