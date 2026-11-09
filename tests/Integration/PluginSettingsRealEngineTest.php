<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\HostPluginSettings;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\PluginSettingsRegistry;
use Whity\Core\Settings\SettingsCatalog;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Settings\PluginSettingsStore;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A plugin's own settings for the current workspace (WHIT-1016).
 *
 * Runs on real Postgres in CI (PHPUNIT_PG_DSN), SQLite locally.
 */
final class PluginSettingsRealEngineTest extends TestCase
{
    private const BAKERY = 11;
    private const FLORIST = 22;

    private PDO $pdo;
    private HookManager $hooks;
    private SettingsService $service;
    private GlobalSettingsRepository $globals;
    private PluginInterface $acme;
    private PluginSettingsStore $settings;

    /** @var list<array<string, mixed>> */
    private array $heard = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        AuditContext::reset();
        $this->pdo = SchemaFromMigrations::make();
        foreach ([self::BAKERY => 'Example Bakery', self::FLORIST => 'Example Florist'] as $id => $name) {
            $this->pdo->prepare('INSERT INTO tenants (id, name, slug) VALUES (?, ?, ?) ON CONFLICT DO NOTHING')
                ->execute([$id, $name, 'tenant-' . $id]);
        }

        $registry = new PluginSettingsRegistry();
        $registry->register('Acme', [
            'sync_interval' => ['type' => 'int', 'default' => 300, 'min' => 60, 'max' => 86400],
            'verbose'       => ['type' => 'bool', 'default' => false],
            'mode'          => ['type' => 'enum', 'options' => ['off', 'live'], 'default' => 'off'],
            'region'        => ['type' => 'string', 'default' => 'eu', 'global_only' => true],
        ]);
        $registry->register('Rival', ['token_hint' => ['type' => 'string', 'default' => 'rival-default']]);

        $this->hooks = new HookManager();
        $this->hooks->listen(Events::PLUGIN_SETTINGS_CHANGED, function (array $payload): array {
            $this->heard[] = $payload;
            return $payload;
        });
        $this->globals = new GlobalSettingsRepository($this->pdo);
        $this->service = new SettingsService($this->globals, new TenantSettingsRepository($this->pdo), new SettingsCatalog($registry), $this->hooks);

        $this->acme = self::plugin('Acme');
        $rival = self::plugin('Rival');
        $this->settings = (new HostPluginSettings($this->service, fn (): array => [$this->acme, $rival]))->for($this->acme);

        TenantContext::setTenantId(self::BAKERY);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    public function testOneWorkspaceCannotReadAnothersSettings(): void
    {
        $this->settings->set('sync_interval', 900);

        $this->inWorkspace(self::FLORIST);
        self::assertSame(300, $this->settings->get('sync_interval'), 'the florist sees the default, not the bakery value');
        self::assertSame(300, $this->settings->all()['sync_interval']);

        $this->settings->set('sync_interval', 120);
        $this->inWorkspace(self::BAKERY);
        self::assertSame(900, $this->settings->get('sync_interval'), 'the florist writing did not touch the bakery');
    }

    public function testResolvesOwnThenDeploymentThenDefault(): void
    {
        self::assertSame(300, $this->settings->get('sync_interval'));

        $this->service->setGlobal('acme:sync_interval', '600');
        self::assertSame(600, $this->settings->get('sync_interval'));

        $this->settings->set('sync_interval', 900);
        self::assertSame(900, $this->settings->get('sync_interval'));

        $this->settings->reset('sync_interval');
        self::assertSame(600, $this->settings->get('sync_interval'));
    }

    public function testValuesComeBackInTheirDeclaredTypes(): void
    {
        $this->settings->set('verbose', true);
        $this->settings->set('mode', 'live');

        self::assertSame(
            ['sync_interval' => 300, 'verbose' => true, 'mode' => 'live', 'region' => 'eu'],
            $this->settings->all(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function keysOutOfReach(): iterable
    {
        yield 'a core key' => ['site_name'];
        yield 'another plugin, bare' => ['token_hint'];
        yield 'another plugin, namespaced' => ['rival:token_hint'];
        yield 'undeclared' => ['nope'];
    }

    #[DataProvider('keysOutOfReach')]
    public function testOnlyTheKeysItDeclaredResolve(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->get($key);
    }

    #[DataProvider('keysOutOfReach')]
    public function testOnlyTheKeysItDeclaredCanBeWritten(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set($key, 'x');
    }

    public function testAllNeverIncludesAnotherPluginsOrCoreKeys(): void
    {
        self::assertSame(['sync_interval', 'verbose', 'mode', 'region'], array_keys($this->settings->all()));
    }

    public function testAValueTheDeclarationRefusesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set('sync_interval', 5);
    }

    public function testADeploymentWideKeyCannotBeSetForAWorkspace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set('region', 'us');
    }

    public function testNoWorkspaceIsRefused(): void
    {
        TenantContext::reset();
        $this->expectException(\RuntimeException::class);
        $this->settings->get('sync_interval');
    }

    public function testTheSystemWorkspaceIsRefused(): void
    {
        $this->inWorkspace(0);
        $this->expectException(\RuntimeException::class);
        $this->settings->all();
    }

    public function testAStubClaimingAnotherPluginsNameGetsNothing(): void
    {
        $service = new HostPluginSettings($this->service, fn (): array => [$this->acme]);
        $this->expectException(\InvalidArgumentException::class);
        $service->for(new class ('Acme') extends StubPlugin {
        });
    }

    public function testAChangeIsAnnouncedByKeyNeverByValue(): void
    {
        $this->settings->set('sync_interval', 900);

        self::assertCount(1, $this->heard);
        self::assertSame(
            ['tenant_id' => self::BAKERY, 'plugin' => 'acme', 'key' => 'sync_interval', 'actor_profile_id' => null],
            $this->heard[0],
        );
    }

    public function testNoChangeIsNotAnnounced(): void
    {
        $this->settings->set('sync_interval', 900);
        $this->settings->set('sync_interval', 900);

        self::assertCount(1, $this->heard);
    }

    public function testAChangeFromASettingsScreenIsAnnouncedToo(): void
    {
        AuditContext::set(77, null);

        $this->service->setGlobal('acme:mode', 'live');

        self::assertSame(['tenant_id' => null, 'plugin' => 'acme', 'key' => 'mode', 'actor_profile_id' => 77], $this->heard[0]);
    }

    public function testCoreKeysAreNotAnnounced(): void
    {
        $this->service->setTenant(self::BAKERY, 'site_name', 'Example Bakery');

        self::assertSame([], $this->heard);
    }

    public function testAListenerCannotStopTheChange(): void
    {
        $this->hooks->listen(Events::PLUGIN_SETTINGS_CHANGED, static function (): array {
            throw new HookVetoException('no');
        });

        $this->settings->set('sync_interval', 900);

        self::assertSame(900, $this->settings->get('sync_interval'));
    }

    private function inWorkspace(int $id): void
    {
        TenantContext::reset();
        TenantContext::setTenantId($id);
    }

    private static function plugin(string $name): PluginInterface
    {
        return new StubPlugin($name);
    }
}

/** @internal a minimal loaded plugin */
class StubPlugin implements PluginInterface
{
    public function __construct(private readonly string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getRoutes(): array
    {
        return [];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [];
    }
}
