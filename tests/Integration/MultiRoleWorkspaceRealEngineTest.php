<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Database\Database;
use Whity\Core\RBAC\PermissionRegistry;

/**
 * A person with several roles in ONE workspace sees that workspace once.
 *
 * The data model keeps one membership row per role (#712 §1: one primary row,
 * the rest secondary). The workspace list used to return one entry per ROW, so
 * sign-in, /me and the switcher offered the same workspace once per role, and a
 * person with two roles in their only workspace was sent to a picker instead of
 * being signed in. Inside the workspace the person holds the union of all the
 * roles, and a staff row outranks a customer row in the same workspace.
 *
 * Real engine: the schema is built from the production migrations
 * ({@see SchemaFromMigrations}).
 */
final class MultiRoleWorkspaceRealEngineTest extends TestCase
{
    private const SECRET = 'multi-role-workspace-test-secret-padded-to-32-bytes';
    private const PASSWORD = 'testpassword123';

    private const BAKERY = 1;
    private const CAFE = 2;

    private PDO $pdo;
    private JwtParser $jwtParser;
    private AuthHandler $handler;

    private int $bakerRoleId;
    private int $cashierRoleId;

    protected function setUp(): void
    {
        $_COOKIE = [];
        RoleChecker::clearCache();

        $this->pdo = SchemaFromMigrations::make();
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->handler = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            new TokenValidator($this->jwtParser, $this->pdo),
        );

        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Example Bakery'), (2, 'Example Cafe')");
        $this->bakerRoleId = $this->seedRole('baker', 'forms:read');
        $this->cashierRoleId = $this->seedRole('cashier', 'contacts:read');
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        RoleChecker::clearCache();
    }

    // ── sign-in ──────────────────────────────────────────────────────────────

    public function testTwoRolesInTheOnlyWorkspaceSignInDirectly(): void
    {
        $profileId = $this->seedProfile('amro@example.com');
        $this->seedMembership($profileId, self::BAKERY, $this->bakerRoleId, true);
        $this->seedMembership($profileId, self::BAKERY, $this->cashierRoleId, false);

        $response = $this->login('amro@example.com');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertArrayNotHasKey('requires_tenant_selection', $body, 'One workspace is not a choice.');
        self::assertSame(self::BAKERY, $body['user']['tenant_id']);
        self::assertSame('baker', $body['user']['role'], 'The primary role speaks for the session.');
    }

    public function testThePickerListsEachWorkspaceOnceWithEveryRole(): void
    {
        $profileId = $this->seedProfile('awss@example.com');
        $this->seedMembership($profileId, self::BAKERY, $this->bakerRoleId, true);
        $this->seedMembership($profileId, self::BAKERY, $this->cashierRoleId, false);
        $this->seedMembership($profileId, self::CAFE, $this->cashierRoleId, true);

        $response = $this->login('awss@example.com');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertTrue($body['requires_tenant_selection'] ?? false);
        self::assertSame([self::BAKERY, self::CAFE], array_column($body['memberships'], 'tenant_id'));
        self::assertSame('baker', $body['memberships'][0]['role']);
        self::assertSame(['baker', 'cashier'], $body['memberships'][0]['roles']);
        self::assertSame(['cashier'], $body['memberships'][1]['roles']);
    }

    // ── /me and switch-tenant ────────────────────────────────────────────────

    public function testMeListsTheWorkspaceOnceAndSwitchingIntoItWorks(): void
    {
        $profileId = $this->seedProfile('ahmad@example.com');
        $this->seedMembership($profileId, self::BAKERY, $this->bakerRoleId, true);
        $this->seedMembership($profileId, self::BAKERY, $this->cashierRoleId, false);
        $this->seedMembership($profileId, self::CAFE, $this->bakerRoleId, true);

        $this->authenticateAs($profileId, self::CAFE, 'ahmad@example.com');
        $me = json_decode($this->handler->handleMe(new Request('GET', '/api/me', []))->getBody(), true);

        self::assertSame([self::BAKERY, self::CAFE], array_column($me['memberships'], 'tenant_id'));
        self::assertSame(['baker', 'cashier'], $me['memberships'][0]['roles']);

        $switch = $this->handler->handleSwitchTenant(new Request(
            'POST',
            '/api/auth/switch-tenant',
            ['Content-Type' => 'application/json'],
            (string) json_encode(['tenant_id' => self::BAKERY])
        ));
        self::assertSame(200, $switch->getStatusCode());
        self::assertSame(self::BAKERY, json_decode($switch->getBody(), true)['user']['tenant_id']);
    }

    public function testASuspendedSecondRoleIsNotListed(): void
    {
        $profileId = $this->seedProfile('ibrahim@example.com');
        $this->seedMembership($profileId, self::BAKERY, $this->bakerRoleId, true);
        $this->seedMembership($profileId, self::BAKERY, $this->cashierRoleId, false, 'suspended');

        $this->authenticateAs($profileId, self::BAKERY, 'ibrahim@example.com');
        $me = json_decode($this->handler->handleMe(new Request('GET', '/api/me', []))->getBody(), true);

        self::assertCount(1, $me['memberships']);
        self::assertSame(['baker'], $me['memberships'][0]['roles']);
    }

    // ── permissions: the union of the roles ──────────────────────────────────

    public function testPermissionsAreTheUnionOfEveryRoleInTheWorkspace(): void
    {
        $profileId = $this->seedProfile('emad@example.com');
        $this->seedMembership($profileId, self::BAKERY, $this->bakerRoleId, true);
        $this->seedMembership($profileId, self::BAKERY, $this->cashierRoleId, false);

        $checker = $this->roleChecker();

        self::assertTrue($checker->hasPermissionForProfile($profileId, 'forms:read', self::BAKERY));
        self::assertTrue($checker->hasPermissionForProfile($profileId, 'contacts:read', self::BAKERY));
        self::assertFalse($checker->hasPermissionForProfile($profileId, 'contacts:read', self::CAFE));
    }

    // ── customer and member in one workspace ─────────────────────────────────

    public function testAMemberRowOutranksACustomerRowInTheSameWorkspace(): void
    {
        $adminRoleId = $this->roleId('admin');
        $this->grant($adminRoleId, 'users:delete');

        $profileId = $this->seedProfile('both@example.com');
        $this->seedMembership($profileId, self::BAKERY, $this->bakerRoleId, true, 'active', 'customer');
        $this->seedMembership($profileId, self::BAKERY, $adminRoleId, false, 'active', 'member');

        $this->authenticateAs($profileId, self::BAKERY, 'both@example.com');
        $me = json_decode($this->handler->handleMe(new Request('GET', '/api/me', []))->getBody(), true);

        self::assertCount(1, $me['memberships']);
        self::assertSame('member', $me['memberships'][0]['kind'], 'Any staff row makes the person staff here.');

        $checker = $this->roleChecker();
        self::assertFalse($checker->isCustomerOnly($profileId, self::BAKERY));
        self::assertTrue($checker->hasPermissionForProfile($profileId, 'users:delete', self::BAKERY));
    }

    public function testACustomerOnlyWorkspaceKeepsTheCustomerCap(): void
    {
        $adminRoleId = $this->roleId('admin');
        $this->grant($adminRoleId, 'users:delete');

        $profileId = $this->seedProfile('customer@example.com');
        $this->seedMembership($profileId, self::BAKERY, $this->bakerRoleId, true, 'active', 'customer');
        $this->seedMembership($profileId, self::BAKERY, $adminRoleId, false, 'active', 'customer');

        $this->authenticateAs($profileId, self::BAKERY, 'customer@example.com');
        $me = json_decode($this->handler->handleMe(new Request('GET', '/api/me', []))->getBody(), true);

        self::assertCount(1, $me['memberships']);
        self::assertSame('customer', $me['memberships'][0]['kind']);

        $checker = $this->roleChecker();
        self::assertTrue($checker->isCustomerOnly($profileId, self::BAKERY));
        self::assertTrue($checker->hasPermissionForProfile($profileId, 'forms:read', self::BAKERY));
        self::assertFalse(
            $checker->hasPermissionForProfile($profileId, 'users:delete', self::BAKERY),
            'Two customer roles are still a customer: privileged permissions stay dropped.'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function login(string $email): \Whity\Core\Response
    {
        return $this->handler->handle(new Request('POST', '/api/login', [], (string) json_encode([
            'email'    => $email,
            'password' => self::PASSWORD,
        ])));
    }

    private function authenticateAs(int $profileId, int $tenantId, string $email): void
    {
        $_COOKIE['access_token'] = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'email'            => $email,
            'role'             => '',
            'token_epoch'      => 0,
        ], 900, 'access');
    }

    private function roleChecker(): RoleChecker
    {
        $pdo = $this->pdo;
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return new RoleChecker($db, new PermissionRegistry());
    }

    private function roleId(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function seedRole(string $name, string $permission): int
    {
        $this->pdo->prepare('INSERT INTO roles (name, tenant_id) VALUES (?, NULL)')->execute([$name]);
        $roleId = (int) $this->pdo->lastInsertId();
        $this->grant($roleId, $permission);

        return $roleId;
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare(
            "INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, NULL, datetime('now'))"
        )->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            "INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, datetime('now'))"
        )->execute([$roleId, $permissionId]);
    }

    private function seedProfile(string $email): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute([$email, password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([$profileId, $email]);

        return $profileId;
    }

    private function seedMembership(
        int $profileId,
        int $tenantId,
        int $roleId,
        bool $primary,
        string $status = 'active',
        string $kind = 'member'
    ): void {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, is_primary, status, kind, created_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime('now'))"
        )->execute([$profileId, $tenantId, $roleId, $primary ? 1 : 0, $status, $kind]);
    }
}
