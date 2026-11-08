<?php

declare(strict_types=1);

namespace Tests\Integration\Audit;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Audit\HostPluginAudit;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Sdk\Audit\AuditTrail;
use Whity\Sdk\PluginInterface;

/**
 * A plugin's own audit entries (WHIT-1032), against the migration-built schema:
 * attributed by the host, and readable only by that plugin in that workspace.
 */
final class PluginAuditRealEngineTest extends TestCase
{
    private const ALICE = 101;

    private PDO $pdo;
    private ?int $tenant = 1;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe') ON CONFLICT DO NOTHING");
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        AuditContext::reset();
    }

    public function testAnEntryIsStoredUnderThePluginsNamespaceInTheCurrentWorkspace(): void
    {
        $this->trail('audit-acme')->record('order.refunded', 'order', 7, ['amount' => 500]);

        $row = $this->rows()[0];
        self::assertSame('audit_acme:order.refunded', $row['action']);
        self::assertSame('audit_acme:order', $row['target_type']);
        self::assertSame(7, (int) $row['target_id']);
        self::assertSame(1, (int) $row['tenant_id']);
    }

    public function testTheActorComesFromTheHostNeverFromTheMetadata(): void
    {
        AuditContext::setActor(Actor::person(self::ALICE), '203.0.113.9');

        $this->trail('audit-acme')->record('order.refunded', null, null, [
            'actor_user_id' => 999,
            'tenant_id' => 2,
            'ip_address' => '198.51.100.1',
            '_origin' => 'cli',
        ]);

        $row = $this->rows()[0];
        self::assertSame(self::ALICE, (int) $row['actor_user_id']);
        self::assertSame('person', $row['actor_kind']);
        self::assertSame('203.0.113.9', $row['ip_address']);
        self::assertSame(1, (int) $row['tenant_id']);
        self::assertArrayNotHasKey('_origin', json_decode((string) $row['metadata'], true));
    }

    public function testAJobWithNoPersonIsRecordedAsTheSystem(): void
    {
        $this->trail('audit-acme')->record('catalog.synced');

        $entry = $this->trail('audit-acme')->entries()[0];
        self::assertSame('system', $entry->actorKind);
        self::assertNull($entry->actorProfileId);
    }

    /** @dataProvider forgedNames */
    public function testANameCannotClaimAnotherPluginOrCore(string $action, ?string $targetType): void
    {
        try {
            $this->trail('audit-acme')->record($action, $targetType);
            self::fail('A forged name was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->rows());
        }
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function forgedNames(): iterable
    {
        yield 'another plugin' => ['audit_other:order.refunded', null];
        yield 'its own prefix' => ['audit_acme:order.refunded', null];
        yield 'a colon in the target type' => ['order.refunded', 'audit_other:order'];
        yield 'a bad shape' => ['Order Refunded', null];
        yield 'too long' => [str_repeat('a', 120), null];
    }

    public function testACoreActionNameStaysThePlugins(): void
    {
        $this->trail('audit-acme')->record('user.deleted');

        self::assertSame('audit_acme:user.deleted', $this->rows()[0]['action']);
    }

    public function testAPluginReadsOnlyItsOwnEntriesWithBareNames(): void
    {
        $this->trail('audit-acme')->record('order.refunded', 'order', 7, ['amount' => 500]);
        $this->trail('audit-other')->record('order.refunded');
        $this->logger()->record('user.deleted', ['tenant_id' => 1]);

        $entries = $this->trail('audit-acme')->entries();

        self::assertCount(1, $entries);
        self::assertSame('order.refunded', $entries[0]->action);
        self::assertSame('order', $entries[0]->targetType);
        self::assertSame(['amount' => 500], $entries[0]->metadata);
    }

    public function testAnUnderscoreInASlugDoesNotMatchALookalikePlugin(): void
    {
        $this->trail('audit-acme')->record('order.refunded');
        $this->logger()->record('auditxacme:order.refunded', ['tenant_id' => 1]);

        self::assertCount(1, $this->trail('audit-acme')->entries());
    }

    public function testAWorkspaceSeesOnlyItsOwnEntries(): void
    {
        $trail = $this->trail('audit-acme');
        $trail->record('order.refunded');

        $this->tenant = 2;

        self::assertSame([], $trail->entries());
    }

    public function testEntriesAreNewestFirstAndPage(): void
    {
        $trail = $this->trail('audit-acme');
        $trail->record('one');
        $trail->record('two');
        $trail->record('three');

        $first = $trail->entries(2);
        self::assertSame(['three', 'two'], array_map(static fn ($e): string => $e->action, $first));

        $next = $trail->entries(2, $first[1]->id);
        self::assertSame(['one'], array_map(static fn ($e): string => $e->action, $next));
    }

    public function testEntriesFilterByAction(): void
    {
        $trail = $this->trail('audit-acme');
        $trail->record('one');
        $trail->record('two');

        self::assertSame(['two'], array_map(static fn ($e): string => $e->action, $trail->entries(action: 'two')));
    }

    public function testSecretLookingMetadataIsDropped(): void
    {
        $trail = $this->trail('audit-acme');
        $trail->record('one', null, null, ['api_token' => 'x', 'order' => 7]);

        self::assertSame(['order' => 7], $trail->entries()[0]->metadata);
    }

    /** @dataProvider noWorkspace */
    public function testNoWorkspaceIsRefused(?int $tenant): void
    {
        $trail = $this->trail('audit-acme');
        $this->tenant = $tenant;

        $this->expectException(\RuntimeException::class);
        $trail->record('one');
    }

    /** @return iterable<string, array{?int}> */
    public static function noWorkspace(): iterable
    {
        yield 'none' => [null];
        yield 'the system tenant' => [0];
    }

    public function testAStubCarryingALoadedPluginsNameIsRefused(): void
    {
        $this->trail('audit-acme');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->for(self::plugin('audit-acme'));
    }

    private function trail(string $name): AuditTrail
    {
        $plugin = self::plugin($name);
        PluginIdentity::bind($plugin, $name);

        return $this->service()->for($plugin);
    }

    private function service(): HostPluginAudit
    {
        return new HostPluginAudit($this->pdo, $this->logger(), fn (): ?int => $this->tenant);
    }

    private function logger(): AuditLogger
    {
        return new AuditLogger($this->pdo);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM audit_log ORDER BY id');
        self::assertNotFalse($stmt);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function plugin(string $name): PluginInterface
    {
        return new class ($name) implements PluginInterface {
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
    }
}
