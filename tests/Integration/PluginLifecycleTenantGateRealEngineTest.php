<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InstallFromStoreApiHandler;
use Whity\Api\PluginsApiHandler;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * The plugin lifecycle is install-wide, so only the system tenant may change it
 * (WHIT-869), on the real schema (SQLite, or Postgres with PHPUNIT_PG_DSN).
 *
 * The first test says why the slug can't be the whole check: the global role a
 * new workspace's first administrator gets holds the lifecycle slugs.
 */
final class PluginLifecycleTenantGateRealEngineTest extends TestCase
{
    private const WORKSPACE = 1;
    private const OPERATOR = 0;

    private PDO $pdo;
    private string $pluginDir;
    private PluginsApiHandler $handler;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make();
        $this->pluginDir = sys_get_temp_dir() . '/whity_gate_plugins_' . uniqid();
        mkdir($this->pluginDir, 0775, true);
        file_put_contents($this->pluginDir . '/Sentinel.php.disabled', "<?php\n");

        $loader = new PluginLoader($this->pluginDir, new Router(''));
        $loader->load();
        $this->handler = new PluginsApiHandler($this->pluginDir, $loader, $this->pdo);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        @unlink($this->pluginDir . '/Sentinel.php.disabled');
        @unlink($this->pluginDir . '/Sentinel.php');
        @rmdir($this->pluginDir);
    }

    public function testAGlobalRoleCanHoldTheLifecycleSlugsSoTheSlugAloneAdmitsAWorkspace(): void
    {
        $this->pdo->exec("INSERT INTO roles (name, tenant_id) SELECT 'admin', NULL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'admin' AND tenant_id IS NULL)");
        $count = $this->pdo->prepare(
            "SELECT COUNT(*) FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
               JOIN roles r ON r.id = rp.role_id
              WHERE r.name = 'admin' AND r.tenant_id IS NULL AND p.name = ?"
        );
        $count->execute([CorePermissions::PLUGINS_UPLOAD]);

        self::assertSame(1, (int) $count->fetchColumn());
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> */
    public static function lifecycleWrites(): array
    {
        return [
            'upload'    => ['upload', []],
            'enable'    => ['enable', ['name' => 'Sentinel']],
            'disable'   => ['disable', ['name' => 'Sentinel']],
            're-enable' => ['reEnable', ['id' => 'Sentinel']],
            'uninstall' => ['uninstall', ['id' => 'Sentinel']],
            'reload'    => ['reload', []],
        ];
    }

    /** @param array<string, string> $params */
    #[DataProvider('lifecycleWrites')]
    public function testAWorkspaceCannotChangeTheInstall(string $method, array $params): void
    {
        TenantContext::setTenantId(self::WORKSPACE);

        self::assertSame(403, $this->call($method, $params)->getStatusCode());
        self::assertFileExists($this->pluginDir . '/Sentinel.php.disabled', 'nothing on disk moved');
    }

    /** @param array<string, string> $params */
    #[DataProvider('lifecycleWrites')]
    public function testNoTenantIsNotAnOperator(string $method, array $params): void
    {
        self::assertSame(403, $this->call($method, $params)->getStatusCode());
    }

    public function testTheOperatorStillReachesTheHandler(): void
    {
        TenantContext::setTenantId(self::OPERATOR);

        self::assertSame(200, $this->call('enable', ['name' => 'Sentinel'])->getStatusCode());
        self::assertFileExists($this->pluginDir . '/Sentinel.php');
    }

    public function testAWorkspaceCannotInstallFromAStoreAndNothingIsFetched(): void
    {
        TenantContext::setTenantId(self::WORKSPACE);
        $fetched = false;

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setGlobal(SettingsRegistry::PLUGINS_STORE_ALLOWED_HOSTS, 'store.example.com');
        $handler = new InstallFromStoreApiHandler(
            $this->pluginDir,
            $settings,
            null,
            null,
            static function () use (&$fetched): string {
                $fetched = true;

                return 'bytes';
            }
        );

        $response = $handler->install(new Request('POST', '/api/plugins/install-from-store', [], (string) json_encode([
            'store_url' => 'https://store.example.com',
            'slug' => 'x',
            'version' => '1.0.0',
        ])));

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($fetched);
    }

    public function testAWorkspaceSeesThePluginsButNotTheDeploymentsDetails(): void
    {
        TenantContext::setTenantId(self::WORKSPACE);

        $body = json_decode($this->handler->list(new Request('GET', '/api/plugins'))->getBody(), true);

        self::assertFalse($body['meta']['can_operate']);
        self::assertSame('Sentinel', $body['data'][0]['id']);
        self::assertNull($body['data'][0]['file']);
        self::assertArrayNotHasKey('last_error', $body['data'][0]);
    }

    public function testTheOperatorSeesTheFileAndMayOperate(): void
    {
        TenantContext::setTenantId(self::OPERATOR);

        $body = json_decode($this->handler->list(new Request('GET', '/api/plugins'))->getBody(), true);

        self::assertTrue($body['meta']['can_operate']);
        self::assertSame('Sentinel.php.disabled', $body['data'][0]['file']);
    }

    /** @param array<string, string> $params */
    private function call(string $method, array $params): Response
    {
        $request = new Request('POST', '/api/plugins/x', [], '{}');

        return $method === 'reload'
            ? $this->handler->reload($request)
            : $this->handler->{$method}($request, $params);
    }
}
