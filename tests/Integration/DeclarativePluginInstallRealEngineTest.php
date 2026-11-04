<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InstallFromStoreApiHandler;
use Whity\Api\TenantPluginVersionsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Plugin\Tenant\ManifestImpact;
use Whity\Core\Plugin\Tenant\ManifestTestRunner;
use Whity\Core\Plugin\Tenant\RecordWriter;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * A workspace installs a declarative plugin from a store (the owner's decision
 * 2): data, kept to one workspace, turned off, and held to the same policy as a
 * plugin written in that workspace. On the real schema (SQLite, or Postgres with
 * PHPUNIT_PG_DSN).
 */
final class DeclarativePluginInstallRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const CAFE = 2;

    private PDO $pdo;
    private TenantPluginRepository $plugins;
    private TenantPluginVersionsApiHandler $versions;
    private InstallFromStoreApiHandler $store;
    private ?string $served = null;

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::BAKERY);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->plugins = new TenantPluginRepository($this->pdo);
        $publisher = new TenantManifestPublisher($this->pdo, $this->plugins, new PluginRoleSeeder($this->pdo), []);

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setGlobal(SettingsRegistry::PLUGINS_STORE_ALLOWED_HOSTS, 'store.example.com');

        $this->store = new InstallFromStoreApiHandler(
            sys_get_temp_dir(),
            $settings,
            null,
            null,
            fn (string $url, array $headers): ?string => $this->served,
            static fn (string $url): array => ['data' => []],
            null,
            $publisher,
            $this->plugins,
        );

        $records = new TenantRecordRepository($this->pdo);
        $this->versions = new TenantPluginVersionsApiHandler(
            $this->plugins,
            $publisher,
            new ManifestImpact($records),
            new ManifestTestRunner(
                $this->pdo,
                $records,
                new RecordWriter($this->pdo, $records, new RoleChecker(Database::withFactory(fn (): PDO => $this->pdo), new PermissionRegistry()))
            ),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testItLandsTurnedOffAndServesNothingUntilTurnedOn(): void
    {
        $response = $this->install($this->manifest());

        self::assertSame(201, $response->getStatusCode(), $response->getBody());
        self::assertFalse(json_decode($response->getBody(), true)['data']['enabled']);
        self::assertSame([], $this->plugins->active(self::BAKERY));

        $on = $this->versions->enable(new Request('POST', '/x'), ['plugin' => 'cakeorders']);

        self::assertSame(200, $on->getStatusCode(), $on->getBody());
        self::assertCount(1, $this->plugins->active(self::BAKERY));

        $this->versions->disable(new Request('POST', '/x'), ['plugin' => 'cakeorders']);
        self::assertSame([], $this->plugins->active(self::BAKERY));
    }

    public function testAnotherWorkspaceCanNeitherSeeNorSwitchIt(): void
    {
        $this->install($this->manifest());

        TenantContext::reset();
        TenantContext::setTenantId(self::CAFE);

        self::assertSame([], $this->plugins->plugins(self::CAFE));
        self::assertSame(404, $this->versions->enable(new Request('POST', '/x'), ['plugin' => 'cakeorders'])->getStatusCode());
        self::assertSame([], $this->plugins->active(self::BAKERY), 'still off in the bakery');
    }

    public function testTheSystemTenantCannotInstallOne(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(0);

        self::assertSame(403, $this->install($this->manifest())->getStatusCode());
    }

    public function testAManifestCannotClaimAMarkOrTurnItselfOn(): void
    {
        $manifest = $this->manifest();
        $manifest['verified'] = true;
        $manifest['enabled'] = true;

        self::assertSame(422, $this->install($manifest)->getStatusCode());
        self::assertSame([], $this->plugins->plugins(self::BAKERY));
    }

    public function testTheWorkspacePolicyHolds(): void
    {
        $tables = $this->manifest();
        $tables['tables'] = ['users' => ['columns' => ['id' => ['type' => 'id']]]];
        self::assertSame(422, $this->install($tables)->getStatusCode());

        $foreign = $this->manifest();
        $foreign['permissions'] = [['slug' => 'users:delete', 'label' => ['en' => 'x', 'ar' => 'x']]];
        self::assertSame(422, $this->install($foreign)->getStatusCode());

        self::assertSame([], $this->plugins->plugins(self::BAKERY), 'nothing was stored');
    }

    public function testAWrongVersionIsRefused(): void
    {
        $manifest = $this->manifest();
        $manifest['plugin']['version'] = '9.9.9';

        self::assertSame(422, $this->install($manifest)->getStatusCode());
        self::assertSame([], $this->plugins->plugins(self::BAKERY));
    }

    public function testInstallingAgainDoesNotReplaceIt(): void
    {
        $this->install($this->manifest());
        $this->versions->enable(new Request('POST', '/x'), ['plugin' => 'cakeorders']);

        self::assertSame(409, $this->install($this->manifest())->getStatusCode());
        self::assertCount(1, $this->plugins->active(self::BAKERY), 'the running one stays on');
    }

    public function testNotJsonFromTheStoreIsRefused(): void
    {
        $this->served = '<?php echo "code";';

        $response = $this->store->installDeclarative($this->request());

        self::assertSame(502, $response->getStatusCode());
    }

    /** @param array<string, mixed> $manifest */
    private function install(array $manifest): Response
    {
        $this->served = (string) json_encode($manifest);

        return $this->store->installDeclarative($this->request());
    }

    private function request(): Request
    {
        return new Request('POST', '/api/workspace-plugins/install-from-store', [], (string) json_encode([
            'store_url' => 'https://store.example.com',
            'slug' => 'cake-orders',
            'version' => '1.0.0',
        ]));
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return [
            'manifest' => 'whity.plugin/1',
            'plugin' => [
                'name' => 'CakeOrders',
                'version' => '1.0.0',
                'label' => ['en' => 'Cake orders', 'ar' => 'طلبات الكعك'],
            ],
        ];
    }
}
