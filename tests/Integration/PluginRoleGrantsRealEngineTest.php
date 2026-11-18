<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRolesInterface;

/**
 * Plugin role seeding stays within the plugin's own grants (GHSA-r4hx-wj99-jw8m,
 * migration 221), on the real schema: SQLite by default, PostgreSQL when
 * PHPUNIT_PG_DSN is set.
 *
 *  - a plugin grants only permissions it declares, never core ones, and only to
 *    roles it created or the operator approved for it;
 *  - uninstall removes only the grants the plugin made, so core grants stay;
 *  - a plugin that keeps to its own roles and permissions works as before.
 */
final class PluginRoleGrantsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OWN = 'acme_stock:read';
    private const OTHER = 'acme_stock:write';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, created_at) VALUES (1, 'Example Bakery', CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($this->pdo);
        foreach ([self::OWN, self::OTHER] as $slug) {
            $stmt = $this->pdo->prepare('INSERT INTO permissions (name, description) VALUES (:name, :description)');
            $stmt->execute([':name' => $slug, ':description' => $slug]);
        }
    }

    public function testAWellBehavedPluginGrantsItsOwnPermissionToItsOwnRole(): void
    {
        $seeder = new PluginRoleSeeder($this->pdo);
        $plugin = $this->plugin('AcmeStock', [self::OWN], ['stock_keeper' => []], ['stock_keeper' => [self::OWN]]);

        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        $role = $this->roleId('stock_keeper', null);
        self::assertSame([self::OWN], $this->grants($role));
        self::assertSame('AcmeStock', $this->grantedBy($role, self::OWN));

        $seeder->removeGrants($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        self::assertSame([], $this->grants($role));
    }

    public function testAWellBehavedPluginWorksInAWorkspace(): void
    {
        $seeder = new PluginRoleSeeder($this->pdo);
        $plugin = $this->plugin('AcmeStock', [self::OWN], ['stock_keeper' => []], ['stock_keeper' => [self::OWN]]);

        $seeder->seed($plugin, self::TENANT);

        self::assertSame([self::OWN], $this->grants($this->roleId('stock_keeper', self::TENANT)));
    }

    public function testAPluginCannotGrantToARoleItDidNotCreate(): void
    {
        $before = $this->allGrants();
        $plugin = $this->plugin(
            'AcmeStock',
            [self::OWN, CorePermissions::USERS_DELETE],
            ['admin' => []],
            ['admin' => [self::OWN, CorePermissions::USERS_DELETE]]
        );

        (new PluginRoleSeeder($this->pdo))->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        self::assertSame($before, $this->allGrants(), 'an existing role gets nothing from the plugin');
    }

    public function testAPluginCannotGrantCorePermissionsEvenWhenItDeclaresThem(): void
    {
        $plugin = $this->plugin(
            'AcmeStock',
            [self::OWN, CorePermissions::USERS_DELETE, CorePermissions::ROLES_MANAGE, 'users:export'],
            ['stock_keeper' => []],
            ['stock_keeper' => [self::OWN, CorePermissions::USERS_DELETE, CorePermissions::ROLES_MANAGE, 'users:export']]
        );
        $this->pdo->exec("INSERT INTO permissions (name, description) VALUES ('users:export', 'x')");

        (new PluginRoleSeeder($this->pdo))->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        self::assertSame([self::OWN], $this->grants($this->roleId('stock_keeper', null)));
    }

    public function testAPluginCannotGrantPermissionsItDoesNotDeclare(): void
    {
        $plugin = $this->plugin('AcmeStock', [self::OWN], ['stock_keeper' => []], ['stock_keeper' => [self::OWN, self::OTHER]]);

        (new PluginRoleSeeder($this->pdo))->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        self::assertSame([self::OWN], $this->grants($this->roleId('stock_keeper', null)));
    }

    public function testAPluginCannotGrantToAnotherPluginsRole(): void
    {
        $seeder = new PluginRoleSeeder($this->pdo);
        $seeder->seed($this->plugin('AcmeStock', [self::OWN], ['stock_keeper' => []], ['stock_keeper' => [self::OWN]]), self::TENANT);
        $seeder->seed($this->plugin('Other', [self::OTHER], ['stock_keeper' => []], ['stock_keeper' => [self::OTHER]]), self::TENANT);

        self::assertSame([self::OWN], $this->grants($this->roleId('stock_keeper', self::TENANT)));
    }

    public function testAnOperatorApprovedRoleTakesThePluginsOwnPermissionsOnly(): void
    {
        $seeder = new PluginRoleSeeder($this->pdo);
        $admin = $this->roleId('admin', null);
        $before = $this->grants($admin);
        $seeder->approveRole('AcmeStock', $admin, null);
        $seeder->approveRole('AcmeStock', $admin, null);

        $plugin = $this->plugin(
            'AcmeStock',
            [self::OWN, CorePermissions::USERS_DELETE],
            ['admin' => []],
            ['admin' => [self::OWN, CorePermissions::USERS_DELETE]]
        );
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        $expected = array_values(array_unique([...$before, self::OWN]));
        sort($expected);
        self::assertSame($expected, $this->grants($admin));

        $seeder->removeGrants($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        self::assertSame($before, $this->grants($admin));
    }

    public function testUninstallLeavesCoreGrantsUntouched(): void
    {
        $seeder = new PluginRoleSeeder($this->pdo);
        $admin = $this->roleId('admin', null);
        $coreHeld = $this->grants($admin);
        self::assertContains(CorePermissions::USERS_READ, $coreHeld, 'migrations grant admin users:read');

        // Approved, and listing a permission admin already holds from core.
        $seeder->approveRole('AcmeStock', $admin, null);
        $plugin = $this->plugin(
            'AcmeStock',
            [self::OWN, CorePermissions::USERS_READ],
            ['admin' => [], 'stock_keeper' => []],
            ['admin' => [self::OWN, CorePermissions::USERS_READ], 'stock_keeper' => [self::OWN]]
        );
        $before = $this->allGrants();

        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        $seeder->removeGrants($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        self::assertSame($coreHeld, $this->grants($admin));
        self::assertSame($before, $this->allGrants(), 'only the plugin\'s own rows are removed');
    }

    public function testUninstallInOneWorkspaceLeavesAnotherScopeAlone(): void
    {
        $seeder = new PluginRoleSeeder($this->pdo);
        $plugin = $this->plugin('AcmeStock', [self::OWN], ['stock_keeper' => []], ['stock_keeper' => [self::OWN]]);
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        $seeder->seed($plugin, self::TENANT);

        $seeder->removeGrants($plugin, self::TENANT);

        self::assertSame([], $this->grants($this->roleId('stock_keeper', self::TENANT)));
        self::assertSame([self::OWN], $this->grants($this->roleId('stock_keeper', null)));
    }

    public function testARefusedRoleIsWarnedAboutOnceWithTheFix(): void
    {
        // A role from before 0.2.26: it exists, but carries no creator.
        $this->pdo->exec("INSERT INTO roles (name, description, created_at) VALUES ('stock_keeper', '', CURRENT_TIMESTAMP)");
        $plugin = $this->plugin('AcmeStock', [self::OWN], ['stock_keeper' => []], ['stock_keeper' => [self::OWN]]);

        $logger = $this->logger();
        $seeder = new PluginRoleSeeder($this->pdo, $logger);
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);
        (new PluginRoleSeeder($this->pdo, $logger))->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        $warnings = array_values(array_filter($logger->records, static fn (array $r): bool => $r[0] === 'warning'));
        self::assertCount(1, $warnings, 'one warning per role, not one per boot');
        self::assertStringContainsString('whity-cli plugin role-approve AcmeStock stock_keeper', $warnings[0][1]);
        self::assertCount(2, array_filter($logger->records, static fn (array $r): bool => $r[0] === 'debug'));
        self::assertSame([], $this->grants($this->roleId('stock_keeper', null)));
    }

    public function testApprovingTheRefusedRoleLetsThePluginGrantAndRearmsTheWarning(): void
    {
        $this->pdo->exec("INSERT INTO roles (name, description, created_at) VALUES ('stock_keeper', '', CURRENT_TIMESTAMP)");
        $plugin = $this->plugin('AcmeStock', [self::OWN], ['stock_keeper' => []], ['stock_keeper' => [self::OWN]]);
        $role = $this->roleId('stock_keeper', null);
        $logger = $this->logger();
        $seeder = new PluginRoleSeeder($this->pdo, $logger);
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        self::assertTrue($seeder->approveRole('AcmeStock', $role, 7));
        self::assertFalse($seeder->approveRole('AcmeStock', $role, 7), 'idempotent');
        $seeder->seed($plugin, PluginRoleSeeder::SYSTEM_TENANT_ID);

        self::assertSame([self::OWN], $this->grants($role));
        $approvedBy = $this->pdo->query("SELECT approved_by FROM plugin_role_approvals WHERE plugin_key = 'AcmeStock'");
        self::assertNotFalse($approvedBy);
        self::assertSame(7, (int) $approvedBy->fetchColumn());
        $marker = $this->pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key LIKE 'plugins.role_refused.%'");
        self::assertNotFalse($marker);
        self::assertSame(0, (int) $marker->fetchColumn(), 'the once-only marker goes with the approval');
    }

    public function testTheWarningForAWorkspaceRoleNamesTheWorkspace(): void
    {
        self::assertStringContainsString(
            'whity-cli plugin role-approve AcmeStock stock_keeper --tenant 1',
            PluginRoleSeeder::refusalMessage('AcmeStock', 'stock_keeper', self::TENANT)
        );
    }

    // -------------------------------------------------------------------------

    private function logger(): PluginRoleSeederRecordingLogger
    {
        return new PluginRoleSeederRecordingLogger();
    }

    /**
     * @param list<string>                                         $permissions
     * @param array<string, array{description?: string, parent?: string}> $roles
     * @param array<string, list<string>>                          $grants
     */
    private function plugin(string $name, array $permissions, array $roles, array $grants): PluginInterface
    {
        return new class ($name, $permissions, $roles, $grants) implements PluginInterface, PluginRolesInterface {
            /**
             * @param list<string>                                         $permissions
             * @param array<string, array{description?: string, parent?: string}> $roles
             * @param array<string, list<string>>                          $grants
             */
            public function __construct(
                private readonly string $name,
                private readonly array $permissions,
                private readonly array $roles,
                private readonly array $grants,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
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
                return $this->permissions;
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
                return $this->roles;
            }

            public function getRolePermissions(): array
            {
                return $this->grants;
            }
        };
    }

    private function roleId(string $name, ?int $tenantId): int
    {
        if ($tenantId === null) {
            $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL');
            $stmt->execute([':name' => $name]);
        } else {
            $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = :name AND tenant_id = :tenant_id');
            $stmt->execute([':name' => $name, ':tenant_id' => $tenantId]);
        }
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "role {$name} exists");

        return (int) $id;
    }

    /** @return list<string> */
    private function grants(int $roleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id ORDER BY p.name'
        );
        $stmt->execute([':role_id' => $roleId]);

        /** @var list<string> */
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function grantedBy(int $roleId, string $slug): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT rp.granted_by_plugin FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id AND p.name = :slug'
        );
        $stmt->execute([':role_id' => $roleId, ':slug' => $slug]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @return list<string> "role_id:permission_id" for every grant. */
    private function allGrants(): array
    {
        $rows = $this->pdo->query('SELECT role_id, permission_id FROM role_permissions ORDER BY role_id, permission_id');
        self::assertNotFalse($rows);
        $out = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $row['role_id'] . ':' . $row['permission_id'];
        }

        return $out;
    }
}

/** A logger that keeps what it was told. */
final class PluginRoleSeederRecordingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{0: string, 1: string}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : '', (string) $message];
    }
}
