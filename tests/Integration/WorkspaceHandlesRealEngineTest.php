<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\HandleException;
use Whity\Core\Tenant\HandleRules;
use Whity\Core\Tenant\Handles;
use Whity\Sdk\Hooks\Events;

/**
 * Workspace @handles on the real schema (WHIT-1363, design section 36):
 * changing, holding and resolving old handles, the 30-day rule, operators,
 * and how migration 354 moves existing slugs.
 */
final class WorkspaceHandlesRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Handles $handles;
    /** @var list<array<string, mixed>> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $hooks = new HookManager();
        $hooks->listen(Events::TENANT_HANDLE_CHANGED, function (array $payload): array {
            $this->events[] = $payload;
            return $payload;
        });
        $this->handles = new Handles($this->pdo, static fn (): string => "local-brand\n", null, $hooks);
    }

    public function testAChangeKeepsTheOldHandleLeadingHereFor90Days(): void
    {
        $bakery = $this->tenant('Example Bakery', 'example-bakery');

        self::assertSame('bakery', $this->handles->change($bakery, '@Bakery', null));

        self::assertSame('bakery', $this->handles->handleOf($bakery));
        self::assertSame($bakery, $this->handles->resolve('bakery'));
        self::assertSame($bakery, $this->handles->resolve('example-bakery'));
        self::assertTrue($this->handles->isHeldAlias('example-bakery'));
        self::assertSame($bakery, (new TenantHostRepository($this->pdo))->findIdBySlug('Example-Bakery'));
        self::assertSame(
            [['id' => $bakery, 'old_handle' => 'example-bakery', 'new_handle' => 'bakery', 'by_profile_id' => null]],
            $this->events
        );
    }

    public function testAnotherWorkspaceCantTakeAHeldHandleButTheSameOneCanTakeItBack(): void
    {
        $bakery = $this->tenant('Example Bakery', 'example-bakery');
        $cafe = $this->tenant('Example Cafe', 'example-cafe');
        $this->handles->change($bakery, 'bakery', null);

        self::assertSame(HandleRules::HELD, $this->handles->problem('example-bakery', $cafe));
        self::assertNull($this->handles->problem('example-bakery', $bakery));

        // Operators aren't bound by the 30 days; the same workspace takes it back.
        $this->handles->change($bakery, 'example-bakery', null, true);
        self::assertSame('example-bakery', $this->handles->handleOf($bakery));
        self::assertFalse($this->handles->isHeldAlias('example-bakery'));
        self::assertSame($bakery, $this->handles->resolve('bakery'));
    }

    public function testAHeldHandleIsFreeAfterTheHold(): void
    {
        $bakery = $this->tenant('Example Bakery', 'example-bakery');
        $cafe = $this->tenant('Example Cafe', 'example-cafe');
        $this->handles->change($bakery, 'bakery', null);
        $this->pdo->exec("UPDATE tenant_handle_history SET held_until = '2000-01-01 00:00:00'");

        self::assertNull($this->handles->resolve('example-bakery'));
        self::assertNull($this->handles->problem('example-bakery', $cafe));
    }

    public function testAPersonChangesAHandleOnceIn30DaysButTheFirstChoiceIsFree(): void
    {
        $bakery = $this->tenant('Example Bakery', HandleRules::generate());

        $this->handles->change($bakery, 'example-bakery', null);
        self::assertNull($this->handles->nextChangeAllowedAt($bakery), 'choosing the first handle starts no clock');
        $this->handles->change($bakery, 'bakery', null);

        self::assertNotNull($this->handles->nextChangeAllowedAt($bakery));
        try {
            $this->handles->change($bakery, 'the-bakery', null);
            self::fail('a second change within 30 days must be refused');
        } catch (HandleException $e) {
            self::assertSame(HandleRules::TOO_SOON, $e->reason);
        }
        // An operator may.
        self::assertSame('the-bakery', $this->handles->change($bakery, 'the-bakery', null, true));
    }

    public function testAWsHandleLeavesNoHistory(): void
    {
        $made = HandleRules::generate();
        $bakery = $this->tenant('Example Bakery', $made);

        $this->handles->change($bakery, 'example-bakery', null);

        self::assertNull($this->handles->resolve($made));
    }

    public function testReservedTakenAndShape(): void
    {
        $bakery = $this->tenant('Example Bakery', 'example-bakery');
        $cafe = $this->tenant('Example Cafe', 'example-cafe');

        self::assertSame(HandleRules::RESERVED, $this->handles->problem('admin', $cafe));
        self::assertSame(HandleRules::RESERVED, $this->handles->problem('local-brand', $cafe));
        self::assertSame(HandleRules::TAKEN, $this->handles->problem('EXAMPLE-BAKERY', $cafe));
        self::assertSame(HandleRules::GENERATED, $this->handles->problem('ws-abc', $cafe));
        self::assertSame(HandleRules::DOUBLE_DASH, $this->handles->problem('my--cafe', $cafe));
        // Operators may give out Whity's words, not the operator's own list.
        self::assertNull($this->handles->problem('support', $cafe, true));
        self::assertSame(HandleRules::RESERVED, $this->handles->problem('local-brand', $cafe, true));
        self::assertNull($this->handles->problem('example-bakery', $bakery));
    }

    public function testTheSystemWorkspaceHasNoHandle(): void
    {
        $this->expectException(HandleException::class);
        $this->handles->change(0, 'system-team', null, true);
    }

    public function testMigration354Plan(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/354_create_tenant_handle_history.php';
        $made = 'ws-0123456789ab';

        $plan = \Database\Migrations\CreateTenantHandleHistory::plan([
            ['id' => 0, 'slug' => null],
            ['id' => 1, 'slug' => 'almolakhas'],
            ['id' => 2, 'slug' => 'aau-irb'],
            ['id' => 3, 'slug' => 'Curapass'],
            ['id' => 4, 'slug' => $made],
            ['id' => 5, 'slug' => '-leading'],
            ['id' => 6, 'slug' => 'admin'],
            ['id' => 7, 'slug' => null],
            ['id' => 8, 'slug' => 'AAU-IRB'],
            ['id' => 9, 'slug' => 'mcp-check'],
        ]);

        self::assertArrayNotHasKey(0, $plan);
        self::assertArrayNotHasKey(1, $plan);
        self::assertArrayNotHasKey(2, $plan);
        self::assertSame(['Curapass', 'curapass'], $plan[3]);
        self::assertArrayNotHasKey(4, $plan);
        self::assertSame(['-leading', 'leading'], $plan[5]);
        self::assertTrue(HandleRules::isGenerated($plan[6][1]), 'a reserved slug becomes a ws- one');
        self::assertTrue(HandleRules::isGenerated($plan[7][1]), 'a missing slug becomes a ws- one');
        self::assertTrue(HandleRules::isGenerated($plan[8][1]), 'a case clash goes to the later workspace');
        self::assertArrayNotHasKey(9, $plan);
    }

    public function testTheLowerCaseIndexRefusesACaseTwin(): void
    {
        $this->tenant('Example Bakery', 'example-bakery');

        $this->expectException(\PDOException::class);
        $this->tenant('Example Bakery Two', 'EXAMPLE-BAKERY');
    }

    private function tenant(string $name, string $slug): int
    {
        $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, CURRENT_TIMESTAMP)')
            ->execute([':n' => $name, ':s' => $slug]);

        return (int) $this->pdo->lastInsertId();
    }
}
