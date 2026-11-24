<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FrontendFeaturesApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\PluginLoader;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\RoleGrantGuard;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\PluginInterface;

/**
 * A code plugin's declared permissions always reach the `permissions`
 * catalogue, so roles can be granted them (found on app.whity.dev: Whity Pay
 * declares `whity_pay:manage`, seeds no roles, and so had no catalogue row and
 * no role could ever hold it).
 *
 *  - loading a plugin catalogues what it declares, on every boot, which is
 *    also the backfill for installs already running;
 *  - it is idempotent and keeps an existing description;
 *  - a plugin never creates a core permission or one under a core resource
 *    (GHSA-r4hx-wj99-jw8m);
 *  - the System operator can put the permission on a System-only role, the
 *    holder then sees the plugin's screen, and nobody else gains anything.
 */
final class PluginPermissionCatalogueRealEngineTest extends TestCase
{
    private const SYSTEM = 0;
    private const WORKSPACE = 1;
    private const MANAGE = 'acme_pay:manage';
    private const WORKSPACE_PERMISSION = 'acme_pay:workspace';

    /** What a System admin holds: role management and plugin management, nothing of Acme Pay. */
    private const OPERATOR_PERMISSIONS = [
        'roles:read', 'roles:write', 'roles:manage', 'permissions:read',
        'users:read', 'users:write', 'plugins:read', 'plugins:enable',
    ];

    private static string $pluginDir;

    private PDO $pdo;
    private Router $router;
    private PluginLoader $loader;
    private PermissionRegistry $registry;

    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = sys_get_temp_dir() . '/whity_permcat_' . uniqid();
        mkdir(self::$pluginDir . '/AcmePay', 0755, true);

        // Like Whity Pay: declares permissions, a setup screen gated on one of
        // them, and NO roles. It also tries to declare core permissions and one
        // under a core resource, which must never reach the catalogue from here.
        file_put_contents(self::$pluginDir . '/AcmePay/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace AcmePay;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginFrontendInterface
{
    public function getName(): string { return 'AcmePay'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/acme-pay/setup',
            'handler' => static fn (Request $r): Response => Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'acme_pay:manage',
        ]];
    }
    public function getPermissions(): array
    {
        return ['acme_pay:manage', 'acme_pay:workspace', 'roles:manage', 'users:export'];
    }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getFrontendFeatures(): array
    {
        return [[
            'id' => 'acme-pay',
            'label' => 'Acme Pay',
            'screen' => 'custom',
            'requiredPermission' => 'acme_pay:manage',
        ]];
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$pluginDir . '/AcmePay/Plugin.php');
        @rmdir(self::$pluginDir . '/AcmePay');
        @rmdir(self::$pluginDir);
    }

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::WORKSPACE . ", 'Example Bakery', 'example-bakery')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->loader = $this->bootLoader();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    public function testLoadingAPluginCataloguesThePermissionsItDeclares(): void
    {
        self::assertSame([self::MANAGE, self::WORKSPACE_PERMISSION], $this->catalogued('acme_pay:%'));
    }

    public function testCataloguingIsIdempotentAndKeepsAnExistingDescription(): void
    {
        $this->pdo->prepare('UPDATE permissions SET description = ? WHERE name = ?')
            ->execute(['Set up Acme Pay', self::MANAGE]);
        $count = $this->scalar('SELECT COUNT(*) FROM permissions');

        // A second boot, and a direct second call, add nothing.
        $this->bootLoader();
        $plugin = $this->loader->getPluginInstance('AcmePay\\Plugin');
        self::assertInstanceOf(PluginInterface::class, $plugin);
        self::assertSame(0, (new PluginRoleSeeder($this->pdo))->catalogue($plugin));

        self::assertSame($count, $this->scalar('SELECT COUNT(*) FROM permissions'));
        $stmt = $this->pdo->prepare('SELECT description FROM permissions WHERE name = ?');
        $stmt->execute([self::MANAGE]);
        self::assertSame('Set up Acme Pay', $stmt->fetchColumn());
    }

    public function testAPluginNeverCreatesACorePermissionOrOneUnderACoreResource(): void
    {
        self::assertSame([], $this->catalogued('users:export'), 'users is a core resource');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM permissions WHERE name = ?');
        $stmt->execute([CorePermissions::ROLES_MANAGE]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'the core row stays the only one');
    }

    public function testTheSystemOperatorGrantsItToASystemOnlyRoleAndTheHolderSeesTheScreen(): void
    {
        $admin = $this->role('admin', null, self::OPERATOR_PERMISSIONS);
        $operator = $this->profile('Amro Saleh', self::SYSTEM, $admin);
        TenantContext::setTenantId(self::SYSTEM);

        self::assertNotContains('acme-pay', $this->featureIds($operator), 'admin alone does not hold it');

        // Settings › Roles: a new role in the System workspace with the plugin's permission.
        $created = $this->roles()->create($this->req($operator, self::SYSTEM, [
            'name' => 'Acme Pay operator',
            'permissions' => [self::MANAGE],
        ]));
        self::assertSame(201, $created->getStatusCode(), $created->getBody());
        $roleId = $this->scalar("SELECT id FROM roles WHERE name = 'Acme Pay operator'");
        self::assertSame(self::SYSTEM, $this->scalar('SELECT tenant_id FROM roles WHERE id = ' . $roleId), 'System-only, not global');
        self::assertSame([self::MANAGE], $this->rolePermissions($roleId));

        // And edited there: the other permission added through the roles API.
        $added = $this->roles()->grantPermissions(
            $this->req($operator, self::SYSTEM, ['permissions' => [self::WORKSPACE_PERMISSION]]),
            ['id' => (string) $roleId]
        );
        self::assertSame(200, $added->getStatusCode(), $added->getBody());
        self::assertSame([self::MANAGE, self::WORKSPACE_PERMISSION], $this->rolePermissions($roleId));

        // Holding the role in the System workspace shows the plugin's screen.
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, is_primary, created_at)
             VALUES (?, ?, ?, 'active', false, NOW())"
        )->execute([$operator, self::SYSTEM, $roleId]);
        RoleChecker::clearCache();
        self::assertContains('acme-pay', $this->featureIds($operator));

        // The shared admin role was never touched, so no workspace admin gains it.
        self::assertNotContains(self::MANAGE, $this->rolePermissions($admin));
    }

    public function testTheSystemRuleStillComparesCorePermissions(): void
    {
        // A role that may manage roles and enable plugins, and nothing else.
        $operator = $this->profile('Amro Saleh', self::SYSTEM, $this->role('plugin_operator', self::SYSTEM, self::OPERATOR_PERMISSIONS));
        TenantContext::setTenantId(self::SYSTEM);

        $refused = $this->roles()->create($this->req($operator, self::SYSTEM, [
            'name' => 'sneaky',
            'permissions' => [self::MANAGE, CorePermissions::AUDIT_READ],
        ]));

        self::assertSame(403, $refused->getStatusCode(), $refused->getBody());
        self::assertSame([CorePermissions::AUDIT_READ], $this->decode($refused)['details']['permissions'] ?? null);
    }

    public function testOnlyAnOperatorWhoMayEnablePluginsGetsTheSystemRule(): void
    {
        $roles = array_values(array_diff(self::OPERATOR_PERMISSIONS, ['plugins:enable']));
        $manager = $this->profile('Awss Haddad', self::SYSTEM, $this->role('role_manager', self::SYSTEM, $roles));
        TenantContext::setTenantId(self::SYSTEM);

        $refused = $this->roles()->create($this->req($manager, self::SYSTEM, ['name' => 'x', 'permissions' => [self::MANAGE]]));

        self::assertSame(403, $refused->getStatusCode(), $refused->getBody());
    }

    public function testAWorkspaceAdminWhoMayEnablePluginsDoesNotGetTheSystemRule(): void
    {
        $bakeryAdmin = $this->role('bakery_admin', self::WORKSPACE, self::OPERATOR_PERMISSIONS);
        $person = $this->profile('Ahmad Khalil', self::WORKSPACE, $bakeryAdmin);
        TenantContext::setTenantId(self::WORKSPACE);

        $refused = $this->roles()->create($this->req($person, self::WORKSPACE, ['name' => 'x', 'permissions' => [self::MANAGE]]));

        self::assertSame(403, $refused->getStatusCode(), $refused->getBody());
    }

    public function testTheWebEntryPointWiresTheSeederSoBootCatalogues(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../public/index.php');
        $start = strpos($source, '$pluginLoader = new PluginLoader(');
        self::assertNotFalse($start);
        $construction = substr($source, $start, (int) strpos($source, ';', $start) - $start);

        self::assertStringContainsString('new PluginRoleSeeder(', $construction);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function bootLoader(): PluginLoader
    {
        // The features handler reads routes from the router the loader used.
        $this->router = new Router('');
        $this->registry = new PermissionRegistry();
        $this->registry->registerCorePermissions();
        $loader = new PluginLoader(
            self::$pluginDir,
            $this->router,
            $this->registry,
            new HookManager(),
            null,
            new PluginRoleSeeder($this->pdo)
        );
        $loader->load();
        $this->loader = $loader;

        return $loader;
    }

    private function roleChecker(): RoleChecker
    {
        // The same registry the loader filled, as in public/index.php.
        $pdo = $this->pdo;

        return new RoleChecker(Database::withFactory(static fn (): PDO => $pdo), $this->registry);
    }

    private function roles(): RolesApiHandler
    {
        return new RolesApiHandler($this->pdo, new HookManager(), null, null, null, new RoleGrantGuard($this->roleChecker()));
    }

    /** @return list<string> */
    private function featureIds(int $profileId): array
    {
        $labels = new ServerLabels(new LanguageRegistry(
            new LanguageRepository(new PDO('sqlite::memory:')),
            new TranslationRepository(new PDO('sqlite::memory:')),
            new StaticTenantContextAdapter(),
        ));
        $handler = new FrontendFeaturesApiHandler($this->loader, $this->roleChecker(), $this->router, $labels);

        $request = new Request('GET', '/api/frontend/features');
        $request->user = (object) ['profile_id' => $profileId];
        $response = $handler->list($request);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return array_column($this->decode($response)['data'] ?? [], 'id');
    }

    /** @param array<string, mixed> $body */
    private function req(int $profileId, int $tenantId, array $body): Request
    {
        $request = new Request('POST', '/x', ['Content-Type' => 'application/json'], (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    /** @param list<string> $permissions */
    private function role(string $name, ?int $tenantId, array $permissions): int
    {
        $existing = $tenantId === null ? $this->scalar("SELECT id FROM roles WHERE tenant_id IS NULL AND name = '{$name}'") : 0;
        if ($existing > 0) {
            $roleId = $existing;
        } else {
            $this->pdo->prepare('INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, ?, ?, NOW())')
                ->execute([$name, $name, $tenantId]);
            $roleId = (int) $this->pdo->lastInsertId();
        }
        foreach ($permissions as $permission) {
            $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')->execute([$permission]);
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at)
                 SELECT ?, id, NOW() FROM permissions WHERE name = ?'
            )->execute([$roleId, $permission]);
        }
        RoleChecker::clearCache();

        return $roleId;
    }

    private function profile(string $name, int $tenantId, int $roleId): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', false, 0, 0, NOW(), NOW())"
        )->execute([$name]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, is_primary, created_at)
             VALUES (?, ?, ?, 'active', true, NOW())"
        )->execute([$id, $tenantId, $roleId]);

        return $id;
    }

    /** @return list<string> */
    private function catalogued(string $like): array
    {
        $stmt = $this->pdo->prepare('SELECT name FROM permissions WHERE name LIKE ? ORDER BY name');
        $stmt->execute([$like]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function rolePermissions(int $roleId): array
    {
        $stmt = $this->pdo->query(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ' . $roleId . ' ORDER BY p.name'
        );

        return array_values(array_map('strval', $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []));
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);

        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    /** @return array<string, mixed> */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
