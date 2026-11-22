<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Plugin\HostPluginScope;
use Whity\Core\Plugin\HostWorkspaceTables;
use Whity\Core\Plugin\PluginCapabilities;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Context\Service;
use Whity\Sdk\Context\ServiceNotDeclared;
use Whity\Sdk\Contribute\PluginCapabilitiesInterface;
use Whity\Sdk\Database\TableRefused;
use Whity\Sdk\PluginInterface;

/**
 * Plugins reach only the services they declare, and their own tables only in the
 * workspace of the moment (#1693). The table handle runs real SQL on SQLite.
 */
final class PluginCapabilitiesRealEngineTest extends TestCase
{
    private PDO $pdo;

    /** @var array<string, mixed> */
    private array $savedServices = [];

    protected function setUp(): void
    {
        PluginCapabilities::reset();
        TenantContext::reset();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE keyhub_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
                ref TEXT, status TEXT, total INTEGER, paid BOOLEAN)'
        );
        $this->savedServices = $GLOBALS['whity_services'] ?? [];
        $pdo = $this->pdo;
        \Whity\register_service(Database::class, Database::withFactory(static fn (): PDO => $pdo));
    }

    protected function tearDown(): void
    {
        $GLOBALS['whity_services'] = $this->savedServices;
        PluginCapabilities::reset();
        TenantContext::reset();
    }

    /**
     * @param list<string>|null $services null for a plugin that declares nothing
     * @param list<string> $tables
     */
    private static function plugin(string $name, ?array $services, array $tables = []): PluginInterface
    {
        $base = new class ($name) implements PluginInterface {
            public function __construct(private string $name)
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

            public function getPermissions(): array
            {
                return [];
            }

            public function getHooks(): array
            {
                return [];
            }

            public function getMigrations(): array
            {
                return [];
            }
        };
        if ($services === null) {
            return $base;
        }

        return new class ($name, $services, $tables) implements PluginInterface, PluginCapabilitiesInterface {
            /**
             * @param list<string> $services
             * @param list<string> $tables
             */
            public function __construct(private string $name, private array $services, private array $tables)
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

            public function getPermissions(): array
            {
                return [];
            }

            public function getHooks(): array
            {
                return [];
            }

            public function getMigrations(): array
            {
                return [];
            }

            public function getServices(): array
            {
                return $this->services;
            }

            public function getTables(): array
            {
                return $this->tables;
            }
        };
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    private function tables(?int $workspace = 1): HostWorkspaceTables
    {
        $pdo = $this->pdo;

        return new HostWorkspaceTables('KeyHub', ['keyhub_orders'], static fn (): PDO => $pdo, static fn (): ?int => $workspace);
    }

    public function testADeclaringPluginGetsOnlyWhatItDeclared(): void
    {
        $scope = new HostPluginScope(self::plugin('KeyHub', [Service::HTTP, Service::DATABASE], ['keyhub_orders']));

        $scope->http();
        self::assertInstanceOf(HostWorkspaceTables::class, $scope->database());

        $this->expectException(ServiceNotDeclared::class);
        $this->expectExceptionMessage("'settings'");
        $scope->settings();
    }

    /** @return array<string, array{string}> */
    public static function undeclared(): array
    {
        return array_combine(
            array_diff(Service::all(), [Service::HTTP]),
            array_map(static fn (string $s): array => [$s], array_values(array_diff(Service::all(), [Service::HTTP])))
        );
    }

    #[DataProvider('undeclared')]
    public function testEveryUndeclaredServiceIsRefused(string $service): void
    {
        $scope = new HostPluginScope(self::plugin('KeyHub', [Service::HTTP]));

        $this->expectException(ServiceNotDeclared::class);
        $scope->{$service}();
    }

    public function testAPluginThatDeclaresNothingKeepsItsServicesButNotTheDatabase(): void
    {
        $scope = new HostPluginScope(self::plugin('Legacy', null));
        $scope->http();

        $this->expectException(ServiceNotDeclared::class);
        $scope->database();
    }

    public function testTablesThatAreNotThePluginsAreDropped(): void
    {
        $first = self::plugin('KeyHub', [Service::DATABASE], ['keyhub_orders', 'users', 'Bad-Name', 'tenants']);
        $second = self::plugin('Copycat', [Service::DATABASE, 'root'], ['keyhub_orders', 'copycat_rows']);

        $dropped = PluginCapabilities::register($first);
        self::assertCount(3, $dropped);
        self::assertSame(['services' => [Service::DATABASE], 'tables' => ['keyhub_orders']], PluginCapabilities::of('KeyHub'));

        $dropped = PluginCapabilities::register($second);
        self::assertContains("table 'keyhub_orders' was declared first by KeyHub", $dropped);
        self::assertContains("unknown service 'root'", $dropped);
        self::assertSame(['services' => [Service::DATABASE], 'tables' => ['copycat_rows']], PluginCapabilities::of('Copycat'));

        PluginCapabilities::forget('KeyHub');
        PluginCapabilities::register($second);
        self::assertSame(['services' => [Service::DATABASE], 'tables' => ['keyhub_orders', 'copycat_rows']], PluginCapabilities::of('Copycat'));
    }

    public function testEveryCallStaysInTheWorkspace(): void
    {
        $one = $this->tables(1);
        $two = $this->tables(2);

        $id = $one->insert('keyhub_orders', ['ref' => 'A-1', 'status' => 'open', 'total' => 100, 'paid' => false]);
        self::assertNotNull($id);
        $two->insert('keyhub_orders', ['ref' => 'B-1', 'status' => 'open', 'total' => 5, 'paid' => true]);

        self::assertSame(1, $one->count('keyhub_orders'));
        self::assertSame('A-1', $one->first('keyhub_orders', ['status' => 'open'])['ref'] ?? null);
        self::assertNull($one->first('keyhub_orders', ['ref' => 'B-1']));

        // Workspace 1 cannot touch workspace 2's row, even by its id.
        $twoId = (int) $this->scalar("SELECT id FROM keyhub_orders WHERE ref = 'B-1'");
        self::assertSame(0, $one->update('keyhub_orders', ['status' => 'void'], ['id' => $twoId]));
        self::assertSame(0, $one->delete('keyhub_orders', ['id' => $twoId]));
        self::assertSame('open', $two->first('keyhub_orders', ['id' => $twoId])['status'] ?? null);

        self::assertSame(1, $one->update('keyhub_orders', ['status' => 'paid', 'paid' => true], ['ref' => 'A-1']));
        self::assertSame(['A-1'], array_column($one->select('keyhub_orders', ['status' => ['paid', 'void']]), 'ref'));
        self::assertSame([], $one->select('keyhub_orders', ['status' => []]));
        self::assertSame(1, $one->delete('keyhub_orders', ['ref' => 'A-1']));
        self::assertSame(1, $two->count('keyhub_orders'));
    }

    public function testOrderLimitOffsetAndNulls(): void
    {
        $t = $this->tables();
        foreach ([3, 1, 2] as $n) {
            $t->insert('keyhub_orders', ['ref' => "R{$n}", 'total' => $n, 'status' => $n === 2 ? null : 'x']);
        }

        self::assertSame(['R3', 'R2'], array_column($t->select('keyhub_orders', [], ['total' => 'desc'], 2), 'ref'));
        self::assertSame(['R2', 'R3'], array_column($t->select('keyhub_orders', [], ['total' => 'asc'], null, 1), 'ref'));
        self::assertSame(['R2'], array_column($t->select('keyhub_orders', ['status' => null]), 'ref'));
    }

    /** @return array<string, array{\Closure(HostWorkspaceTables): mixed, string}> */
    public static function refusals(): array
    {
        return [
            'undeclared table' => [static fn (HostWorkspaceTables $t) => $t->select('users'), TableRefused::UNDECLARED_TABLE],
            'tenant_id in a condition' => [static fn (HostWorkspaceTables $t) => $t->select('keyhub_orders', ['tenant_id' => 2]), TableRefused::TENANT_COLUMN],
            'tenant_id in a row' => [static fn (HostWorkspaceTables $t) => $t->insert('keyhub_orders', ['ref' => 'x', 'tenant_id' => 2]), TableRefused::TENANT_COLUMN],
            'tenant_id set' => [static fn (HostWorkspaceTables $t) => $t->update('keyhub_orders', ['tenant_id' => 2], ['ref' => 'x']), TableRefused::TENANT_COLUMN],
            'injection in a column' => [static fn (HostWorkspaceTables $t) => $t->select('keyhub_orders', ['ref" OR 1=1 --' => 'x']), TableRefused::BAD_NAME],
            'injection in an order' => [static fn (HostWorkspaceTables $t) => $t->select('keyhub_orders', [], ['total; DROP TABLE x' => 'asc']), TableRefused::BAD_NAME],
            'update with no condition' => [static fn (HostWorkspaceTables $t) => $t->update('keyhub_orders', ['status' => 'x'], []), TableRefused::EMPTY_WRITE],
            'delete with no condition' => [static fn (HostWorkspaceTables $t) => $t->delete('keyhub_orders', []), TableRefused::EMPTY_WRITE],
            'empty insert' => [static fn (HostWorkspaceTables $t) => $t->insert('keyhub_orders', []), TableRefused::EMPTY_WRITE],
        ];
    }

    /** @param \Closure(HostWorkspaceTables): mixed $call */
    #[DataProvider('refusals')]
    public function testRefusals(\Closure $call, string $reason): void
    {
        try {
            $call($this->tables());
            self::fail('expected a refusal');
        } catch (TableRefused $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testNoWorkspaceNoCall(): void
    {
        try {
            $this->tables(null)->count('keyhub_orders');
            self::fail('expected a refusal');
        } catch (TableRefused $e) {
            self::assertSame(TableRefused::NO_WORKSPACE, $e->reason);
        }
    }

    public function testATransactionRollsBackOnFailure(): void
    {
        $t = $this->tables();
        try {
            $t->transaction(static function (\Whity\Sdk\Database\WorkspaceTables $tx): void {
                $tx->insert('keyhub_orders', ['ref' => 'T-1']);
                throw new \RuntimeException('stop');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $t->count('keyhub_orders'));

        self::assertSame('ok', $t->transaction(static function (\Whity\Sdk\Database\WorkspaceTables $tx): string {
            $tx->insert('keyhub_orders', ['ref' => 'T-2']);

            return 'ok';
        }));
        self::assertSame(1, $t->count('keyhub_orders'));
    }

    public function testTheScopesDatabaseUsesTheRequestsWorkspace(): void
    {
        TenantContext::setTenantId(7);
        $scope = new HostPluginScope(self::plugin('KeyHub', [Service::DATABASE], ['keyhub_orders']));
        $scope->database()->insert('keyhub_orders', ['ref' => 'W7']);

        self::assertSame('7', (string) $this->scalar("SELECT tenant_id FROM keyhub_orders WHERE ref = 'W7'"));
    }
}
