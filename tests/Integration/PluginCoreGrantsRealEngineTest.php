<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PluginCoreGrantsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\HostCorePermissionGrants;
use Whity\Core\Plugin\PluginCoreGrants;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\PluginLoader;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Contribute\CorePermissionRequestsInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRolesInterface;

/**
 * #2256: a plugin asks for core permissions for its roles; nothing is granted
 * until an operator approves, per role and per workspace, and an approval
 * grants exactly what was asked.
 *
 * The plugin here is a leave plugin (KeyHub-like): its `hr` role asks for
 * `calendar:write` and `calendar:manage`, its `technician` for
 * `calendar:write`, and it slips in `acme_leave:read` (its own, not core) and
 * `users:write` for `hr` it does not get to ask about elsewhere.
 */
final class PluginCoreGrantsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const OPERATOR = 50;

    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
        $this->pdo->exec("INSERT OR IGNORE INTO permissions (name, created_at) VALUES ('acme_leave:read', NOW())");
        SchemaFromMigrations::syncSequences($this->pdo);

        // The plugin's roles exist in both workspaces, as the seeder makes them.
        $seeder = new PluginRoleSeeder($this->pdo);
        $seeder->seed($this->plugin(), self::TENANT);
        $seeder->seed($this->plugin(), self::OTHER_TENANT);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    public function testOnlyCorePermissionsAreAskedForAndNothingIsGrantedOnItsOwn(): void
    {
        self::assertSame(
            ['hr' => ['calendar:manage', 'calendar:write', 'users:write'], 'technician' => ['calendar:write']],
            PluginCoreGrants::requests($this->plugin()),
            'a plugin\'s own permission is not a core request'
        );

        $grants = new PluginCoreGrants($this->pdo);
        self::assertSame([], $this->rolePermissions('hr', self::TENANT, core: true), 'seeding grants no core permission');
        self::assertFalse($grants->isGranted('AcmeLeave', 'hr', 'calendar:write', self::TENANT));
        self::assertSame(
            ['calendar:manage', 'calendar:write', 'users:write'],
            $this->host()->missing('AcmeLeave', 'hr', self::TENANT),
            'the plugin can tell what is still waiting'
        );
    }

    public function testAnApprovalGrantsExactlyWhatWasAskedInThatWorkspaceOnly(): void
    {
        $grants = new PluginCoreGrants($this->pdo);

        self::assertSame(
            ['calendar:manage', 'calendar:write'],
            $grants->approve($this->plugin(), 'hr', self::TENANT, null, ['calendar:write', 'calendar:manage'])
        );
        self::assertSame(['calendar:manage', 'calendar:write'], $this->rolePermissions('hr', self::TENANT, core: true));
        self::assertSame([], $this->rolePermissions('hr', self::OTHER_TENANT, core: true), 'per workspace');
        self::assertSame(['users:write'], $this->host()->missing('AcmeLeave', 'hr', self::TENANT));

        // Approving again adds nothing and repeats nothing.
        self::assertSame([], $grants->approve($this->plugin(), 'hr', self::TENANT, null, ['calendar:write']));

        // Something the plugin did not ask for is refused, and nothing is written.
        try {
            $grants->approve($this->plugin(), 'technician', self::TENANT, null, ['calendar:manage']);
            self::fail('a permission the plugin did not ask for must be refused');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $this->rolePermissions('technician', self::TENANT, core: true));
    }

    public function testRevokingTakesBackOnlyWhatTheApprovalAdded(): void
    {
        // An admin had already given hr calendar:write themselves.
        $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT ?, id, NOW() FROM permissions WHERE name = ?'
        )->execute([$this->roleId('hr', self::TENANT), 'calendar:write']);

        $grants = new PluginCoreGrants($this->pdo);
        $grants->approve($this->plugin(), 'hr', self::TENANT, null, ['calendar:write', 'calendar:manage']);
        self::assertSame(['calendar:manage', 'calendar:write'], $grants->revoke('AcmeLeave', 'hr', self::TENANT));

        self::assertSame(['calendar:write'], $this->rolePermissions('hr', self::TENANT, core: true), 'the admin\'s own grant stays');
        self::assertFalse($grants->isGranted('AcmeLeave', 'hr', 'calendar:manage', self::TENANT));
    }

    public function testTurningThePluginOffOrRemovingItTakesTheApprovalsAway(): void
    {
        $grants = new PluginCoreGrants($this->pdo);
        $grants->approve($this->plugin(), 'hr', self::TENANT, null, ['calendar:write']);
        $grants->approve($this->plugin(), 'hr', self::OTHER_TENANT, null, ['calendar:write']);

        (new CodePluginActivation($this->pdo))->setEnabled(self::TENANT, 'AcmeLeave', false);
        self::assertSame([], $this->rolePermissions('hr', self::TENANT, core: true));
        self::assertFalse($grants->isGranted('AcmeLeave', 'hr', 'calendar:write', self::TENANT));
        self::assertSame(['calendar:write'], $this->rolePermissions('hr', self::OTHER_TENANT, core: true), 'only that workspace');

        (new PluginRoleSeeder($this->pdo))->removeGrants($this->plugin(), self::OTHER_TENANT);
        self::assertSame([], $this->rolePermissions('hr', self::OTHER_TENANT, core: true));
        $left = $this->pdo->prepare('SELECT COUNT(*) FROM plugin_core_grants WHERE plugin_key = ?');
        $left->execute(['AcmeLeave']);
        self::assertSame(0, (int) $left->fetchColumn());
    }

    public function testTheApiIsForTheOperatorAndApprovesOnlyWhatTheOperatorHolds(): void
    {
        $handler = $this->api();
        $params = ['name' => 'AcmeLeave', 'role' => 'hr'];

        // A workspace admin is not the operator.
        TenantContext::setTenantId(self::TENANT);
        self::assertSame(403, $handler->approve($this->req(['tenant_id' => self::TENANT]), $params)->getStatusCode());

        // The operator, holding calendar:* but not users:write.
        TenantContext::reset();
        TenantContext::setTenantId(0);
        $this->operatorHolds(['calendar:write', 'calendar:manage']);

        $refused = $handler->approve($this->req(['tenant_id' => self::TENANT]), $params);
        self::assertSame(403, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertSame(['users:write'], json_decode((string) $refused->getBody(), true)['details']['permissions'] ?? null);
        self::assertSame([], $this->rolePermissions('hr', self::TENANT, core: true), 'a refused approval writes nothing');

        // Refusals are plain words with a reason, never exception text (WC-186).
        $notAsked = $handler->approve($this->req(['tenant_id' => self::TENANT, 'permissions' => ['audit:read']]), $params);
        self::assertSame(422, $notAsked->getStatusCode());
        self::assertSame(['audit:read'], json_decode((string) $notAsked->getBody(), true)['details']['permissions'] ?? null);
        $otherRole = $handler->approve($this->req(['tenant_id' => self::TENANT]), ['name' => 'AcmeLeave', 'role' => 'nobody']);
        self::assertSame('not_requested', json_decode((string) $otherRole->getBody(), true)['details']['reason'] ?? null);

        $approved = $handler->approve($this->req(['tenant_id' => self::TENANT, 'permissions' => ['calendar:write', 'calendar:manage']]), $params);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame(['calendar:manage', 'calendar:write'], json_decode((string) $approved->getBody(), true)['data']['granted']);

        $list = json_decode((string) $handler->list($this->req([], 'GET', '/api/plugins/AcmeLeave/core-grants?tenant_id=1'), ['name' => 'AcmeLeave'])->getBody(), true)['data'];
        self::assertSame(['tenant_id' => 1, 'workspace' => 'Example Bakery', 'role' => 'hr', 'requested' => ['calendar:manage', 'calendar:write', 'users:write'], 'granted' => ['calendar:manage', 'calendar:write']], $list['grants'][0]);

        $revoked = $handler->revoke($this->req(['tenant_id' => self::TENANT]), $params);
        self::assertSame(200, $revoked->getStatusCode());
        self::assertSame([], $this->rolePermissions('hr', self::TENANT, core: true));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function api(): PluginCoreGrantsApiHandler
    {
        $loader = new PluginLoader(sys_get_temp_dir() . '/whity_no_plugins_' . uniqid(), new Router(''), new PermissionRegistry(), new HookManager());
        (new \ReflectionProperty(PluginLoader::class, 'plugins'))->setValue($loader, [$this->plugin()]);
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $pdo = $this->pdo;

        return new PluginCoreGrantsApiHandler($this->pdo, $loader, new RoleChecker(Database::withFactory(static fn (): PDO => $pdo), $registry));
    }

    private function host(): HostCorePermissionGrants
    {
        $loader = new PluginLoader(sys_get_temp_dir() . '/whity_no_plugins_' . uniqid(), new Router(''), new PermissionRegistry(), new HookManager());
        (new \ReflectionProperty(PluginLoader::class, 'plugins'))->setValue($loader, [$this->plugin()]);

        return new HostCorePermissionGrants(new PluginCoreGrants($this->pdo), $loader);
    }

    /** @param list<string> $permissions */
    private function operatorHolds(array $permissions): void
    {
        $this->pdo->exec("INSERT INTO roles (name, description, tenant_id, created_at) VALUES ('operator', '', 0, NOW())");
        $roleId = (int) $this->pdo->lastInsertId();
        foreach ($permissions as $permission) {
            $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, created_at) SELECT ?, id, NOW() FROM permissions WHERE name = ?')
                ->execute([$roleId, $permission]);
        }
        $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (" . self::OPERATOR . ", 'Amro Saleh', 'x', false, 0, 0, NOW(), NOW())");
        $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (" . self::OPERATOR . ", 0, {$roleId}, 'active', NOW())");
        RoleChecker::clearCache();
    }

    /** @param array<string, mixed> $body */
    private function req(array $body, string $method = 'POST', string $path = '/x'): Request
    {
        $request = new Request($method, $path, ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => self::OPERATOR];

        return $request;
    }

    private function roleId(string $name, int $tenantId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ? AND tenant_id = ?');
        $stmt->execute([$name, $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function rolePermissions(string $role, int $tenantId, bool $core): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ? ORDER BY p.name'
        );
        $stmt->execute([$this->roleId($role, $tenantId)]);
        $names = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values($core ? array_filter($names, static fn (string $n): bool => !str_starts_with($n, 'acme_leave')) : $names);
    }

    private function plugin(): PluginInterface
    {
        return new class implements PluginInterface, PluginRolesInterface, CorePermissionRequestsInterface {
            public function getName(): string
            {
                return 'AcmeLeave';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getRoutes(): array
            {
                return [];
            }

            public function getPermissions(): array
            {
                return ['acme_leave:read'];
            }

            public function getHooks(): array
            {
                return [];
            }

            public function getMigrations(): array
            {
                return [];
            }

            public function getRoles(): array
            {
                return ['hr' => ['description' => 'HR'], 'technician' => ['description' => 'Technician']];
            }

            public function getRolePermissions(): array
            {
                return ['hr' => ['acme_leave:read'], 'technician' => ['acme_leave:read']];
            }

            public function getCorePermissionRequests(): array
            {
                return [
                    'hr' => ['calendar:write', 'calendar:manage', 'users:write', 'acme_leave:read', 'calendar:write'],
                    'technician' => ['calendar:write'],
                ];
            }
        };
    }
}
