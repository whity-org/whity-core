<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PluginsApiHandler;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * #1907: the operator approves, role by role, an existing role for a plugin's
 * own grants (POST /api/plugins/{name}/roles/{role}/approve). Roles a plugin made
 * before 0.2.26 carry no creator (migration 221, GHSA-r4hx-wj99-jw8m), so the
 * seeder refuses them; there is deliberately no automatic backfill.
 *
 * On the real schema: SQLite by default, PostgreSQL when PHPUNIT_PG_DSN is set.
 */
final class PluginRoleApprovalApiRealEngineTest extends TestCase
{
    private const OPERATOR = 0;
    private const WORKSPACE = 1;
    private const OWN = 'acmestock:read';

    private static string $dir;

    private PDO $pdo;
    private PluginsApiHandler $handler;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_role_approve_' . uniqid();
        mkdir(self::$dir . '/AcmeStock', 0755, true);
        file_put_contents(self::$dir . '/AcmeStock/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace AcmeStock;

use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRolesInterface;

final class Plugin implements PluginInterface, PluginRolesInterface
{
    public function getName(): string { return 'AcmeStock'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getSdkConstraint(): string { return '^1.0'; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return []; }
    public function getPermissions(): array { return ['acmestock:read']; }
    public function getRoutes(): array { return []; }
    public function getRoles(): array { return ['stock_keeper' => ['description' => 'Keeps stock']]; }
    public function getRolePermissions(): array { return ['stock_keeper' => ['acmestock:read']]; }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/AcmeStock/Plugin.php');
        @rmdir(self::$dir . '/AcmeStock');
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, created_at) VALUES (1, 'Example Bakery', CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($this->pdo);
        $this->pdo->exec("INSERT INTO permissions (name, description) VALUES ('acmestock:read', 'Read stock')");
        // Roles from before 0.2.26: they exist, but no plugin is recorded as their creator.
        $this->pdo->exec("INSERT INTO roles (name, description, created_at) VALUES ('stock_keeper', '', CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO roles (name, description, tenant_id, created_at) VALUES ('stock_keeper', '', 1, CURRENT_TIMESTAMP)");

        // No seeder on the loader: loading must not grant, so the approval is what does.
        $loader = new PluginLoader(self::$dir, new Router(''));
        $loader->load();
        $this->handler = new PluginsApiHandler(self::$dir, $loader, $this->pdo, new AuditLogger($this->pdo));
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testTheOperatorApprovesARoleAndThePluginsOwnGrantsApply(): void
    {
        TenantContext::setTenantId(self::OPERATOR);
        $role = $this->roleId(null);
        self::assertSame([], $this->grants($role), 'refused before the approval');

        $response = $this->approve('AcmeStock', 'stock_keeper');

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $data = $this->data($response);
        self::assertSame($role, $data['role_id']);
        self::assertNull($data['tenant_id']);
        self::assertFalse($data['already_allowed']);
        self::assertSame([self::OWN], $this->grants($role));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM plugin_role_approvals WHERE plugin_key = 'AcmeStock' AND role_id = {$role}"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'plugin.role_approve' AND target_id = '{$role}'"));
        self::assertSame([], $this->grants($this->roleId(self::WORKSPACE)), 'the workspace role is a different role');
    }

    public function testApprovingTwiceIsHarmless(): void
    {
        TenantContext::setTenantId(self::OPERATOR);
        $this->approve('AcmeStock', 'stock_keeper');

        $again = $this->approve('AcmeStock', 'stock_keeper');

        self::assertSame(200, $again->getStatusCode());
        self::assertTrue($this->data($again)['already_allowed']);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM plugin_role_approvals WHERE plugin_key = 'AcmeStock'"));
    }

    public function testTheOperatorApprovesAWorkspacesRole(): void
    {
        TenantContext::setTenantId(self::OPERATOR);

        $response = $this->approve('AcmeStock', 'stock_keeper', ['tenant_id' => self::WORKSPACE]);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame(self::WORKSPACE, $this->data($response)['tenant_id']);
        self::assertSame([self::OWN], $this->grants($this->roleId(self::WORKSPACE)));
        self::assertSame([], $this->grants($this->roleId(null)));
    }

    public function testAWorkspaceCannotApprove(): void
    {
        TenantContext::setTenantId(self::WORKSPACE);

        self::assertSame(403, $this->approve('AcmeStock', 'stock_keeper')->getStatusCode());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM plugin_role_approvals'));
    }

    public function testOnlyARoleThePluginGrantsToCanBeApproved(): void
    {
        TenantContext::setTenantId(self::OPERATOR);

        self::assertSame(422, $this->approve('AcmeStock', 'admin')->getStatusCode());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM plugin_role_approvals'));
    }

    public function testAnUnknownPluginOrMissingRoleIsNotFound(): void
    {
        TenantContext::setTenantId(self::OPERATOR);
        $this->pdo->exec("DELETE FROM roles WHERE name = 'stock_keeper' AND tenant_id = 1");

        self::assertSame(404, $this->approve('Nope', 'stock_keeper')->getStatusCode());
        self::assertSame(404, $this->approve('AcmeStock', 'stock_keeper', ['tenant_id' => self::WORKSPACE])->getStatusCode());
        self::assertSame(400, $this->approve('AcmeStock', 'stock_keeper', ['tenant_id' => 'one'])->getStatusCode());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM plugin_role_approvals'));
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function approve(string $plugin, string $role, array $body = []): Response
    {
        $request = new Request(
            'POST',
            "/api/plugins/{$plugin}/roles/{$role}/approve",
            ['Content-Type' => 'application/json'],
            $body === [] ? '' : (string) json_encode($body)
        );

        return $this->handler->approveRole($request, ['name' => $plugin, 'role' => $role]);
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data']);

        return $decoded['data'];
    }

    private function roleId(?int $tenantId): int
    {
        $stmt = $tenantId === null
            ? $this->pdo->prepare("SELECT id FROM roles WHERE name = 'stock_keeper' AND tenant_id IS NULL")
            : $this->pdo->prepare("SELECT id FROM roles WHERE name = 'stock_keeper' AND tenant_id = :t");
        $stmt->execute($tenantId === null ? [] : [':t' => $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function grants(int $roleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r ORDER BY p.name'
        );
        $stmt->execute([':r' => $roleId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
