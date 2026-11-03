<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\AddHomeCardHidden;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\HomeCardsApiHandler;
use Whity\Auth\RoleChecker;
use Tests\Sdk\Manifest\RecordTypeValidatorTest;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Core\RBAC\PermissionRegistry;

/**
 * The cards apps add to the home (WHIT-872, H.4): only with the permission, hiding is
 * per person and per workspace, and a workspace plugin's card counts that workspace's
 * records alone.
 */
final class HomeCardsApiRealEngineTest extends TestCase
{
    private const RANA = 101;
    private const OMAR = 202;
    private const BAKERY = 1;
    private const PHARMACY = 2;

    private PDO $pdo;

    /** @var array<string, list<string>> "tenant:profile" => slugs held */
    private array $grants = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Pharmacy', 'pharmacy')");
        foreach ([self::RANA => 'Rana', self::OMAR => 'Omar'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
        $this->grants = [
            self::BAKERY . ':' . self::RANA => ['inventory:read', 'orders:read'],
            self::BAKERY . ':' . self::OMAR => [],
            self::PHARMACY . ':' . self::RANA => ['inventory:read'],
        ];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testACardNeedsItsPermissionInThisWorkspace(): void
    {
        self::assertSame(['Inventory'], array_column($this->cards(self::BAKERY, self::RANA), 'key'));
        self::assertSame([], $this->cards(self::BAKERY, self::OMAR), 'No permission, no card.');
    }

    public function testASourceCardNamesItsVersionedRouteAndWhereItOpens(): void
    {
        $card = $this->cards(self::BAKERY, self::RANA)[0];

        self::assertSame('/api/v1/inventory/home-card', $card['source']);
        self::assertSame('/admin/x/inventory', $card['href']);
        self::assertSame('المخزون', $card['labelAr']);
        self::assertFalse($card['hidden']);
    }

    public function testHidingIsPerPersonAndPerWorkspace(): void
    {
        $this->grants[self::BAKERY . ':' . self::OMAR] = ['inventory:read'];

        self::assertSame(200, $this->setHidden(self::BAKERY, self::RANA, 'Inventory', true));

        self::assertTrue($this->cards(self::BAKERY, self::RANA)[0]['hidden'], 'A hidden card is still listed, so Show can bring it back.');
        self::assertFalse($this->cards(self::BAKERY, self::OMAR)[0]['hidden'], 'A colleague keeps theirs.');
        self::assertFalse($this->cards(self::PHARMACY, self::RANA)[0]['hidden'], 'So does their other workspace.');

        self::assertSame(200, $this->setHidden(self::BAKERY, self::RANA, 'Inventory', true), 'Hiding twice is fine.');
        self::assertSame(200, $this->setHidden(self::BAKERY, self::RANA, 'Inventory', false));
        self::assertFalse($this->cards(self::BAKERY, self::RANA)[0]['hidden']);
    }

    public function testACardTheCallerCannotSeeCannotBeHidden(): void
    {
        self::assertSame(404, $this->setHidden(self::BAKERY, self::OMAR, 'Inventory', true));
        self::assertSame(404, $this->setHidden(self::BAKERY, self::RANA, 'NoSuchPlugin', true));

        self::assertSame(0, $this->hiddenRows());
    }

    public function testHiddenMustBeABoolean(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $request = $this->request(self::RANA, '{"hidden":"yes"}');

        self::assertSame(422, $this->handler()->setHidden($request, ['key' => 'Inventory'])->getStatusCode());
    }

    public function testAWorkspacePluginsCardCountsOnlyItsOwnWorkspacesRecords(): void
    {
        $this->publishWorkspacePlugin(['record' => 'order']);
        $records = new TenantRecordRepository($this->pdo);
        $plugin = RecordTypeValidatorTest::manifest()['plugin']['name'];
        $records->create(self::BAKERY, $plugin, 'order', null, [], self::RANA);
        $records->create(self::BAKERY, $plugin, 'order', null, [], self::RANA);
        $records->create(self::PHARMACY, $plugin, 'order', null, [], self::RANA);
        $this->grants[self::BAKERY . ':' . self::RANA][] = self::recordPermission();

        $card = $this->cards(self::BAKERY, self::RANA)[1];

        self::assertSame($plugin, $card['key']);
        self::assertSame(2, $card['value']);
        self::assertNull($card['source']);
        self::assertSame('orders waiting', $card['caption']);
    }

    public function testAWorkspacePluginCannotPointAtARoute(): void
    {
        $this->publishWorkspacePlugin(['source' => '/api/users']);
        $this->grants[self::BAKERY . ':' . self::RANA][] = self::recordPermission();

        self::assertSame(['Inventory'], array_column($this->cards(self::BAKERY, self::RANA), 'key'));
    }

    public function testAnUnauthenticatedCallerIsRefused(): void
    {
        TenantContext::setTenantId(self::BAKERY);

        self::assertSame(401, $this->handler()->list(new Request('GET', '/api/me/home-cards'))->getStatusCode());
    }

    public function testTheMigrationGoesBothWays(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/200_add_home_card_hidden.php';
        $pdo = $this->pdo;
        $db = Database::withFactory(static fn (): PDO => $pdo);

        AddHomeCardHidden::down($db);
        $this->assertTableMissing('home_card_hidden');

        AddHomeCardHidden::up($db);
        AddHomeCardHidden::up($db);
        self::assertSame(0, $this->hiddenRows());
    }

    private function hiddenRows(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM home_card_hidden');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function assertTableMissing(string $table): void
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$table}");
        } catch (\PDOException) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail("{$table} should be gone.");
    }

    /** @return list<array<string, mixed>> */
    private function cards(int $tenantId, int $profileId): array
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $response = $this->handler()->list($this->request($profileId));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['data'];
    }

    private function setHidden(int $tenantId, int $profileId, string $key, bool $hidden): int
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        return $this->handler()
            ->setHidden($this->request($profileId, $hidden ? '{"hidden":true}' : '{"hidden":false}'), ['key' => $key])
            ->getStatusCode();
    }

    private function request(int $profileId, string $body = ''): Request
    {
        $request = new Request('PUT', '/api/me/home-cards', ['Content-Type' => 'application/json'], $body);
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    private function handler(): HomeCardsApiHandler
    {
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            fn (int $profile, string $slug, int $tenant): bool => in_array($slug, $this->grants["{$tenant}:{$profile}"] ?? [], true)
        );
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('getHomeCards')->willReturn([[
            'plugin' => 'Inventory',
            'label' => ['en' => 'Stock', 'ar' => 'المخزون'],
            'permission' => 'inventory:read',
            'source' => '/api/inventory/home-card',
            'screen' => 'inventory',
        ]]);
        return new HomeCardsApiHandler(
            $this->pdo,
            $roles,
            $loader,
            new TenantManifestHost(new TenantPluginRepository($this->pdo), new PermissionRegistry()),
            new TenantRecordRepository($this->pdo),
            new Router('/v1'),
        );
    }

    private static function recordPermission(): string
    {
        return RecordTypeValidatorTest::manifest()['records']['order']['permissions']['read'];
    }

    /** @param array<string, mixed> $data */
    private function publishWorkspacePlugin(array $data): void
    {
        $manifest = RecordTypeValidatorTest::manifest();
        $manifest['homeCard'] = $data + [
            'label' => ['en' => 'Orders', 'ar' => 'الطلبات'],
            'caption' => ['en' => 'orders waiting', 'ar' => 'طلبات تنتظر'],
            'permission' => self::recordPermission(),
        ];
        $plugins = new TenantPluginRepository($this->pdo);
        (new TenantManifestPublisher($this->pdo, $plugins, new PluginRoleSeeder($this->pdo), []))->publish(self::BAKERY, $manifest, self::RANA);
        (new TenantManifestPublisher($this->pdo, $plugins, new PluginRoleSeeder($this->pdo), []))->publish(self::PHARMACY, $manifest, self::RANA);
    }
}
