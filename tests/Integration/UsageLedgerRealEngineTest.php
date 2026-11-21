<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\SeedFreeBaseUsageEntitlements;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Plan\PlanService;
use Whity\Core\Seat\SeatService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Storage\StoredFiles;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Usage\CustomerUsage;
use Whity\Core\Usage\InvoiceUsageCounter;
use Whity\Core\Usage\McpUsage;
use Whity\Core\Usage\PlanLimitsReport;
use Whity\Core\Usage\StorageUsage;
use Whity\Core\Usage\UsageLedger;
use Whity\Core\Usage\UsageMeters;
use Whity\Core\Usage\UsageReport;
use Whity\Core\Usage\UsageSnapshots;
use Whity\Database\Database;

/**
 * The usage ledger (section 33 §5, BM.17) against a real schema: counted and
 * snapshot meters, the workspace's own month, stored bytes kept with the
 * files, the report's warning states, MCP calls and the Free base seed.
 */
final class UsageLedgerRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private SettingsService $settings;
    private EntitlementService $entitlements;
    private PlanService $plans;
    private UsageLedger $ledger;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Example Cafe', 'example-cafe')");

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->plans = new PlanService(new PlanRepository($this->pdo), $this->entitlements, $this->pdo);
        $this->ledger = new UsageLedger($this->pdo, $this->settings);
        unset($GLOBALS['whity_services'][InvoiceUsageCounter::class]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['whity_services'][InvoiceUsageCounter::class]);
    }

    // ── Counted meters ──────────────────────────────────────────────────────

    public function testCountedMetersAddUpPerDimension(): void
    {
        $now = $this->at('2026-11-21 10:00');
        $this->ledger->add(self::TENANT, UsageMeters::MESSAGES, 1, 'email', $now);
        $this->ledger->add(self::TENANT, UsageMeters::MESSAGES, 2, 'email', $now);
        $this->ledger->add(self::TENANT, UsageMeters::MESSAGES, 1, 'whatsapp', $now);

        $month = $this->ledger->month(self::TENANT, '2026-11');
        self::assertSame(3, $month[UsageMeters::MESSAGES]['email']['quantity']);
        self::assertSame(1, $month[UsageMeters::MESSAGES]['whatsapp']['quantity']);
        self::assertSame([], $this->ledger->month(2, '2026-11'), 'Another workspace sees none of it.');
    }

    public function testTheMonthIsTheWorkspacesOwn(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::TIMEZONE, 'Asia/Amman');

        // 22:30 UTC on 30 November is already 1 December in Amman.
        $this->ledger->add(self::TENANT, UsageMeters::API_CALLS, 1, UsageMeters::CALLS_MCP, $this->at('2026-11-30 22:30'));

        self::assertSame([], $this->ledger->month(self::TENANT, '2026-11'));
        self::assertSame(1, $this->ledger->month(self::TENANT, '2026-12')[UsageMeters::API_CALLS]['mcp']['quantity']);
    }

    public function testAnOddDimensionIsFoldedNotRefused(): void
    {
        $this->ledger->add(self::TENANT, UsageMeters::MESSAGES, 1, "bad name; DROP", $this->at('2026-11-21 10:00'));

        self::assertSame(1, $this->ledger->month(self::TENANT, '2026-11')[UsageMeters::MESSAGES]['']['quantity']);
    }

    public function testAddingToASnapshotMeterIsLoggedAndIgnored(): void
    {
        $this->ledger->add(self::TENANT, UsageMeters::SEATS, 5, '', $this->at('2026-11-21 10:00'));

        self::assertSame([], $this->ledger->month(self::TENANT, '2026-11'));
    }

    // ── Snapshots ───────────────────────────────────────────────────────────

    public function testASnapshotKeepsTheLatestAndTheMonthsHighest(): void
    {
        $this->ledger->snapshot(self::TENANT, UsageMeters::SEATS, 4, '', $this->at('2026-11-02 10:00'));
        $this->ledger->snapshot(self::TENANT, UsageMeters::SEATS, 6, '', $this->at('2026-11-10 10:00'));
        $this->ledger->snapshot(self::TENANT, UsageMeters::SEATS, 3, '', $this->at('2026-11-20 10:00'));

        $row = $this->ledger->month(self::TENANT, '2026-11')[UsageMeters::SEATS][''];
        self::assertSame('snapshot', $row['kind']);
        self::assertSame(3, $row['quantity']);
        self::assertSame(6, $row['peak']);
    }

    public function testTheSnapshotsReadEachTotalFromWhereItIsKept(): void
    {
        $this->storeFile(self::TENANT, 'a', 1500);
        $GLOBALS['whity_services'][InvoiceUsageCounter::class] = new class implements InvoiceUsageCounter {
            public function issuedBetween(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $until): int
            {
                return $tenantId === 1 && $from->format('Y-m-d') === '2026-11-01' && $until->format('Y-m-d') === '2026-12-01' ? 7 : 0;
            }
        };

        $values = $this->snapshots()->take(self::TENANT, $this->at('2026-11-21 10:00'));

        self::assertSame(1500, $values[UsageMeters::STORAGE]);
        self::assertSame(7, $values[UsageMeters::INVOICES]);
        self::assertSame(0, $values[UsageMeters::ITEMS]);
        self::assertSame(0, $values[UsageMeters::CUSTOMERS]);
        self::assertSame(7, $this->ledger->month(self::TENANT, '2026-11')[UsageMeters::INVOICES]['']['quantity']);
    }

    public function testInvoicesCountNoneUntilAccountingBindsACounter(): void
    {
        $values = $this->snapshots()->take(self::TENANT, $this->at('2026-11-21 10:00'));

        self::assertSame(0, $values[UsageMeters::INVOICES]);
    }

    // ── Storage ─────────────────────────────────────────────────────────────

    public function testStoredBytesFollowTheFiles(): void
    {
        $this->storeFile(self::TENANT, 'a', 1000);
        $this->storeFile(self::TENANT, 'b', 250);
        $this->storeFile(2, 'c', 99);
        // The same object remembered twice is one file.
        $this->storeFile(self::TENANT, 'b', 250);
        self::assertSame(1250, StorageUsage::bytes($this->pdo, self::TENANT));

        StoredFiles::forget($this->pdo, self::TENANT, $this->key(self::TENANT, 'a'));
        self::assertSame(250, StorageUsage::bytes($this->pdo, self::TENANT));
        self::assertSame(99, StorageUsage::bytes($this->pdo, 2));
    }

    public function testTheRebuildPutsADriftedTotalRight(): void
    {
        $this->storeFile(self::TENANT, 'a', 1000);
        $this->pdo->exec('UPDATE tenant_storage_usage SET bytes = 5 WHERE tenant_id = 1');

        self::assertSame([1000, 1], StorageUsage::rebuild($this->pdo, self::TENANT));
        self::assertSame(1000, StorageUsage::bytes($this->pdo, self::TENANT));
    }

    public function testATotalNeverGoesBelowZero(): void
    {
        StorageUsage::removed($this->pdo, self::TENANT, 400);
        $this->storeFile(self::TENANT, 'a', 100);
        StorageUsage::removed($this->pdo, self::TENANT, 400);

        self::assertSame(0, StorageUsage::bytes($this->pdo, self::TENANT));
    }

    // ── MCP calls ───────────────────────────────────────────────────────────

    public function testAnAgentsCallsInOneWindowAreOneRun(): void
    {
        $usage = new McpUsage($this->ledger, new DatabaseSharedStore($this->pdo));

        self::assertTrue($usage->agentCalled(self::TENANT, 'agent-a'));
        self::assertFalse($usage->agentCalled(self::TENANT, 'agent-a'));
        self::assertTrue($usage->agentCalled(self::TENANT, 'agent-b'), 'Another agent starts its own run.');

        $agents = $this->ledger->month(self::TENANT, $this->ledger->period(self::TENANT))[UsageMeters::AGENTS];
        self::assertSame(2, $agents[UsageMeters::AGENT_RUNS]['quantity']);
        self::assertSame(3, $agents[UsageMeters::AGENT_TOOL_CALLS]['quantity']);
        self::assertArrayNotHasKey(UsageMeters::AGENT_TOKENS_IN, $agents, 'Whity supplies no model, so no tokens are counted.');
    }

    public function testAPersonsCallIsAnApiCall(): void
    {
        (new McpUsage($this->ledger, new DatabaseSharedStore($this->pdo)))->personCalled(self::TENANT);

        $month = $this->ledger->month(self::TENANT, $this->ledger->period(self::TENANT));
        self::assertSame(1, $month[UsageMeters::API_CALLS][UsageMeters::CALLS_MCP]['quantity']);
    }

    // ── The report ──────────────────────────────────────────────────────────

    public function testWithoutAPlanEveryMeterHasNoLimit(): void
    {
        // A self-hosted install has no plan limits (BM.3 Q14).
        $report = $this->report()->forTenant(self::TENANT);

        self::assertSame(UsageMeters::all(), array_column($report['meters'], 'meter'));
        foreach ($report['meters'] as $meter) {
            self::assertNull($meter['base'], $meter['meter']);
            self::assertSame(UsageReport::STATE_UNLIMITED, $meter['state'], $meter['meter']);
        }
    }

    public function testNearAndOverAreWarningsOnly(): void
    {
        $tier = $this->plans->createPlan('free', 'Free', null, true, 0);
        $this->plans->setPlanEntitlement($tier, EntitlementRegistry::API_CALLS_PER_MONTH, '10');
        $this->plans->setPlanEntitlement($tier, EntitlementRegistry::MESSAGES_OUTBOUND_PER_MONTH, '2');
        $this->plans->applyToTenant($tier, self::TENANT);
        $now = new DateTimeImmutable();

        $this->ledger->add(self::TENANT, UsageMeters::API_CALLS, 8, UsageMeters::CALLS_MCP, $now);
        $this->ledger->add(self::TENANT, UsageMeters::MESSAGES, 3, 'email', $now);

        $rows = array_column($this->report()->forTenant(self::TENANT, null, $now)['meters'], null, 'meter');
        self::assertSame(UsageReport::STATE_NEAR, $rows[UsageMeters::API_CALLS]['state']);
        self::assertSame(10, $rows[UsageMeters::API_CALLS]['base']);
        self::assertSame(['mcp' => 8], $rows[UsageMeters::API_CALLS]['dimensions']);
        self::assertSame(UsageReport::STATE_OVER, $rows[UsageMeters::MESSAGES]['state']);
        self::assertNotNull($rows[UsageMeters::MESSAGES]['resets_at']);
        self::assertNull($rows[UsageMeters::SEATS]['resets_at'], 'Seats are held, not used up in a month.');
    }

    public function testAgentsAreMeasuredOnRuns(): void
    {
        $tier = $this->plans->createPlan('free', 'Free', null, true, 0);
        $this->plans->setPlanEntitlement($tier, EntitlementRegistry::AGENTS_RUNS_PER_MONTH, '1');
        $this->plans->applyToTenant($tier, self::TENANT);
        $usage = new McpUsage($this->ledger, new DatabaseSharedStore($this->pdo));
        $usage->agentCalled(self::TENANT, 'agent-a');
        $usage->agentCalled(self::TENANT, 'agent-a');

        $rows = array_column($this->report()->forTenant(self::TENANT)['meters'], null, 'meter');
        self::assertSame(1, $rows[UsageMeters::AGENTS]['used']);
        self::assertSame(UsageReport::STATE_NEAR, $rows[UsageMeters::AGENTS]['state']);
        self::assertSame(2, $rows[UsageMeters::AGENTS]['dimensions'][UsageMeters::AGENT_TOOL_CALLS]);
    }

    public function testAnEarlierMonthIsReadAsItWasKept(): void
    {
        $this->ledger->snapshot(self::TENANT, UsageMeters::SEATS, 9, '', $this->at('2026-10-05 10:00'));
        $this->ledger->snapshot(self::TENANT, UsageMeters::SEATS, 2, '', $this->at('2026-10-25 10:00'));

        $report = $this->report()->forTenant(self::TENANT, '2026-10', $this->at('2026-11-21 10:00'));
        $rows = array_column($report['meters'], null, 'meter');

        self::assertFalse($report['current']);
        self::assertSame(2, $rows[UsageMeters::SEATS]['used']);
        self::assertSame(9, $rows[UsageMeters::SEATS]['peak']);
        self::assertContains('2026-10', $report['periods']);
        self::assertContains('2026-11', $report['periods']);
    }

    public function testThePlanLimitsBannerReadsTheLedger(): void
    {
        $tier = $this->plans->createPlan('free', 'Free', null, true, 0);
        $this->plans->setPlanEntitlement($tier, EntitlementRegistry::API_CALLS_PER_MONTH, '1');
        $this->plans->setPlanEntitlement($tier, EntitlementRegistry::STORAGE_QUOTA_BYTES, '100');
        $this->plans->applyToTenant($tier, self::TENANT);
        $this->ledger->add(self::TENANT, UsageMeters::API_CALLS, 2, UsageMeters::CALLS_MCP);
        $this->storeFile(self::TENANT, 'a', 150);

        $limits = new PlanLimitsReport(
            $this->pdo,
            $this->entitlements,
            new SeatService($this->pdo, $this->entitlements, $this->settings),
            new CustomerUsage($this->pdo, $this->entitlements, $this->settings),
            new MeterService(new DatabaseSharedStore($this->pdo), $this->entitlements, $this->settings),
            $this->ledger,
        );
        $rows = array_column($limits->forTenant(self::TENANT), null, 'key');

        self::assertSame(2, $rows[EntitlementRegistry::API_CALLS_PER_MONTH]['used']);
        self::assertTrue($rows[EntitlementRegistry::API_CALLS_PER_MONTH]['over']);
        self::assertSame(150, $rows[EntitlementRegistry::STORAGE_QUOTA_BYTES]['used']);
        self::assertTrue($rows[EntitlementRegistry::STORAGE_QUOTA_BYTES]['over']);
        self::assertArrayNotHasKey(EntitlementRegistry::INVOICES_PER_MONTH, $rows, 'Unlimited meters are left out.');
    }

    // ── The Free base ───────────────────────────────────────────────────────

    public function testTheFreeBaseIsSetOnTheDefaultPlanOnlyWhereAbsent(): void
    {
        $free = $this->plans->createPlan('starter', 'Starter', null, true, 0);
        $other = $this->plans->createPlan('team', 'Team', null, true, 0);
        $this->plans->setPlanEntitlement($free, EntitlementRegistry::API_CALLS_PER_MONTH, '50');
        $this->settings->setGlobal(SettingsRegistry::BILLING_DEFAULT_PLAN, 'starter');

        SeedFreeBaseUsageEntitlements::up($this->database());
        SeedFreeBaseUsageEntitlements::up($this->database());

        $this->plans->applyToTenant($free, self::TENANT);
        $this->plans->applyToTenant($other, 2);
        self::assertSame(100, $this->entitlements->limit(self::TENANT, EntitlementRegistry::INVOICES_PER_MONTH));
        self::assertSame(50, $this->entitlements->limit(self::TENANT, EntitlementRegistry::API_CALLS_PER_MONTH), 'An operator\'s own number stays.');
        self::assertSame(EntitlementRegistry::UNLIMITED, $this->entitlements->limit(2, EntitlementRegistry::INVOICES_PER_MONTH));
    }

    public function testNoFreePlanMeansNoLimits(): void
    {
        $this->plans->createPlan('team', 'Team', null, true, 0);

        SeedFreeBaseUsageEntitlements::up($this->database());

        $count = $this->pdo->query("SELECT COUNT(*) FROM plan_entitlements WHERE entitlement_key IN ('invoices.per_month', 'api.calls.per_month')");
        self::assertSame(0, (int) ($count === false ? -1 : $count->fetchColumn()));
    }

    public function testTheNewMetersOnlyWarn(): void
    {
        self::assertContains(EntitlementRegistry::INVOICES_PER_MONTH, EntitlementRegistry::WARN_ONLY);
        self::assertContains(EntitlementRegistry::API_CALLS_PER_MONTH, EntitlementRegistry::WARN_ONLY);
        self::assertContains(EntitlementRegistry::STORAGE_QUOTA_BYTES, EntitlementRegistry::WARN_ONLY);
        self::assertSame('-1', EntitlementRegistry::defaultFor(EntitlementRegistry::INVOICES_PER_MONTH));
        self::assertSame('-1', EntitlementRegistry::defaultFor(EntitlementRegistry::API_CALLS_PER_MONTH));
        self::assertSame('warn', SettingsRegistry::defaultFor(SettingsRegistry::METERS_ENFORCEMENT));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function snapshots(): UsageSnapshots
    {
        return new UsageSnapshots(
            $this->pdo,
            $this->ledger,
            new SeatService($this->pdo, $this->entitlements, $this->settings),
            new CustomerUsage($this->pdo, $this->entitlements, $this->settings),
        );
    }

    private function report(): UsageReport
    {
        return new UsageReport($this->ledger, $this->snapshots(), $this->entitlements);
    }

    private function storeFile(int $tenantId, string $name, int $bytes): void
    {
        StoredFiles::remember($this->pdo, $tenantId, StoredFiles::CHAT_ATTACHMENT, [
            'storage_key' => $this->key($tenantId, $name),
            'content_type' => 'application/pdf',
            'byte_size' => $bytes,
        ], $name . '.pdf', null);
    }

    private function key(int $tenantId, string $name): string
    {
        return 'tenants/' . $tenantId . '/chat/1/' . $name . '.pdf';
    }

    private function database(): Database
    {
        $pdo = $this->pdo;

        return Database::withFactory(static fn (): PDO => $pdo);
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc . ' UTC');
    }
}
