<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Workspace;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\WorkspaceCodePluginsApiHandler;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plan\HostWorkspaceAddons;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-1125: a paid plugin needs a plan or add-on that includes it, a trial,
 * or the operator's "free for every workspace". When access ends it keeps
 * working for 7 days, then turns read-only, and nothing is deleted.
 */
final class PaidPluginAccessRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const CAFE = 2;
    private const DAY = 86400;

    private static string $dir;

    private PDO $pdo;
    private int $now;
    /** @var array<int, true> */
    private array $entitled = [];
    private ?string $licence = null;
    private CodePluginActivation $activation;
    private PluginLoader $loader;
    private Router $router;
    private HookManager $hooks;
    private WorkspaceCodePluginsApiHandler $api;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_ws_paid_' . uniqid();
        mkdir(self::$dir . '/WsPaid', 0755, true);
        file_put_contents(self::$dir . '/WsPaid/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsPaid;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginAccessInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginWorkspaceLifecycleInterface;

final class Plugin implements PluginInterface, PluginAccessInterface, PluginWorkspaceLifecycleInterface
{
    public function getName(): string { return 'WsPaid'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return ['wspaid:use']; }
    public function getHooks(): array
    {
        return ['order.placed' => static function (array $data): array {
            $data['seen_by'][] = 'WsPaid';

            return $data;
        }];
    }
    public function getRoutes(): array
    {
        return [
            ['method' => 'GET', 'path' => '/api/wspaid/stock', 'handler' => static fn (Request $r): Response => Response::json(['data' => []]), 'requiredRole' => null, 'requiredPermission' => 'wspaid:use'],
            ['method' => 'POST', 'path' => '/api/wspaid/stock', 'handler' => static fn (Request $r): Response => Response::json(['data' => ['saved' => true]], 201), 'requiredRole' => null, 'requiredPermission' => 'wspaid:use'],
        ];
    }
    public function getAccess(): ?array
    {
        return ['entitlement' => 'wspaid.access', 'product' => 'plugin.ws-paid', 'trialDays' => 14];
    }
    public function onWorkspaceEnabled(int $tenantId): void { $GLOBALS['wspaid_calls'][] = "on:{$tenantId}"; }
    public function onWorkspaceDisabled(int $tenantId): void { $GLOBALS['wspaid_calls'][] = "off:{$tenantId}"; }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/WsPaid/Plugin.php');
        @rmdir(self::$dir . '/WsPaid');
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery'), (2, 'example-cafe')");
        $this->now = (int) strtotime('2026-11-16 10:00:00 UTC');

        $this->activation = new CodePluginActivation($this->pdo, fn (): int => $this->now);
        $this->router = new Router();
        $this->hooks = new HookManager();
        $this->loader = new PluginLoader(self::$dir, $this->router, new PermissionRegistry(), $this->hooks);
        $this->loader->setWorkspaceActivation($this->activation);
        $this->loader->load();
        $GLOBALS['wspaid_calls'] = [];
        $this->activation->usePaidAccess(fn (): array => $this->loader->getPaidAccess());
        $this->activation->useEntitlements(fn (int $tenantId, string $key): bool => $key === 'wspaid.access' && isset($this->entitled[$tenantId]));
        $this->activation->useInstallLicence(fn (string $plugin): ?string => $this->licence);
        $this->activation->setAccess('WsPaid', CodePluginActivation::CHOOSE);
        $this->api = new WorkspaceCodePluginsApiHandler($this->loader, $this->activation, $this->pdo, new AuditLogger($this->pdo));
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testThePluginsDeclarationIsRead(): void
    {
        $this->assertSame(
            ['WsPaid' => ['entitlement' => 'wspaid.access', 'product' => 'plugin.ws-paid', 'trialDays' => 14]],
            $this->loader->getPaidAccess(),
        );
    }

    public function testTurningOnAPaidPluginNeedsAPlanAPurchaseOrATrial(): void
    {
        $this->inWorkspace(self::BAKERY);
        $refused = $this->api->enable($this->request('POST'), ['plugin' => 'WsPaid']);
        $this->assertSame(402, $refused->getStatusCode());
        $this->assertSame(['reason' => 'needs_purchase', 'product' => 'plugin.ws-paid', 'trialAvailable' => true], $this->json($refused)['details']);

        $this->entitled[self::BAKERY] = true;
        $this->activation->resetRequest();
        $this->assertSame(200, $this->api->enable($this->request('POST'), ['plugin' => 'WsPaid'])->getStatusCode());
        $this->assertSame(['state' => 'on', 'reason' => 'entitled', 'graceUntil' => null, 'trialEndsAt' => null], $this->activation->stateFor('WsPaid', self::BAKERY));
        $this->assertSame(['on:1'], $GLOBALS['wspaid_calls'], 'The plugin hears that the workspace turned it on');
    }

    public function testFreeForEveryWorkspaceNeedsNothing(): void
    {
        $this->activation->setAccess('WsPaid', CodePluginActivation::CHOOSE, true);
        $this->inWorkspace(self::CAFE);
        $this->assertSame(200, $this->api->enable($this->request('POST'), ['plugin' => 'WsPaid'])->getStatusCode());
        $this->assertSame('free_for_all', $this->activation->stateFor('WsPaid', self::CAFE)['reason']);
    }

    public function testATrialRunsOnceThenGraceThenReadOnlyWithNothingDeleted(): void
    {
        $this->inWorkspace(self::BAKERY);
        $trial = $this->api->trial($this->request('POST'), ['plugin' => 'WsPaid']);
        $this->assertSame(200, $trial->getStatusCode());
        $this->assertSame('2026-11-30 10:00:00', $this->json($trial)['data']['trialEndsAt']);
        $this->assertSame(409, $this->api->trial($this->request('POST'), ['plugin' => 'WsPaid'])->getStatusCode(), 'One trial per workspace');
        $this->assertSame(201, $this->call('POST')->getStatusCode());

        // The trial ends: 7 days of grace, still fully on.
        $this->now += 15 * self::DAY;
        $this->activation->resetRequest();
        $state = $this->activation->stateFor('WsPaid', self::BAKERY);
        $this->assertSame(['on', 'grace', '2026-12-07 10:00:00'], [$state['state'], $state['reason'], $state['graceUntil']]);

        // Grace ends: read-only. Reads work, writes are refused, hooks pause.
        $this->now += 7 * self::DAY;
        $this->activation->resetRequest();
        $this->assertSame(['read_only', 'lapsed'], array_slice(array_values($this->activation->stateFor('WsPaid', self::BAKERY)), 0, 2));
        $this->assertSame(200, $this->call('GET')->getStatusCode());
        $write = $this->call('POST');
        $this->assertSame(402, $write->getStatusCode());
        $this->assertStringContainsString('plugin_read_only', $write->getBody());
        $this->assertArrayNotHasKey('seen_by', $this->hooks->dispatch('order.placed', []));
        $this->assertTrue($this->activation->isVisible('WsPaid', self::BAKERY));

        $list = $this->json($this->api->list($this->request('GET')))['data'][0];
        $this->assertSame(['read_only', true, false, false], [$list['state'], $list['enabled'], $list['trialAvailable'], $list['mayTurnOn']]);
    }

    public function testAnAddOnGrantsAccessAndRevokingItStartsGrace(): void
    {
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, sort_order, is_addon, created_at, updated_at)
                          VALUES (90, 'plugin.ws-paid.monthly', 'WsPaid', true, 1, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $addons = new HostWorkspaceAddons($this->pdo, $this->activation, new AuditLogger($this->pdo));
        $this->activation->setEnabled(self::BAKERY, 'WsPaid', true);

        $this->assertTrue($addons->grant(self::BAKERY, 'plugin.ws-paid.monthly'));
        $this->assertFalse($addons->grant(self::BAKERY, 'no.such.plan'));
        $held = $this->pdo->query('SELECT COUNT(*) FROM tenant_plan_addons WHERE tenant_id = 1 AND plan_id = 90');
        $this->assertNotFalse($held);
        $this->assertSame(1, (int) $held->fetchColumn());

        $addons->revoke(self::BAKERY, 'plugin.ws-paid.monthly');
        $this->activation->resetRequest();
        $state = $this->activation->stateFor('WsPaid', self::BAKERY);
        $this->assertSame(['on', 'grace', '2026-11-23 10:00:00'], [$state['state'], $state['reason'], $state['graceUntil']]);

        $addons->grant(self::BAKERY, 'plugin.ws-paid.monthly');
        $this->entitled[self::BAKERY] = true;
        $this->activation->resetRequest();
        $this->assertSame('entitled', $this->activation->stateFor('WsPaid', self::BAKERY)['reason']);
        $lapsed = $this->pdo->query("SELECT access_lapsed_at FROM tenant_code_plugins WHERE tenant_id = 1 AND plugin_name = 'WsPaid'");
        $this->assertNotFalse($lapsed);
        $this->assertNull($lapsed->fetchColumn(), 'Buying again ends the grace');
    }

    public function testWithoutTheInstallationsStoreLicenceItIsReadOnlyEverywhere(): void
    {
        $this->entitled[self::BAKERY] = true;
        $this->activation->setEnabled(self::BAKERY, 'WsPaid', true);
        $this->licence = 'unlicensed';
        $this->activation->resetRequest();

        $this->assertSame(['read_only', 'installation_unlicensed'], array_slice(array_values($this->activation->stateFor('WsPaid', self::BAKERY)), 0, 2));
        $this->assertSame('on', $this->activation->stateFor('WsPaid', 0)['state'], 'The operator still manages it');
    }

    private function inWorkspace(int $tenantId): void
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
    }

    private function call(string $method): \Whity\Sdk\Http\Response
    {
        $match = $this->router->match(new Request($method, '/api/v1/wspaid/stock'));
        $this->assertNotNull($match);

        return ($match['handler'])(new Request($method, '/api/v1/wspaid/stock'), $match['params']);
    }

    private function request(string $method): Request
    {
        $request = new Request($method, '/api/workspace/code-plugins');
        $request->user = (object) ['profile_id' => null];

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
