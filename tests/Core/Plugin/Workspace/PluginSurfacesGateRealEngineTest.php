<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Workspace;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\CalendarPlugins;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\Plugin\Workspace\PluginWorkspaceGate;
use Whity\Core\PluginLoader;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\JobRunner;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\JobInterface;

/**
 * WHIT-1123: a plugin switched off for a workspace is gone from every surface
 * there: hooks, queued jobs, MCP toolsets, and what it added to the
 * registries. The surfaces read one gate, which the loader implements.
 */
final class PluginSurfacesGateRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const CAFE = 2;

    private static string $dir;

    private PDO $pdo;
    private CodePluginActivation $activation;
    private PluginLoader $loader;
    private HookManager $hooks;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_ws_surfaces_' . uniqid();
        mkdir(self::$dir . '/WsBakes', 0755, true);
        file_put_contents(self::$dir . '/WsBakes/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsBakes;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'WsBakes'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getHooks(): array
    {
        return [
            'order.placed' => static function (array $data): array {
                $data['seen_by'][] = 'WsBakes';

                return $data;
            },
        ];
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/WsBakes/Plugin.php');
        @rmdir(self::$dir . '/WsBakes');
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery'), (2, 'example-cafe')");

        $this->activation = new CodePluginActivation($this->pdo);
        $this->activation->setAccess('WsBakes', CodePluginActivation::CHOOSE);
        $this->activation->setEnabled(self::BAKERY, 'WsBakes', true);

        $this->hooks = new HookManager();
        $this->loader = new PluginLoader(self::$dir, new Router(), new PermissionRegistry(), $this->hooks);
        $this->loader->setWorkspaceActivation($this->activation);
        $this->loader->load();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAHookListenerRunsOnlyWhereThePluginIsOn(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $this->assertSame(['WsBakes'], $this->hooks->dispatch('order.placed', [])['seen_by'] ?? null);

        TenantContext::reset();
        TenantContext::setTenantId(self::CAFE);
        $this->assertArrayNotHasKey('seen_by', $this->hooks->dispatch('order.placed', []));
    }

    public function testTheGateAnswersByNameClassAndToolset(): void
    {
        $this->assertFalse($this->loader->isPluginOffForWorkspace('WsBakes', self::BAKERY));
        $this->assertTrue($this->loader->isPluginOffForWorkspace('WsBakes', self::CAFE));
        $this->assertTrue($this->loader->isClassOffForWorkspace('WsBakes\\Orders\\Something', self::CAFE));
        $this->assertFalse($this->loader->isClassOffForWorkspace('Whity\\Core\\Something', self::CAFE));
        $this->assertTrue($this->loader->isToolsetOffForWorkspace('ws-bakes', self::CAFE));
        $this->assertTrue($this->loader->isToolsetOffForWorkspace('ws-bakes.orders', self::CAFE));
        $this->assertFalse($this->loader->isToolsetOffForWorkspace('ws-bakes', self::BAKERY));
        $this->assertFalse($this->loader->isToolsetOffForWorkspace('core.people', self::CAFE));
        $this->assertFalse($this->loader->isToolsetOffForWorkspace('ws-bakes', 0), 'The system workspace sees every plugin on');
    }

    public function testAQueuedJobOfAnOffPluginCompletesWithoutRunning(): void
    {
        $ran = new \ArrayObject();
        $registry = new JobRegistry();
        $registry->registerFromSource('WsBakes', ['bake' => new class ($ran) implements JobInterface {
            /** @param \ArrayObject<int, mixed> $ran */
            public function __construct(private readonly \ArrayObject $ran)
            {
            }

            public function handle(array $payload): array
            {
                $this->ran->append($payload['tenant'] ?? null);

                return ['baked' => true];
            }
        }]);
        $repo = new JobRepository($this->pdo);
        $name = array_values(array_filter($registry->names(), static fn (string $n): bool => str_ends_with($n, ":bake")))[0];
        $repo->enqueue(self::CAFE, $name, ['tenant' => self::CAFE]);
        $repo->enqueue(self::BAKERY, $name, ['tenant' => self::BAKERY]);

        $runner = new JobRunner($repo, $registry);
        $runner->setWorkspaceActivation($this->activation);
        $runner->processNext();
        TenantContext::reset();
        $runner->processNext();
        TenantContext::reset();

        $this->assertSame([self::BAKERY], $ran->getArrayCopy());
    }

    public function testRegistriesHideWhatAnOffPluginAdded(): void
    {
        $gate = new class implements PluginWorkspaceGate {
            public function isPluginOffForWorkspace(string $pluginName, ?int $tenantId = null): bool
            {
                return $pluginName === 'WsBakes';
            }

            public function isOffForWorkspace(string $pluginKey, ?int $tenantId = null): bool
            {
                return str_starts_with($pluginKey, 'WsBakes\\');
            }

            public function isToolsetOffForWorkspace(?string $toolset, ?int $tenantId = null): bool
            {
                return false;
            }

            public function isClassOffForWorkspace(string $class, ?int $tenantId = null): bool
            {
                return false;
            }
        };

        $calendar = new CalendarPlugins();
        $step = $this->createStub(\Whity\Sdk\Calendar\BookingPaymentStep::class);
        $calendar->offerPaymentStep('WsBakes', $step);
        $this->assertSame($step, $calendar->paymentStep());
        $calendar->setWorkspaceGate($gate);
        $this->assertNull($calendar->paymentStep(), 'An off plugin takes no booking payments');

        $channels = new MessageChannelRegistry();
        $channel = $this->createStub(\Whity\Sdk\Messaging\MessageChannel::class);
        $channel->method('key')->willReturn('sms');
        $channels->register('WsBakes', [$channel]);
        $this->assertNotSame([], $channels->keys());
        $channels->setWorkspaceGate($gate);
        $this->assertSame([], $channels->keys());
        $this->assertNull($channels->get('wsbakes:sms'));
    }
}
