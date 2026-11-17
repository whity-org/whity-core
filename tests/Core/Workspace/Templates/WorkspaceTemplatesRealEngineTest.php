<?php

declare(strict_types=1);

namespace Tests\Core\Workspace\Templates;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\WorkspaceTemplatesApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Workspace\Templates\TemplateApplier;
use Whity\Core\Workspace\Templates\TemplateCatalogue;
use Whity\Core\Workspace\Templates\TemplateRuns;

/**
 * WHIT-1194 (SDK 1.116): a plugin's templates are checked one by one, listed
 * with core's Blank where the plugin is on, and offer the paid plugin's trial
 * only while it is installed and paid.
 */
final class WorkspaceTemplatesRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const AMRO = 7;

    private static string $dir;

    private PDO $pdo;
    private CodePluginActivation $activation;
    private PluginLoader $loader;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_ws_templates_' . uniqid();
        mkdir(self::$dir . '/WsTemplates', 0755, true);
        mkdir(self::$dir . '/WsCounter', 0755, true);
        file_put_contents(self::$dir . '/WsTemplates/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsTemplates;

use Tests\Support\ExampleBakeryTemplate;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginWorkspaceTemplatesInterface;

final class Plugin implements PluginInterface, PluginWorkspaceTemplatesInterface
{
    public function getName(): string { return 'WsTemplates'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getWorkspaceTemplates(): array
    {
        $bakery = ExampleBakeryTemplate::manifest();
        $bakery['offer']['plugin'] = 'WsCounter';
        $broken = ['key' => 'clinic', 'version' => 1, 'name' => ['en' => 'Clinic']];
        $blank = ['key' => 'blank'] + $bakery;

        return [$bakery, $broken, $blank];
    }
}
PHP);
        file_put_contents(self::$dir . '/WsCounter/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsCounter;

use Whity\Sdk\PluginAccessInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginAccessInterface
{
    public function getName(): string { return 'WsCounter'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getAccess(): ?array
    {
        return ['entitlement' => 'ws_counter.access', 'product' => 'plugin.ws-counter', 'trialDays' => 21];
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
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([self::AMRO]);
        $this->activation = new CodePluginActivation($this->pdo);
        $this->loader = new PluginLoader(self::$dir, new Router(), new PermissionRegistry(), new HookManager());
        $this->loader->setWorkspaceActivation($this->activation);
        $this->loader->load();
        $this->activation->usePaidAccess(fn (): array => $this->loader->getPaidAccess());
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testValidTemplatesAreListedWithBlankAndOfferTheTrial(): void
    {
        $data = $this->list('ar');

        self::assertSame(['bakery', 'blank'], array_column($data['templates'], 'key'), 'The broken one is dropped and no plugin replaces Blank');
        $bakery = $data['templates'][0];
        self::assertSame('WsTemplates', $bakery['plugin']);
        self::assertSame('مخبز أو مقهى', $bakery['name']);
        self::assertSame(['قائمتك بالأسعار', 'نموذج طلب كيك'], $bakery['setsUp']);
        self::assertTrue($bakery['hasExamples']);
        self::assertSame('WsCounter', $bakery['offer']['plugin']);
        self::assertSame(30, $bakery['offer']['trialDays'], 'The template\'s own trial, since the plugin offers one');
        self::assertTrue($bakery['offer']['trialAvailable']);
        self::assertSame(['الطلبات عند الكاونتر برمز QR'], $bakery['offer']['paid']);
        self::assertNull($data['templates'][1]['plugin']);
        self::assertNull($data['templates'][1]['offer']);
        self::assertSame([], $data['chosen']);
    }

    public function testATemplateIsOnlyOfferedWhereItsPluginIsOn(): void
    {
        $this->activation->setAccess('WsTemplates', CodePluginActivation::CHOOSE);

        self::assertSame(['blank'], array_column($this->list('en')['templates'], 'key'));
    }

    public function testChoosingBlankIsRemembered(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $request = new Request('POST', '/api/v1/workspace/templates/blank/apply', ['content-type' => 'application/json'], '{}');
        $request->user = (object) ['profile_id' => self::AMRO];

        $response = $this->handler('en')->apply($request, ['key' => 'blank']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['blank'], $this->list('en')['chosen']);
        self::assertSame(404, $this->handler('en')->apply($request, ['key' => 'nowhere'])->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function list(string $language): array
    {
        if (TenantContext::getTenantId() === null) {
            TenantContext::setTenantId(self::BAKERY);
        }
        $response = $this->handler($language)->list(new Request('GET', '/api/v1/workspace/templates'));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);

        return $body['data'];
    }

    private function handler(string $language): WorkspaceTemplatesApiHandler
    {
        $runs = new TemplateRuns($this->pdo);

        return new WorkspaceTemplatesApiHandler(
            new TemplateCatalogue($this->loader),
            $runs,
            static fn (): TemplateApplier => new TemplateApplier(new FakeSetup(), $runs, static fn (): bool => true),
            $this->activation,
            null,
            static fn (): string => $language,
        );
    }
}
