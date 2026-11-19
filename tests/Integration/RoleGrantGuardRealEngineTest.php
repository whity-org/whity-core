<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\OusApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Api\TenantEmailDomainApiHandler;
use Whity\Api\UsersApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\DnsTxtResolver;
use Whity\Core\Identity\DomainOwnershipVerifier;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\RoleGrantGuard;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * #2235, #2236, #2241: a caller only grants what they hold.
 *
 * The person acting here is a MANAGER: they may manage people, OUs, roles and
 * email domains, but they hold nothing else. Before the shared guard each of
 * those permissions was a way to become admin. Each test pins one grant path:
 * the refusal, nothing written, and the same path still working for a role the
 * manager does hold.
 */
final class RoleGrantGuardRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** What the manager holds, and nothing more. */
    private const MANAGER_PERMISSIONS = [
        'users:read', 'users:write', 'ous:read', 'ous:write', 'ous:assign',
        'roles:read', 'roles:write', 'roles:manage', 'permissions:read', 'email_domains:manage',
    ];

    private PDO $pdo;
    private int $adminRole;
    private int $managerRole;
    private int $helperRole;
    private int $manager;
    private int $member;
    private int $ou;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT . ", 'acme', 'acme')");

        $this->adminRole = $this->role('admin', null);
        $this->grant($this->adminRole, array_merge(self::MANAGER_PERMISSIONS, ['settings:write', 'audit:read']));
        $this->managerRole = $this->role('manager', self::TENANT);
        $this->grant($this->managerRole, self::MANAGER_PERMISSIONS);
        $this->helperRole = $this->role('helper', self::TENANT);
        $this->grant($this->helperRole, ['users:read']);

        $this->manager = $this->profile('manager', $this->managerRole);
        $this->member = $this->profile('member', $this->helperRole);

        $this->pdo->exec("INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at)
            VALUES (" . self::TENANT . ", NULL, 'Finance', 'finance', NOW())");
        $this->ou = (int) $this->pdo->lastInsertId();

        SchemaFromMigrations::syncSequences($this->pdo);
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ── #2235: people ────────────────────────────────────────────────────────

    public function testUsersUpdateRefusesRaisingYourOwnRole(): void
    {
        $res = $this->users()->update($this->req(['role_id' => $this->adminRole]), ['id' => (string) $this->manager]);

        self::assertSame(403, $res->getStatusCode(), $res->getBody());
        self::assertSame('exceeds_your_access', $this->decode($res)['details']['reason'] ?? null);
        self::assertSame($this->managerRole, $this->membershipRole($this->manager));
    }

    public function testUsersUpdateRefusesMakingSomebodyElseAdminButAllowsAHeldRole(): void
    {
        $refused = $this->users()->update($this->req(['role_id' => $this->adminRole]), ['id' => (string) $this->member]);
        self::assertSame(403, $refused->getStatusCode(), $refused->getBody());
        self::assertSame($this->helperRole, $this->membershipRole($this->member));

        $allowed = $this->users()->update($this->req(['role_id' => $this->managerRole]), ['id' => (string) $this->member]);
        self::assertSame(200, $allowed->getStatusCode(), $allowed->getBody());
        self::assertSame($this->managerRole, $this->membershipRole($this->member));
    }

    public function testAddingASecondRoleIsAGrantToo(): void
    {
        $res = $this->users()->addMembership($this->req(['role_id' => $this->adminRole]), ['id' => (string) $this->manager]);

        self::assertSame(403, $res->getStatusCode(), $res->getBody());
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM memberships WHERE profile_id = ' . $this->manager));
    }

    // ── #2235: OUs ───────────────────────────────────────────────────────────

    public function testAttachingAdminToAnOuIsRefusedAndAHeldRoleIsNot(): void
    {
        $refused = $this->ous()->assignRole($this->req(['role_id' => $this->adminRole]), ['id' => (string) $this->ou]);
        self::assertSame(403, $refused->getStatusCode(), $refused->getBody());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM ou_role_assignments'));

        $allowed = $this->ous()->assignRole($this->req(['role_id' => $this->helperRole]), ['id' => (string) $this->ou]);
        self::assertSame(201, $allowed->getStatusCode(), $allowed->getBody());
    }

    // ── #2236: roles ─────────────────────────────────────────────────────────

    public function testCreatingARoleWithPermissionsYouDoNotHoldIsRefused(): void
    {
        $before = $this->scalar('SELECT COUNT(*) FROM roles');

        $refused = $this->roles()->create($this->req(['name' => 'sneaky', 'permissions' => ['users:read', 'settings:write']]));
        self::assertSame(403, $refused->getStatusCode(), $refused->getBody());
        self::assertSame(['settings:write'], $this->decode($refused)['details']['permissions'] ?? null);
        self::assertSame($before, $this->scalar('SELECT COUNT(*) FROM roles'));

        $allowed = $this->roles()->create($this->req(['name' => 'readers', 'permissions' => ['users:read']]));
        self::assertSame(201, $allowed->getStatusCode(), $allowed->getBody());
    }

    public function testAddingPermissionsYouDoNotHoldToYourOwnRoleIsRefused(): void
    {
        $res = $this->roles()->grantPermissions($this->req(['permissions' => ['settings:write']]), ['id' => (string) $this->managerRole]);

        self::assertSame(403, $res->getStatusCode(), $res->getBody());
        self::assertNotContains('settings:write', $this->rolePermissions($this->managerRole));
    }

    public function testReplacingARolesPermissionsComparesOnlyWhatIsAdded(): void
    {
        // The helper role carries something the manager lacks. Taking it away is
        // fine; putting it back is a grant.
        $this->grant($this->helperRole, ['audit:read']);

        $removed = $this->roles()->update($this->req(['permissions' => ['users:read']]), ['id' => (string) $this->helperRole]);
        self::assertSame(200, $removed->getStatusCode(), $removed->getBody());
        self::assertSame(['users:read'], $this->rolePermissions($this->helperRole));

        $readded = $this->roles()->update(
            $this->req(['name' => 'renamed', 'permissions' => ['users:read', 'audit:read']]),
            ['id' => (string) $this->helperRole]
        );
        self::assertSame(403, $readded->getStatusCode(), $readded->getBody());
        self::assertSame(['users:read'], $this->rolePermissions($this->helperRole));
        $name = $this->pdo->prepare('SELECT name FROM roles WHERE id = ?');
        $name->execute([$this->helperRole]);
        self::assertSame('helper', (string) $name->fetchColumn(), 'A refused replace must not rename the role either');
    }

    // ── #2241: email domains ─────────────────────────────────────────────────

    public function testEmailDomainArrivalsCannotBeMadeAdmin(): void
    {
        $refused = $this->domains()->create($this->req(['domain' => 'acme.test', 'default_role_id' => $this->adminRole]));
        self::assertSame(403, $refused->getStatusCode(), $refused->getBody());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM tenant_email_domains'));

        $allowed = $this->domains()->create($this->req(['domain' => 'acme.test', 'default_role_id' => $this->helperRole]));
        self::assertSame(201, $allowed->getStatusCode(), $allowed->getBody());
        $id = (int) $this->decode($allowed)['data']['id'];

        $raised = $this->domains()->update($this->req(['default_role_id' => $this->adminRole]), ['id' => (string) $id]);
        self::assertSame(403, $raised->getStatusCode(), $raised->getBody());
    }

    public function testSwitchingArrivalsOnForAnAdminDomainIsRefusedButOffIsNot(): void
    {
        // Set up by a real admin earlier, with arrivals off.
        $this->pdo->prepare(
            'INSERT INTO tenant_email_domains (tenant_id, domain, default_role_id, auto_provision, created_at)
             VALUES (?, ?, ?, false, NOW())'
        )->execute([self::TENANT, 'acme.test', $this->adminRole]);
        $id = (int) $this->pdo->lastInsertId();

        $on = $this->domains()->update($this->req(['auto_provision' => true]), ['id' => (string) $id]);
        self::assertSame(403, $on->getStatusCode(), $on->getBody());

        $off = $this->domains()->update($this->req(['auto_provision' => false]), ['id' => (string) $id]);
        self::assertSame(200, $off->getStatusCode(), $off->getBody());
    }

    /**
     * The guard is an optional constructor argument (tests build these handlers
     * bare), so the one place that MUST pass it is pinned here: the worker
     * entry point builds every one of these handlers with it.
     */
    public function testTheWorkerWiresTheGuardIntoEveryGrantHandler(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../public/index.php');

        foreach (['UsersApiHandler', 'OusApiHandler', 'RolesApiHandler', 'TenantEmailDomainApiHandler'] as $handler) {
            $start = strpos($source, 'new ' . $handler . '(');
            self::assertNotFalse($start, "public/index.php no longer builds {$handler}");
            $construction = substr($source, $start, (int) strpos($source, ';', $start) - $start);
            self::assertStringContainsString(
                '$roleGrantGuard',
                $construction,
                "{$handler} must be built with \$roleGrantGuard in public/index.php"
            );
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function guard(): RoleGrantGuard
    {
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $pdo = $this->pdo;

        return new RoleGrantGuard(new RoleChecker(Database::withFactory(static fn (): PDO => $pdo), $registry));
    }

    private function users(): UsersApiHandler
    {
        return new UsersApiHandler($this->pdo, new HookManager(), null, $this->guard());
    }

    private function ous(): OusApiHandler
    {
        return new OusApiHandler($this->pdo, new HookManager(), $this->guard());
    }

    private function roles(): RolesApiHandler
    {
        return new RolesApiHandler($this->pdo, new HookManager(), null, null, null, $this->guard());
    }

    private function domains(): TenantEmailDomainApiHandler
    {
        $resolver = new class implements DnsTxtResolver {
            public function txtRecords(string $host): array
            {
                return [];
            }
        };

        return new TenantEmailDomainApiHandler($this->pdo, new DomainOwnershipVerifier($resolver), $this->guard());
    }

    /** @param array<string, mixed> $body */
    private function req(array $body): Request
    {
        $request = new Request('POST', '/x', ['Content-Type' => 'application/json'], (string) json_encode($body));
        $request->user = (object) ['profile_id' => $this->manager, 'active_tenant_id' => self::TENANT];

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function role(string $name, ?int $tenantId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ? AND tenant_id IS NULL');
        $stmt->execute([$name]);
        $existing = $tenantId === null ? $stmt->fetchColumn() : false;
        if ($existing !== false) {
            return (int) $existing;
        }
        $this->pdo->prepare('INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$name, $name, $tenantId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<string> $permissions */
    private function grant(int $roleId, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')->execute([$permission]);
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at)
                 SELECT ?, id, NOW() FROM permissions WHERE name = ?'
            )->execute([$roleId, $permission]);
        }
        RoleChecker::clearCache();
    }

    private function profile(string $name, int $roleId): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', false, 0, 0, NOW(), NOW())"
        )->execute([$name]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES (?, ?, true, true, NOW())'
        )->execute([$id, $name . '@acme.test']);
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, is_primary, created_at)
             VALUES (?, ?, ?, 'active', true, NOW())"
        )->execute([$id, self::TENANT, $roleId]);

        return $id;
    }

    private function membershipRole(int $profileId): int
    {
        return $this->scalar('SELECT role_id FROM memberships WHERE profile_id = ' . $profileId . ' AND tenant_id = ' . self::TENANT);
    }

    /** @return list<string> */
    private function rolePermissions(int $roleId): array
    {
        $stmt = $this->pdo->query(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ' . $roleId . ' ORDER BY p.name'
        );

        return array_map('strval', $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []);
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);

        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }
}
