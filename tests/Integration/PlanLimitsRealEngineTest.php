<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Plan\PlanService;
use Whity\Core\Plan\PlanValidationException;
use Whity\Core\Seat\SeatService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Usage\CustomerUsage;
use Whity\Core\Usage\PlanLimitsReport;

/**
 * The plan limits the business model sells (WHIT-932): add-on packs that stack,
 * customers per month, agent work as an allowance, brand features, and staff
 * that never includes agents or customers. Every one of them warns; none locks.
 */
final class PlanLimitsRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private SettingsService $settings;
    private EntitlementService $entitlements;
    private PlanService $plans;
    private SeatService $seats;
    private CustomerUsage $customers;
    private MeterService $meters;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->plans = new PlanService(new PlanRepository($this->pdo), $this->entitlements, $this->pdo);
        $this->seats = new SeatService($this->pdo, $this->entitlements, $this->settings);
        $this->customers = new CustomerUsage($this->pdo, $this->entitlements, $this->settings);
        $this->meters = new MeterService(new DatabaseSharedStore($this->pdo), $this->entitlements, $this->settings);
    }

    // ── Add-on packs stack onto the tier ────────────────────────────────────

    public function testPacksAddTheirNumbersToTheTier(): void
    {
        $team = $this->tier('team', [EntitlementRegistry::MEMBERS_MAX => '25']);
        $pack = $this->pack('extra-staff', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $this->plans->applyToTenant($team, self::TENANT);

        $this->plans->setTenantAddon(self::TENANT, $pack, 2);

        self::assertSame(45, $this->entitlements->limit(self::TENANT, EntitlementRegistry::MEMBERS_MAX));
    }

    public function testAPackCannotCapATierThatHasNoCap(): void
    {
        $tier = $this->tier('larger', [EntitlementRegistry::MEMBERS_MAX => '-1']);
        $pack = $this->pack('extra-staff', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $this->plans->applyToTenant($tier, self::TENANT);
        $this->plans->setTenantAddon(self::TENANT, $pack, 1);

        self::assertSame(EntitlementRegistry::UNLIMITED, $this->entitlements->limit(self::TENANT, EntitlementRegistry::MEMBERS_MAX));
    }

    public function testAPackGrantsAFeatureAndNeverTakesOneAway(): void
    {
        $tier = $this->tier('team', [EntitlementRegistry::BRAND_CUSTOM_DOMAIN => 'true', EntitlementRegistry::BRAND_REMOVE_BADGE => 'false']);
        $pack = $this->pack('white-label', [EntitlementRegistry::BRAND_REMOVE_BADGE => 'true', EntitlementRegistry::BRAND_CUSTOM_DOMAIN => 'false']);
        $this->plans->applyToTenant($tier, self::TENANT);
        $this->plans->setTenantAddon(self::TENANT, $pack, 1);

        self::assertTrue($this->entitlements->isGranted(self::TENANT, EntitlementRegistry::BRAND_REMOVE_BADGE));
        self::assertTrue($this->entitlements->isGranted(self::TENANT, EntitlementRegistry::BRAND_CUSTOM_DOMAIN));
    }

    public function testAnOperatorOverrideIsFinalOverPacks(): void
    {
        $tier = $this->tier('team', [EntitlementRegistry::MEMBERS_MAX => '25']);
        $pack = $this->pack('extra-staff', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $this->plans->applyToTenant($tier, self::TENANT);
        $this->plans->setTenantAddon(self::TENANT, $pack, 3);
        $this->entitlements->set(self::TENANT, EntitlementRegistry::MEMBERS_MAX, '30');

        self::assertSame(30, $this->entitlements->limit(self::TENANT, EntitlementRegistry::MEMBERS_MAX));
    }

    public function testQuantityZeroRemovesThePack(): void
    {
        $tier = $this->tier('team', [EntitlementRegistry::MEMBERS_MAX => '25']);
        $pack = $this->pack('extra-staff', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $this->plans->applyToTenant($tier, self::TENANT);
        $this->plans->setTenantAddon(self::TENANT, $pack, 1);
        $this->plans->setTenantAddon(self::TENANT, $pack, 0);

        self::assertSame([], $this->plans->listTenantAddons(self::TENANT));
        self::assertSame(25, $this->entitlements->limit(self::TENANT, EntitlementRegistry::MEMBERS_MAX));
    }

    /** Applying an add-on as the tier used to replace the tier. */
    public function testAnAddOnCannotBePutOnAsTheTier(): void
    {
        $pack = $this->pack('extra-staff', [EntitlementRegistry::MEMBERS_MAX => '10']);

        $this->expectException(PlanValidationException::class);
        $this->plans->applyToTenant($pack, self::TENANT);
    }

    public function testATierCannotBeHeldAsAPack(): void
    {
        $tier = $this->tier('team', []);

        $this->expectException(PlanValidationException::class);
        $this->plans->setTenantAddon(self::TENANT, $tier, 1);
    }

    public function testAPackSomebodyHoldsCannotBeDeleted(): void
    {
        $pack = $this->pack('extra-staff', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $this->plans->setTenantAddon(self::TENANT, $pack, 1);

        self::assertSame(1, $this->plans->usageFor($pack)->subscribers);
        $this->expectException(PlanValidationException::class);
        $this->plans->deletePlan($pack);
    }

    // ── Staff never includes agents, customers or service principals ────────

    public function testAgentsCustomersAndServicePrincipalsDoNotTakeStaffSeats(): void
    {
        $this->person(10);
        $this->membership(10, 'member');
        $this->person(11);
        $this->membership(11, 'customer');
        $this->person(12, 'service');
        $this->membership(12, 'member');

        // An agent acting for person 10: an agents row and a grant, no membership.
        $this->pdo->exec("INSERT INTO agents (id, tenant_id, slug, name, status) VALUES (7, 1, 'orders', 'Orders assistant', 'active')");
        $this->pdo->exec('INSERT INTO agent_assignments (id, tenant_id, agent_id, profile_id) VALUES (31, 1, 7, 10)');

        self::assertSame(1, $this->seats->used(self::TENANT));
    }

    // ── Customers per month ─────────────────────────────────────────────────

    public function testASignedInCustomerCountsOnceAMonth(): void
    {
        $this->person(20);
        $this->membership(20, 'customer');
        foreach ([501, 502, 503] as $submission) {
            $this->submitted($submission, 20);
        }

        self::assertSame(1, $this->customers->count(self::TENANT));

        // Next month they are a customer again.
        $next = (new DateTimeImmutable())->modify('first day of next month noon');
        self::assertTrue($this->customers->served(self::TENANT, 'profile:20', $next));
    }

    public function testEachAnonymousFormIsOneCustomerAndStaffAreNone(): void
    {
        $this->person(10);
        $this->membership(10, 'member');

        $this->submitted(601, null);
        $this->submitted(602, null);
        $this->submitted(603, 10);

        self::assertSame(2, $this->customers->count(self::TENANT));
    }

    public function testJoiningAsACustomerCounts(): void
    {
        $this->customers->onCustomerJoined(['tenant_id' => self::TENANT, 'profile_id' => 30]);
        $this->customers->onCustomerJoined(['tenant_id' => self::TENANT, 'profile_id' => 30]);

        self::assertSame(1, $this->customers->count(self::TENANT));
    }

    /** The month is the workspace's own: 23:30 on 31 March UTC is April in Amman. */
    public function testTheMonthIsTheWorkspacesOwn(): void
    {
        (new TenantSettingsRepository($this->pdo))->set(self::TENANT, SettingsRegistry::TIMEZONE, 'Asia/Amman');
        $lateMarchUtc = new DateTimeImmutable('2026-03-31 23:30:00 UTC');

        $this->customers->served(self::TENANT, 'submission:1', $lateMarchUtc);

        self::assertSame(0, $this->customers->count(self::TENANT, new DateTimeImmutable('2026-03-15 12:00:00 UTC')));
        self::assertSame(1, $this->customers->count(self::TENANT, new DateTimeImmutable('2026-04-15 12:00:00 UTC')));
    }

    public function testPassingTheCustomerLimitWarnsAndServesEveryone(): void
    {
        $tier = $this->tier('free', [EntitlementRegistry::CUSTOMERS_PER_MONTH => '2']);
        $this->plans->applyToTenant($tier, self::TENANT);

        foreach ([1, 2, 3] as $n) {
            self::assertTrue($this->customers->served(self::TENANT, 'submission:' . $n));
        }

        $row = $this->mustRow(EntitlementRegistry::CUSTOMERS_PER_MONTH);
        self::assertSame(3, $row['used']);
        self::assertSame(2, $row['limit']);
        self::assertTrue($row['over']);
    }

    // ── Agent work is an allowance, never a wall ─────────────────────────────

    public function testAgentWorkPastTheAllowanceIsCountedAndNeverRefused(): void
    {
        // Even on an instance that BLOCKS metered limits, and even with an
        // allowance of zero.
        $this->settings->setGlobal(SettingsRegistry::METERS_ENFORCEMENT, 'block');
        $tier = $this->tier('free', [EntitlementRegistry::AGENTS_RUNS_PER_MONTH => '0']);
        $this->plans->applyToTenant($tier, self::TENANT);

        for ($i = 0; $i < 3; $i++) {
            $decision = $this->meters->record(self::TENANT, [EntitlementRegistry::AGENTS_RUNS_PER_MONTH]);
            self::assertTrue($decision->allowed);
            self::assertTrue($decision->warned);
        }

        $row = $this->mustRow(EntitlementRegistry::AGENTS_RUNS_PER_MONTH);
        self::assertSame(3, $row['used']);
        self::assertTrue($row['over']);
    }

    public function testAgentWorkThroughConsumeIsNeverRefusedEither(): void
    {
        $this->settings->setGlobal(SettingsRegistry::METERS_ENFORCEMENT, 'block');
        $tier = $this->tier('free', [EntitlementRegistry::AGENTS_RUNS_PER_MONTH => '1']);
        $this->plans->applyToTenant($tier, self::TENANT);

        $this->meters->consume(self::TENANT, [EntitlementRegistry::AGENTS_RUNS_PER_MONTH]);
        self::assertTrue($this->meters->consume(self::TENANT, [EntitlementRegistry::AGENTS_RUNS_PER_MONTH])->allowed);
    }

    public function testDocumentsStillBlockWhereTheInstanceSaysSo(): void
    {
        $this->settings->setGlobal(SettingsRegistry::METERS_ENFORCEMENT, 'block');
        $tier = $this->tier('free', [EntitlementRegistry::DOCUMENTS_RENDER_PER_MONTH => '0']);
        $this->plans->applyToTenant($tier, self::TENANT);

        self::assertFalse($this->meters->consume(self::TENANT, [EntitlementRegistry::DOCUMENTS_RENDER_PER_MONTH])->allowed);
    }

    // ── Brand features ──────────────────────────────────────────────────────

    public function testBuiltOnWhityShowsUnlessThePlanRemovesIt(): void
    {
        $branding = new BrandingService($this->settings, entitlements: $this->entitlements);
        self::assertTrue($branding->effective(self::TENANT)->builtOnWhity);

        $tier = $this->tier('team', [EntitlementRegistry::BRAND_REMOVE_BADGE => 'true']);
        $this->plans->applyToTenant($tier, self::TENANT);

        self::assertFalse($branding->effective(self::TENANT)->builtOnWhity);
    }

    public function testAnOwnWebAddressWithoutThePlanShowsAsAWarning(): void
    {
        self::assertNull($this->row(EntitlementRegistry::BRAND_CUSTOM_DOMAIN));

        $tier = $this->tier('free', [EntitlementRegistry::BRAND_CUSTOM_DOMAIN => 'false']);
        $this->plans->applyToTenant($tier, self::TENANT);
        $this->pdo->exec("UPDATE tenants SET branding_host = 'orders.example-bakery.test' WHERE id = 1");

        $row = $this->mustRow(EntitlementRegistry::BRAND_CUSTOM_DOMAIN);
        self::assertSame('feature', $row['kind']);
        self::assertTrue($row['over']);
    }

    public function testTheReportShowsStaffAgainstTheirLimit(): void
    {
        $tier = $this->tier('free', [EntitlementRegistry::MEMBERS_MAX => '1']);
        $this->plans->applyToTenant($tier, self::TENANT);
        foreach ([10, 11] as $id) {
            $this->person($id);
            $this->membership($id, 'member');
        }

        $row = $this->mustRow(EntitlementRegistry::MEMBERS_MAX);
        self::assertSame(['used' => 2, 'limit' => 1, 'over' => true], ['used' => $row['used'], 'limit' => $row['limit'], 'over' => $row['over']]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param array<string, string> $limits */
    private function tier(string $key, array $limits): int
    {
        $id = $this->plans->createPlan($key, ucfirst($key), null, true, 0);
        foreach ($limits as $k => $v) {
            $this->plans->setPlanEntitlement($id, $k, $v);
        }

        return $id;
    }

    /** @param array<string, string> $limits */
    private function pack(string $key, array $limits): int
    {
        $id = $this->plans->createPlan($key, ucfirst($key), null, true, 0, true);
        foreach ($limits as $k => $v) {
            $this->plans->setPlanEntitlement($id, $k, $v);
        }

        return $id;
    }

    private function person(int $id, string $authMethod = 'local'): void
    {
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, auth_method, created_at, updated_at)
             VALUES ({$id}, 'p{$id}', 'x', false, 0, 0, '{$authMethod}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
    }

    private function membership(int $profileId, string $kind): void
    {
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
             VALUES ({$profileId}, 1, 901, 'active', '{$kind}', CURRENT_TIMESTAMP)"
        );
    }

    private function submitted(int $submissionId, ?int $actor): void
    {
        $this->customers->onFormSubmitted([
            'id' => $submissionId,
            'tenant_id' => self::TENANT,
            'form_id' => 1,
            'actor_profile_id' => $actor,
        ]);
    }

    /** @return array{key: string, kind: string, used: int|null, limit: int|null, over: bool, resets_at: string|null} */
    private function mustRow(string $key): array
    {
        $row = $this->row($key);
        self::assertNotNull($row, "No row for {$key}");

        return $row;
    }

    /** @return array{key: string, kind: string, used: int|null, limit: int|null, over: bool, resets_at: string|null}|null */
    private function row(string $key): ?array
    {
        $report = new PlanLimitsReport($this->pdo, $this->entitlements, $this->seats, $this->customers, $this->meters);
        foreach ($report->forTenant(self::TENANT) as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }
}
