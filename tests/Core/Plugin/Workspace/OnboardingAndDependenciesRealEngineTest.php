<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Workspace;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\WorkspaceCodePluginsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-1148: a guided setup (SDK 1.109) is checked like a blocks screen and
 * served per workspace, in the reader's language; turning a plugin on turns on
 * what it depends on, and what another plugin needs can't be turned off.
 */
final class OnboardingAndDependenciesRealEngineTest extends TestCase
{
    private const BAKERY = 1;

    private static string $dir;

    private PDO $pdo;
    private CodePluginActivation $activation;
    private PluginLoader $loader;
    /** @var list<string> */
    private array $granted = ['wsguided:setup'];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_ws_guided_' . uniqid();
        mkdir(self::$dir . '/WsBase', 0755, true);
        mkdir(self::$dir . '/WsGuided', 0755, true);
        file_put_contents(self::$dir . '/WsBase/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsBase;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'WsBase'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getHooks(): array { return []; }
}
PHP);
        file_put_contents(self::$dir . '/WsGuided/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsGuided;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginOnboardingInterface;
use Whity\Sdk\PluginRequirementsInterface;

final class Plugin implements PluginInterface, PluginOnboardingInterface, PluginRequirementsInterface
{
    public function getName(): string { return 'WsGuided'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return ['wsguided:setup']; }
    public function getHooks(): array { return []; }
    public function getSdkConstraint(): string { return ''; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return ['WsBase' => '^1.0']; }
    public function getRoutes(): array
    {
        $ok = static fn (Request $r): Response => Response::json(['data' => ['steps' => ['details' => true, 'stray' => false]]]);

        return [
            ['method' => 'GET', 'path' => '/api/wsguided/onboarding', 'handler' => $ok, 'requiredRole' => null, 'requiredPermission' => 'wsguided:setup'],
            ['method' => 'POST', 'path' => '/api/wsguided/details', 'handler' => $ok, 'requiredRole' => null, 'requiredPermission' => 'wsguided:setup'],
        ];
    }
    public function getOnboarding(): array
    {
        $form = static fn (string $endpoint): array => [[
            'type' => 'form',
            'requiredPermission' => 'wsguided:setup',
            'submit' => ['method' => 'POST', 'endpoint' => $endpoint],
            'children' => [
                ['type' => 'textInput', 'name' => 'name', 'label' => ['en' => 'Business name', 'ar' => 'اسم العمل']],
                ['type' => 'submitButton', 'label' => ['en' => 'Save', 'ar' => 'حفظ'], 'requiredPermission' => 'wsguided:setup'],
            ],
        ]];

        return [
            'title' => ['en' => 'Set up your salon', 'ar' => 'إعداد صالونك'],
            'permission' => 'wsguided:setup',
            'status' => '/api/wsguided/onboarding',
            'steps' => [
                ['id' => 'details', 'title' => ['en' => 'Your business', 'ar' => 'عملك'], 'blocks' => $form('/api/wsguided/details')],
                // Posts to a route the plugin doesn't own: dropped.
                ['id' => 'stray', 'title' => ['en' => 'Stray', 'ar' => 'شارد'], 'blocks' => $form('/api/users')],
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
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery')");
        // The person turning plugins on: history rows point at a real profile (Postgres checks it).
        $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES (7, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $this->activation = new CodePluginActivation($this->pdo);
        $this->loader = new PluginLoader(self::$dir, new Router(), new PermissionRegistry(), new HookManager());
        $this->loader->setWorkspaceActivation($this->activation);
        $this->loader->load();
        $this->activation->setAccess('WsBase', CodePluginActivation::CHOOSE);
        $this->activation->setAccess('WsGuided', CodePluginActivation::CHOOSE);
        TenantContext::setTenantId(self::BAKERY);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testTurningAPluginOnTurnsOnWhatItNeedsAndThatCannotGoFirst(): void
    {
        $api = $this->api('en');

        $this->assertSame(['WsBase'], $this->loader->workspaceDependenciesOf('WsGuided'));
        $this->assertSame(200, $api->enable($this->request(), ['plugin' => 'WsGuided'])->getStatusCode());
        $this->assertTrue($this->activation->isOn('WsBase', self::BAKERY), 'Its dependency came on with it');

        $refused = $api->disable($this->request(), ['plugin' => 'WsBase']);
        $this->assertSame(409, $refused->getStatusCode());
        $this->assertStringContainsString('required_by', $refused->getBody());

        $this->assertSame(200, $api->disable($this->request(), ['plugin' => 'WsGuided'])->getStatusCode());
        $this->assertSame(200, $api->disable($this->request(), ['plugin' => 'WsBase'])->getStatusCode());
    }

    public function testADependencyKeptToThePlatformStopsTheSwitch(): void
    {
        $this->activation->setAccess('WsBase', CodePluginActivation::OPERATOR);
        $refused = $this->api('en')->enable($this->request(), ['plugin' => 'WsGuided']);

        $this->assertSame(409, $refused->getStatusCode());
        $this->assertStringContainsString('dependency_unavailable', $refused->getBody());
        $this->assertFalse($this->activation->isVisible('WsGuided', self::BAKERY));
    }

    public function testTheGuidedSetupIsCheckedServedInTheReadersLanguageAndOnlyToWhoMayRunIt(): void
    {
        $this->activation->setEnabled(self::BAKERY, 'WsGuided', true);
        $body = json_decode($this->api('ar')->onboarding($this->request())->getBody(), true);
        $this->assertIsArray($body);
        $setup = $body['data'][0];

        $this->assertSame('WsGuided', $setup['plugin']);
        $this->assertSame('إعداد صالونك', $setup['title']);
        $this->assertSame('/api/v1/wsguided/onboarding', $setup['status']);
        $this->assertSame(['details'], array_column($setup['steps'], 'id'), 'A step posting outside the plugin is dropped');
        $this->assertSame('/api/v1/wsguided/details', $setup['steps'][0]['blocks'][0]['submit']['endpoint']);
        $this->assertSame('اسم العمل', $setup['steps'][0]['blocks'][0]['children'][0]['label']);

        $this->granted = [];
        $none = json_decode($this->api('en')->onboarding($this->request())->getBody(), true);
        $this->assertIsArray($none);
        $this->assertSame([], $none['data'], 'Not offered to someone without the setup permission');

        $this->activation->setEnabled(self::BAKERY, 'WsGuided', false);
        $this->granted = ['wsguided:setup'];
        $off = json_decode($this->api('en')->onboarding($this->request())->getBody(), true);
        $this->assertIsArray($off);
        $this->assertSame([], $off['data'], 'Nothing from a plugin that is off here');
    }

    private function api(string $language): WorkspaceCodePluginsApiHandler
    {
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            fn (int $profileId, string $permission, int $tenantId): bool => in_array($permission, $this->granted, true)
        );

        return new WorkspaceCodePluginsApiHandler($this->loader, $this->activation, $this->pdo, new AuditLogger($this->pdo), $roles, static fn (): string => $language);
    }

    private function request(): Request
    {
        $request = new Request('POST', '/api/workspace/code-plugins');
        $request->user = (object) ['profile_id' => 7];

        return $request;
    }
}
