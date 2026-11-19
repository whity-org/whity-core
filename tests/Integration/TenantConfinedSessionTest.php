<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TenantConfinement;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\EnforceTenantIsolation;

/**
 * #2249: a session minted by a workspace's own sign-in provider (tenant-trust
 * SSO) stays inside that workspace for its whole life.
 *
 * The attack: the admin of workspace A runs A's own OIDC provider, so it can
 * assert any member of A. Before the fix the session it yielded was an ordinary
 * one: switch-tenant took it into the member's other workspaces, System Tenant
 * 0 included, and the member's global account (emails, linked sign-ins,
 * devices, sign-in email and password) was open to it.
 */
final class TenantConfinedSessionTest extends TestCase
{
    private const SECRET = 'tenant-confined-session-test-secret-padded-32b';

    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const SYSTEM = 0;

    private PDO $pdo;
    private JwtParser $jwt;
    private AuthHandler $auth;
    private int $victimId;

    protected function setUp(): void
    {
        $_COOKIE = [];
        TenantContext::reset();

        $this->pdo = SchemaFromMigrations::make();
        $this->jwt = new JwtParser(self::SECRET);
        $this->auth = new AuthHandler($this->pdo, $this->jwt, new TokenValidator($this->jwt, $this->pdo));

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'System'), (1, 'Tenant A'), (2, 'Tenant B')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");

        // The victim: a platform super-admin who is also a member of A and B.
        $this->victimId = $this->seedProfile('victim@example.com');
        $this->seedMembership($this->victimId, self::SYSTEM);
        $this->seedMembership($this->victimId, self::TENANT_A);
        $this->seedMembership($this->victimId, self::TENANT_B);
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        TenantContext::reset();
    }

    public function testTenantTrustSignInIsConfinedAndCannotSwitchIntoSystemTenant(): void
    {
        $tokens = $this->tenantTrustSignIn();

        $access = $this->jwt->parse($tokens['access_token']);
        self::assertIsArray($access);
        self::assertSame(self::TENANT_A, $access['active_tenant_id']);
        self::assertSame(self::TENANT_A, $access[TenantConfinement::CLAIM]);

        foreach ([self::SYSTEM, self::TENANT_B] as $target) {
            $response = $this->auth->handleSwitchTenant($this->bearerRequest(
                'POST',
                '/api/v1/auth/switch-tenant',
                $tokens['access_token'],
                ['tenant_id' => $target],
            ));
            self::assertSame(403, $response->getStatusCode(), 'A confined session must not switch to tenant ' . $target);
        }
    }

    public function testRefreshKeepsTheConfinement(): void
    {
        $tokens = $this->tenantTrustSignIn();

        $response = $this->auth->handleRefresh(new Request(
            'POST',
            '/api/v1/auth/refresh',
            ['Content-Type' => 'application/json', 'X-Auth-Mode' => 'token'],
            (string) json_encode(['refresh_token' => $tokens['refresh_token']]),
        ));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);

        foreach (['access_token', 'refresh_token'] as $kind) {
            $claims = $this->jwt->parse((string) $body[$kind]);
            self::assertIsArray($claims);
            self::assertSame(self::TENANT_A, $claims[TenantConfinement::CLAIM], $kind . ' lost its confinement');
        }

        $switched = $this->auth->handleSwitchTenant($this->bearerRequest(
            'POST',
            '/api/v1/auth/switch-tenant',
            (string) $body['access_token'],
            ['tenant_id' => self::SYSTEM],
        ));
        self::assertSame(403, $switched->getStatusCode());
    }

    public function testMeListsOnlyTheConfinedWorkspaceAndRefusesAccountChanges(): void
    {
        $tokens = $this->tenantTrustSignIn();

        $me = $this->auth->handleMe($this->bearerRequest('GET', '/api/v1/me', $tokens['access_token']));
        self::assertSame(200, $me->getStatusCode());
        $body = json_decode($me->getBody(), true);
        self::assertIsArray($body);
        self::assertSame([self::TENANT_A], array_column($body['memberships'], 'tenant_id'));

        $update = $this->auth->handleUpdateMe($this->bearerRequest(
            'PATCH',
            '/api/v1/me',
            $tokens['access_token'],
            ['email' => 'attacker@example.com'],
        ));
        self::assertSame(403, $update->getStatusCode());

        $others = $this->auth->handleLogoutOthers($this->bearerRequest('POST', '/api/v1/me/logout-others', $tokens['access_token']));
        self::assertSame(403, $others->getStatusCode());
    }

    public function testMiddlewareRefusesGlobalAccountRoutesButAllowsWorkspaceRoutes(): void
    {
        $tokens = $this->tenantTrustSignIn();
        $middleware = new EnforceTenantIsolation($this->jwt);
        $ok = static fn (Request $r): Response => new Response(200, 'ok');

        $refused = [
            ['POST', '/api/v1/auth/switch-tenant'],
            ['GET', '/api/v1/me/emails'],
            ['POST', '/api/v1/me/emails'],
            ['GET', '/api/v1/me/identities'],
            ['POST', '/api/v1/me/ownership-offers/4/accept'],
            ['GET', '/api/v1/me/sessions'],
            ['POST', '/api/v1/devices'],
            ['POST', '/api/v1/mcp/tokens'],
            ['POST', '/api/v1/auth/2fa/setup'],
            ['POST', '/api/v1/auth/2fa/disable'],
            ['PUT', '/api/v1/me/phone'],
        ];
        foreach ($refused as [$method, $path]) {
            TenantContext::reset();
            $response = $middleware->handle($this->bearerRequest($method, $path, $tokens['access_token']), $ok);
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ' must be refused');
        }

        foreach ([['GET', '/api/v1/users'], ['GET', '/api/v1/me/notifications'], ['GET', '/api/v1/me/phone']] as [$method, $path]) {
            TenantContext::reset();
            $response = $middleware->handle($this->bearerRequest($method, $path, $tokens['access_token']), $ok);
            self::assertSame(200, $response->getStatusCode(), $method . ' ' . $path . ' must stay open');
        }
    }

    public function testConfinedClaimForAnotherWorkspaceIsRefused(): void
    {
        $forged = $this->jwt->create([
            'profile_id'           => $this->victimId,
            'active_tenant_id'     => self::SYSTEM,
            'email'                => 'victim@example.com',
            'role'                 => 'admin',
            'token_epoch'          => 0,
            TenantConfinement::CLAIM => self::TENANT_A,
        ], 900, 'access');

        $response = (new EnforceTenantIsolation($this->jwt))->handle(
            $this->bearerRequest('GET', '/api/v1/users', $forged),
            static fn (Request $r): Response => new Response(200, 'ok'),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testGlobalTrustSignInIntoOneWorkspaceIsNotConfined(): void
    {
        // A global-trust provider landing the person in one workspace (an
        // accepted invitation, a join) is the person's own sign-in: it may switch.
        $response = $this->auth->completeFederatedLogin(
            $this->victimId,
            'victim@example.com',
            $this->tokenModeRequest(),
            self::TENANT_A,
            false,
        );
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        $claims = $this->jwt->parse((string) $body['access_token']);
        self::assertIsArray($claims);
        self::assertArrayNotHasKey(TenantConfinement::CLAIM, $claims);

        $switched = $this->auth->handleSwitchTenant($this->bearerRequest(
            'POST',
            '/api/v1/auth/switch-tenant',
            (string) $body['access_token'],
            ['tenant_id' => self::TENANT_B],
        ));
        self::assertSame(200, $switched->getStatusCode());
    }

    public function testTenantTrustWithoutAWorkspaceGetsNoSession(): void
    {
        $response = $this->auth->completeFederatedLogin(
            $this->victimId,
            'victim@example.com',
            $this->tokenModeRequest(),
            null,
            true,
        );
        self::assertSame(403, $response->getStatusCode());
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function tenantTrustSignIn(): array
    {
        $response = $this->auth->completeFederatedLogin(
            $this->victimId,
            'victim@example.com',
            $this->tokenModeRequest(),
            self::TENANT_A,
            true,
        );
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);

        return ['access_token' => (string) $body['access_token'], 'refresh_token' => (string) $body['refresh_token']];
    }

    private function tokenModeRequest(): Request
    {
        return new Request('GET', '/api/v1/auth/sso/acme/callback', ['X-Auth-Mode' => 'token']);
    }

    /** @param array<string, mixed>|null $body */
    private function bearerRequest(string $method, string $path, string $token, ?array $body = null): Request
    {
        return new Request(
            $method,
            $path,
            ['Authorization' => 'Bearer ' . $token, 'X-Auth-Mode' => 'token', 'Content-Type' => 'application/json'],
            $body === null ? '' : (string) json_encode($body),
        );
    }

    private function seedProfile(string $email): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Victim', ?, false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([password_hash('a-long-password-1', PASSWORD_BCRYPT, ['cost' => 4])]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([$id, $email]);

        return $id;
    }

    private function seedMembership(int $profileId, int $tenantId): void
    {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, is_primary, created_at)
             VALUES (?, ?, 1, 'active', false, datetime('now'))"
        )->execute([$profileId, $tenantId]);
    }
}
