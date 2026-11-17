<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Workspace;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DashboardApiHandler;
use Whity\Api\HomeCardsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Dashboard\DashboardLayoutRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-1177 (SDK 1.115): a plugin's setup points and widgets are checked
 * against its own routes, steps and permissions, served per workspace and
 * permission in the reader's language, and arranged per person.
 */
final class DashboardRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const PHARMACY = 2;
    private const AMRO = 7;
    private const AWSS = 8;

    private static string $dir;

    private PDO $pdo;
    private CodePluginActivation $activation;
    private PluginLoader $loader;
    private Router $router;

    /** @var array<string, list<string>> "tenant:profile" => permissions held */
    private array $grants = [];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_ws_dashboard_' . uniqid();
        mkdir(self::$dir . '/WsDash', 0755, true);
        file_put_contents(self::$dir . '/WsDash/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsDash;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginDashboardInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginOnboardingInterface;

final class Plugin implements PluginInterface, PluginOnboardingInterface, PluginDashboardInterface
{
    public function getName(): string { return 'WsDash'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return ['wsdash:manage', 'wsdash:read']; }
    public function getHooks(): array { return []; }
    public function getRoutes(): array
    {
        $ok = static fn (Request $r): Response => Response::json(['data' => []]);
        $route = static fn (string $method, string $path, string $permission): array => ['method' => $method, 'path' => $path, 'handler' => $ok, 'requiredRole' => null, 'requiredPermission' => $permission];

        return [
            $route('GET', '/api/wsdash/setup', 'wsdash:manage'),
            $route('POST', '/api/wsdash/chairs', 'wsdash:manage'),
            $route('GET', '/api/wsdash/today', 'wsdash:read'),
            $route('GET', '/api/wsdash/money', 'wsdash:manage'),
            $route('POST', '/api/wsdash/walk-in', 'wsdash:manage'),
        ];
    }
    public function getOnboarding(): array
    {
        return [
            'title' => ['en' => 'Set up your salon', 'ar' => 'إعداد صالونك'],
            'permission' => 'wsdash:manage',
            'status' => '/api/wsdash/setup',
            'steps' => [[
                'id' => 'chairs',
                'title' => ['en' => 'Chairs', 'ar' => 'الكراسي'],
                'blocks' => [['type' => 'form', 'requiredPermission' => 'wsdash:manage', 'submit' => ['method' => 'POST', 'endpoint' => '/api/wsdash/chairs'], 'children' => [
                    ['type' => 'numberInput', 'name' => 'chairs', 'label' => ['en' => 'Chairs', 'ar' => 'الكراسي']],
                    ['type' => 'submitButton', 'label' => ['en' => 'Save', 'ar' => 'حفظ'], 'requiredPermission' => 'wsdash:manage'],
                ]]],
            ]],
        ];
    }
    public function getDashboard(): array
    {
        $list = static fn (string $id, string $source, string $permission): array => [
            'id' => $id,
            'kind' => 'list',
            'title' => ['en' => "Today's bookings", 'ar' => 'حجوزات اليوم'],
            'permission' => $permission,
            'source' => $source,
            'blocks' => [['type' => 'dataList', 'source' => $source, 'itemField' => 'line', 'emptyText' => ['en' => 'None today', 'ar' => 'لا شيء اليوم']]],
        ];

        return [
            'setup' => [
                'permission' => 'wsdash:manage',
                'status' => '/api/wsdash/setup',
                'points' => [
                    ['id' => 'chairs', 'label' => ['en' => 'Add your chairs', 'ar' => 'أضف الكراسي'], 'step' => 'chairs'],
                    // Not a step of its guided setup: dropped.
                    ['id' => 'stray', 'label' => ['en' => 'Stray', 'ar' => 'شارد'], 'step' => 'nowhere'],
                ],
            ],
            'widgets' => [
                $list('todays-bookings', '/api/wsdash/today', 'wsdash:read'),
                // A route that isn't the plugin's: dropped.
                $list('users', '/api/users', 'wsdash:read'),
                // Its own route, but gated on another permission: dropped.
                $list('money', '/api/wsdash/money', 'wsdash:read'),
                [
                    'id' => 'walk-in',
                    'kind' => 'actions',
                    'title' => ['en' => 'Walk-ins', 'ar' => 'الزبائن بدون موعد'],
                    'permission' => 'wsdash:manage',
                    'sizes' => ['small'],
                    'blocks' => [['type' => 'actionButton', 'label' => ['en' => 'Add a walk-in', 'ar' => 'إضافة زبون'], 'requiredPermission' => 'wsdash:manage', 'action' => ['method' => 'POST', 'endpoint' => '/api/wsdash/walk-in']]],
                ],
            ],
        ];
    }
}
PHP);
    }

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery'), (2, 'example-pharmacy')");
        foreach ([self::AMRO => 'Amro Saleh', self::AWSS => 'Awss Haddad'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }

        $this->activation = new CodePluginActivation($this->pdo);
        $this->router = new Router();
        $this->loader = new PluginLoader(self::$dir, $this->router, new PermissionRegistry(), new HookManager());
        $this->loader->setWorkspaceActivation($this->activation);
        $this->loader->load();
        $this->activation->setAccess('WsDash', CodePluginActivation::CHOOSE);
        $this->activation->setEnabled(self::BAKERY, 'WsDash', true);

        $this->grants = [
            self::BAKERY . ':' . self::AMRO => ['wsdash:manage', 'wsdash:read'],
            self::BAKERY . ':' . self::AWSS => ['wsdash:read'],
            self::PHARMACY . ':' . self::AMRO => ['wsdash:manage', 'wsdash:read'],
        ];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testOnlyWhatThePluginOwnsIsServedInTheReadersLanguage(): void
    {
        $dashboard = $this->dashboard(self::BAKERY, self::AMRO, 'ar');

        $setup = $dashboard['setup'][0];
        self::assertSame('WsDash', $setup['plugin']);
        self::assertSame('/api/v1/wsdash/setup', $setup['status']);
        self::assertSame(['chairs'], array_column($setup['points'], 'id'), 'A point naming no step of its setup is dropped');
        self::assertSame('أضف الكراسي', $setup['points'][0]['label']);
        self::assertSame('/admin/setup/WsDash?step=chairs', $setup['points'][0]['href']);

        self::assertSame(['WsDash:todays-bookings', 'WsDash:walk-in'], array_column($dashboard['widgets'], 'key'), 'Foreign and mis-gated sources are dropped');
        $list = $dashboard['widgets'][0];
        self::assertSame('حجوزات اليوم', $list['title']);
        self::assertSame('/api/v1/wsdash/today', $list['source']);
        self::assertSame('/api/v1/wsdash/today', $list['blocks'][0]['source']);
        self::assertSame('لا شيء اليوم', $list['blocks'][0]['emptyText']);
        self::assertSame(['medium', 'large'], $list['sizes']);
        self::assertSame('medium', $list['size']);
        self::assertFalse($list['hidden']);
        self::assertSame('/api/v1/wsdash/walk-in', $dashboard['widgets'][1]['blocks'][0]['action']['endpoint']);
    }

    public function testEachPartNeedsItsOwnPermission(): void
    {
        $dashboard = $this->dashboard(self::BAKERY, self::AWSS, 'en');

        self::assertSame([], $dashboard['setup'], 'Setup points are for people who may set the plugin up');
        self::assertSame(['WsDash:todays-bookings'], array_column($dashboard['widgets'], 'key'));
    }

    public function testNothingComesFromAPluginThatIsOffInTheWorkspace(): void
    {
        $dashboard = $this->dashboard(self::PHARMACY, self::AMRO, 'en');

        self::assertSame([], $dashboard['setup']);
        self::assertSame([], $dashboard['widgets']);
    }

    public function testAPersonArrangesTheirOwnDashboard(): void
    {
        $saved = $this->saveLayout(self::BAKERY, self::AMRO, ['items' => [
            ['key' => 'WsDash:walk-in'],
            ['key' => 'WsDash:todays-bookings', 'hidden' => true, 'size' => 'large'],
        ]]);
        self::assertSame(200, $saved[0]);
        self::assertSame(['WsDash:walk-in', 'WsDash:todays-bookings'], array_column($saved[1]['data']['widgets'], 'key'));
        self::assertTrue($saved[1]['data']['widgets'][1]['hidden']);
        self::assertSame('large', $saved[1]['data']['widgets'][1]['size']);

        // Theirs alone: someone else, and the same person elsewhere, keep the apps' order.
        self::assertFalse($this->dashboard(self::BAKERY, self::AWSS, 'en')['widgets'][0]['hidden']);
        $this->activation->setEnabled(self::PHARMACY, 'WsDash', true);
        self::assertSame('WsDash:todays-bookings', $this->dashboard(self::PHARMACY, self::AMRO, 'en')['widgets'][0]['key']);
    }

    public function testALayoutNamesOnlyWhatIsOnTheCallersDashboard(): void
    {
        self::assertSame(422, $this->saveLayout(self::BAKERY, self::AWSS, ['items' => [['key' => 'WsDash:walk-in']]])[0], 'A widget they may not see');
        self::assertSame(422, $this->saveLayout(self::BAKERY, self::AMRO, ['items' => [['key' => 'WsDash:walk-in', 'size' => 'large']]])[0], 'A size it does not fit');
        self::assertSame(422, $this->saveLayout(self::BAKERY, self::AMRO, ['items' => [['key' => 'WsDash:walk-in'], ['key' => 'WsDash:walk-in']]])[0]);
        self::assertSame(422, $this->saveLayout(self::BAKERY, self::AMRO, ['items' => 'all'])[0]);
    }

    public function testAnUnauthenticatedCallerIsRefused(): void
    {
        TenantContext::setTenantId(self::BAKERY);

        self::assertSame(401, $this->handler('en')->show(new Request('GET', '/api/me/dashboard'))->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function dashboard(int $tenantId, int $profileId, string $language): array
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $response = $this->handler($language)->show($this->request($profileId));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['data'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: mixed}
     */
    private function saveLayout(int $tenantId, int $profileId, array $body): array
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $response = $this->handler('en')->saveLayout($this->request($profileId, (string) json_encode($body)));

        return [$response->getStatusCode(), json_decode($response->getBody(), true)];
    }

    private function request(int $profileId, string $body = ''): Request
    {
        $request = new Request('PUT', '/api/me/dashboard/layout', ['Content-Type' => 'application/json'], $body);
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    private function handler(string $language): DashboardApiHandler
    {
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            fn (int $profile, string $slug, int $tenant): bool => in_array($slug, $this->grants["{$tenant}:{$profile}"] ?? [], true)
        );
        $cards = new HomeCardsApiHandler(
            $this->pdo,
            $roles,
            $this->loader,
            new TenantManifestHost(new TenantPluginRepository($this->pdo), new PermissionRegistry()),
            new TenantRecordRepository($this->pdo),
            $this->router,
        );

        return new DashboardApiHandler($roles, $this->loader, new DashboardLayoutRepository($this->pdo), $cards, static fn (): string => $language);
    }
}
