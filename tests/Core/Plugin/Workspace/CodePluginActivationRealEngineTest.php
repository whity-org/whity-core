<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Workspace;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\WorkspaceCodePluginsApiHandler;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\PluginRecords;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-1122: an installed code plugin, switched on per workspace. Covers the
 * real schema (migrations 287 and 288), the loader's route, screen and card
 * gates, and the workspace and operator API.
 */
final class CodePluginActivationRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const CAFE = 2;

    private static string $dir;

    private PDO $pdo;
    private CodePluginActivation $activation;
    private PluginLoader $loader;
    private Router $router;
    private WorkspaceCodePluginsApiHandler $api;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_ws_plugins_' . uniqid();
        mkdir(self::$dir . '/WsCakes', 0755, true);
        file_put_contents(self::$dir . '/WsCakes/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsCakes;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginFrontendInterface
{
    public function getName(): string { return 'WsCakes'; }
    public function getVersion(): string { return '1.2.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return ['wscakes:view']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/wscakes/orders',
            'handler' => static fn (Request $r): Response => Response::json(['data' => ['cake']]),
            'requiredRole' => null,
            'requiredPermission' => 'wscakes:view',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [[
            'id' => 'wscakes-orders',
            'label' => 'Cake orders',
            'screen' => 'custom',
            'requiredPermission' => 'wscakes:view',
        ]];
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/WsCakes/Plugin.php');
        @rmdir(self::$dir . '/WsCakes');
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery'), (2, 'example-cafe')");
        $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES (7, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $this->activation = new CodePluginActivation($this->pdo);
        $this->router = new Router();
        $this->loader = new PluginLoader(self::$dir, $this->router, new PermissionRegistry(), new HookManager());
        $this->loader->setWorkspaceActivation($this->activation);
        $this->loader->load();
        $this->api = new WorkspaceCodePluginsApiHandler($this->loader, $this->activation, $this->pdo, new AuditLogger($this->pdo));
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAPluginWithNoAccessRowIsOnEverywhereAsBefore(): void
    {
        $this->assertTrue($this->activation->isOn('WsCakes', self::BAKERY));
        $this->assertTrue($this->activation->isOn('WsCakes', self::CAFE));
        $this->assertSame(CodePluginActivation::ALL, $this->activation->accessOf('WsCakes'));
    }

    public function testChooseIsOffUntilAWorkspaceTurnsItOnAndOnlyThere(): void
    {
        $this->activation->setAccess('WsCakes', CodePluginActivation::CHOOSE);
        $this->assertFalse($this->activation->isOn('WsCakes', self::BAKERY));

        $this->activation->setEnabled(self::BAKERY, 'WsCakes', true, 7);
        $this->assertTrue($this->activation->isOn('WsCakes', self::BAKERY));
        $this->assertFalse($this->activation->isOn('WsCakes', self::CAFE));
        $this->assertTrue($this->activation->isOn('WsCakes', 0), 'The system workspace always sees it');

        $row = $this->activation->workspaceRows(self::BAKERY)['WsCakes'];
        $this->assertTrue($row['enabled']);
        $this->assertSame(['id' => 7, 'name' => 'Amro Saleh'], $row['changed_by']);
    }

    public function testOperatorOnlyIsOffForEveryWorkspace(): void
    {
        $this->activation->setAccess('WsCakes', CodePluginActivation::OPERATOR);
        $this->activation->setEnabled(self::BAKERY, 'WsCakes', true);
        $this->activation->resetRequest();

        $this->assertFalse($this->activation->isOn('WsCakes', self::BAKERY));
        $this->assertTrue($this->activation->isOn('WsCakes', 0));
    }

    public function testANewInstallStartsAsChooseAndAReinstallKeepsTheOperatorsChoice(): void
    {
        $records = new PluginRecords($this->pdo);
        $records->recordInstall('WsCakes', 'upload', null, null, '1.2.0', null, [], [], null);
        $this->activation->resetRequest();
        $this->assertSame(CodePluginActivation::CHOOSE, $this->activation->accessOf('WsCakes'));

        $this->activation->setAccess('WsCakes', CodePluginActivation::ALL);
        $records->recordInstall('WsCakes', 'upload', null, null, '1.2.1', null, [], [], null);
        $this->activation->resetRequest();
        $this->assertSame(CodePluginActivation::ALL, $this->activation->accessOf('WsCakes'));
    }

    public function testRoutesScreensAndCardsDisappearWhenOffAndComeBackWhenOn(): void
    {
        $this->activation->setAccess('WsCakes', CodePluginActivation::CHOOSE);
        TenantContext::setTenantId(self::BAKERY);

        $off = $this->call('/api/v1/wscakes/orders');
        $this->assertSame(404, $off->getStatusCode());
        $this->assertStringContainsString('plugin_off', $off->getBody());
        $this->assertSame([], $this->loader->getFrontendFeatures());

        $this->activation->setEnabled(self::BAKERY, 'WsCakes', true);
        $on = $this->call('/api/v1/wscakes/orders');
        $this->assertSame(200, $on->getStatusCode());
        $this->assertSame(['wscakes-orders'], array_column($this->loader->getFrontendFeatures(), 'id'));

        TenantContext::reset();
        TenantContext::setTenantId(self::CAFE);
        $this->assertSame(404, $this->call('/api/v1/wscakes/orders')->getStatusCode());
    }

    public function testAWorkspaceTurnsAPluginOnAndOffAndSeesWhoDidIt(): void
    {
        $this->activation->setAccess('WsCakes', CodePluginActivation::CHOOSE);
        TenantContext::setTenantId(self::BAKERY);

        $list = $this->json($this->api->list($this->request('GET')));
        $this->assertSame('WsCakes', $list['data'][0]['name']);
        $this->assertFalse($list['data'][0]['enabled']);
        $this->assertTrue($list['data'][0]['canSwitch']);

        $this->assertSame(200, $this->api->enable($this->request('POST'), ['plugin' => 'WsCakes'])->getStatusCode());
        $this->assertSame(200, $this->api->disable($this->request('POST'), ['plugin' => 'WsCakes'])->getStatusCode());
        $this->assertSame(200, $this->api->enable($this->request('POST'), ['plugin' => 'WsCakes'])->getStatusCode());

        $list = $this->json($this->api->list($this->request('GET')));
        $this->assertTrue($list['data'][0]['enabled']);
        $this->assertSame('Amro Saleh', $list['data'][0]['changedBy']['name']);

        $history = $this->json($this->api->history($this->request('GET'), ['plugin' => 'WsCakes']));
        $this->assertSame(['enabled', 'disabled', 'enabled'], array_column($history['data'], 'action'));
        $this->assertSame('Amro Saleh', $history['data'][0]['by']['name']);

        TenantContext::reset();
        TenantContext::setTenantId(self::CAFE);
        $cafeHistory = $this->json($this->api->history($this->request('GET'), ['plugin' => 'WsCakes']));
        $this->assertSame([], $cafeHistory['data'], 'Another workspace never sees the history');
    }

    public function testAnAllPluginCantBeSwitchedAndAnOperatorPluginIsntThere(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $response = $this->api->disable($this->request('POST'), ['plugin' => 'WsCakes']);
        $this->assertSame(409, $response->getStatusCode());

        $this->activation->setAccess('WsCakes', CodePluginActivation::OPERATOR);
        $this->assertSame([], $this->json($this->api->list($this->request('GET')))['data']);
        $this->assertSame(404, $this->api->enable($this->request('POST'), ['plugin' => 'WsCakes'])->getStatusCode());
        $this->assertSame(404, $this->api->enable($this->request('POST'), ['plugin' => 'NoSuchPlugin'])->getStatusCode());
    }

    public function testOnlyTheOperatorSetsWhoCanUseIt(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $refused = $this->api->setAccess($this->request('PUT', ['access' => 'operator']), ['plugin' => 'WsCakes']);
        $this->assertSame(403, $refused->getStatusCode());

        TenantContext::reset();
        TenantContext::setTenantId(0);
        $bad = $this->api->setAccess($this->request('PUT', ['access' => 'everyone']), ['plugin' => 'WsCakes']);
        $this->assertSame(422, $bad->getStatusCode());

        $ok = $this->api->setAccess($this->request('PUT', ['access' => 'choose', 'freeForAll' => true]), ['plugin' => 'WsCakes']);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(
            [['name' => 'WsCakes', 'version' => '1.2.0', 'access' => 'choose', 'freeForAll' => true]],
            $this->json($this->api->accessList($this->request('GET')))['data'],
        );
        $count = $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'code_plugin.access_changed'");
        $this->assertNotFalse($count);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    private function call(string $path): \Whity\Sdk\Http\Response
    {
        $match = $this->router->match(new Request('GET', $path));
        $this->assertNotNull($match);

        return ($match['handler'])(new Request('GET', $path), $match['params']);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, ?array $body = null): Request
    {
        $request = new Request($method, '/api/workspace/code-plugins', ['Content-Type' => 'application/json'], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => 7];

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(\Whity\Core\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
