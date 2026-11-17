<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Plan\PlanPriceRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\WorkspaceCreator;
use Whity\Core\Tenant\WorkspaceOwners;

/**
 * "Each new workspace pays" (WHIT-1203): a person's first owned workspace
 * starts on the default plan, every further one on the additional plan in a
 * 30-day trial, then read-only until it is paid. On the real schema from migrations.
 */
final class AdditionalWorkspacePlanRealEngineTest extends TestCase
{
    private PDO $pdo;
    private SettingsService $settings;
    private WorkspaceCreator $creator;
    private WorkspaceOwners $owners;
    private int $freeId;
    private int $teamId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->settings->setGlobal(SettingsRegistry::WORKSPACES_SELF_CREATE_ENABLED, 'true');
        $this->creator = new WorkspaceCreator($this->pdo, $this->settings);
        $this->owners = new WorkspaceOwners($this->pdo);

        $plans = new PlanRepository($this->pdo);
        $this->freeId = $plans->createPlan('free', 'Free', null, true, 0, false);
        $this->teamId = $plans->createPlan('team', 'Team', null, true, 1, false);
        $prices = new PlanPriceRepository($this->pdo);
        $prices->create($this->teamId, 'USD', 6900, PlanPriceRepository::PERIOD_MONTH);
        $prices->create($this->teamId, 'JOD', 49000, PlanPriceRepository::PERIOD_MONTH);
        RoleChecker::clearCache();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    public function testSelfHostedDefaultsLeaveEveryWorkspaceOnTheDefaultPlan(): void
    {
        self::assertSame('', SettingsRegistry::defaultFor(SettingsRegistry::WORKSPACES_ADDITIONAL_PLAN));
        self::assertSame('30', SettingsRegistry::defaultFor(SettingsRegistry::WORKSPACES_ADDITIONAL_TRIAL_DAYS));
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::WORKSPACES_ADDITIONAL_PLAN));
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::WORKSPACES_ADDITIONAL_TRIAL_DAYS));

        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $first = (int) $this->creator->createFor($pid, 'Example Bakery')['tenant_id'];
        $second = $this->creator->createFor($pid, 'Example Cafe');

        self::assertNull($second['paid_plan'] ?? null);
        self::assertNull($this->planRow($first));
        self::assertNull($this->planRow((int) $second['tenant_id']));
        self::assertNull($this->creator->stateFor($pid)['starts_on']);
    }

    public function testTheFirstOwnedWorkspaceIsOnTheDefaultPlanAndTheSecondStartsATrialOfTheAdditionalPlan(): void
    {
        $this->appWhityDevSettings();
        $pid = $this->profile('Amro Saleh', 'amro@example.test');

        // Before the first: no notice, it is free.
        self::assertNull($this->creator->stateFor($pid)['starts_on']);
        $first = $this->creator->createFor($pid, 'Example Bakery');
        self::assertNull($first['paid_plan'] ?? null);
        $firstRow = $this->planRow((int) $first['tenant_id']);
        self::assertIsArray($firstRow);
        self::assertSame($this->freeId, (int) $firstRow['plan_id']);
        self::assertNull($firstRow['status']);

        // Before the second: the screen is told the plan, trial and price.
        self::assertSame(
            [
                'plan_key' => 'team',
                'plan_name' => 'Team',
                'trial_days' => 30,
                'price' => ['unit_amount' => 6900, 'currency' => 'USD', 'billing_period' => 'month'],
            ],
            $this->creator->stateFor($pid)['starts_on']
        );

        $before = time();
        $second = $this->creator->createFor($pid, 'Example Cafe');
        self::assertSame(WorkspaceCreator::CREATED, $second['status']);
        self::assertSame('team', $second['paid_plan'] ?? null);

        $row = $this->planRow((int) $second['tenant_id']);
        self::assertIsArray($row);
        self::assertSame($this->teamId, (int) $row['plan_id']);
        self::assertSame('trialing', $row['status']);
        self::assertSame('block_writes', $row['enforcement_mode']);
        $ends = (int) strtotime((string) $row['current_period_end']);
        self::assertGreaterThanOrEqual($before + 30 * 86400 - 1, $ends);
        self::assertLessThanOrEqual(time() + 30 * 86400 + 1, $ends);
        self::assertSame($pid, (int) $row['assigned_by']);
    }

    public function testTheTrialOpensTheWorkspaceAndItsEndMakesItReadOnly(): void
    {
        $this->appWhityDevSettings();
        $pid = $this->profile('Awss Haddad', 'awss@example.test');
        $this->creator->createFor($pid, 'Example Bakery');
        $tenantId = (int) $this->creator->createFor($pid, 'Example Cafe')['tenant_id'];
        $ends = (int) strtotime((string) ($this->planRow($tenantId)['current_period_end'] ?? ''));

        $during = $this->subscriptions(fn (): int => $ends - 86400)->decide($tenantId, true);
        self::assertTrue($during->allowed);
        self::assertFalse($during->warn);

        // After it: read-only. Reading still works (with the unpaid warning),
        // a change is refused, nothing is closed.
        $read = $this->subscriptions(fn (): int => $ends + 60)->decide($tenantId, false);
        self::assertTrue($read->allowed);
        self::assertTrue($read->warn);
        $write = $this->subscriptions(fn (): int => $ends + 60)->decide($tenantId, true);
        self::assertFalse($write->allowed);
        self::assertSame('block_writes', $write->mode);
    }

    public function testATrialWithNoEndDateStaysOpenAsBefore(): void
    {
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Legacy', 'legacy', NOW())");
        $tenantId = (int) $this->pdo->lastInsertId();
        $this->subscriptions()->setSubscription($tenantId, [
            'status' => SubscriptionService::STATUS_TRIALING,
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_ALL,
        ]);

        self::assertTrue($this->subscriptions(fn (): int => time() + 365 * 86400)->decide($tenantId, true)->allowed);
    }

    public function testNoTrialMeansTheSecondWorkspaceIsReadOnlyUntilPaid(): void
    {
        $this->appWhityDevSettings();
        $this->settings->setGlobal(SettingsRegistry::WORKSPACES_ADDITIONAL_TRIAL_DAYS, '0');
        $pid = $this->profile('Ahmad Khalil', 'ahmad@example.test');
        $this->creator->createFor($pid, 'Example Bakery');
        $tenantId = (int) $this->creator->createFor($pid, 'Example Cafe')['tenant_id'];

        $row = $this->planRow($tenantId);
        self::assertIsArray($row);
        self::assertSame($this->teamId, (int) $row['plan_id']);
        self::assertSame(['expired', 'block_writes', null], [$row['status'], $row['enforcement_mode'], $row['current_period_end']]);
        self::assertTrue($this->subscriptions()->decide($tenantId, false)->allowed);
        self::assertFalse($this->subscriptions()->decide($tenantId, true)->allowed);
    }

    public function testACoOwnedWorkspaceCountsSoTheNextOneTheyCreateIsPaid(): void
    {
        $this->appWhityDevSettings();
        $owner = $this->profile('Ibrahim Mansour', 'ibrahim@example.test');
        $coOwner = $this->profile('Emad Nasser', 'emad@example.test');
        $shared = (int) $this->creator->createFor($owner, 'Example Bakery')['tenant_id'];
        $this->member($coOwner, $shared);
        $this->owners->add($shared, $coOwner, $owner);

        self::assertSame('team', $this->creator->stateFor($coOwner)['starts_on']['plan_key'] ?? null);
        $created = $this->creator->createFor($coOwner, 'Emad Studio');
        self::assertSame('team', $created['paid_plan'] ?? null);
    }

    public function testBeingInvitedNeverCountsSoTheirOwnFirstWorkspaceIsFree(): void
    {
        $this->appWhityDevSettings();
        $owner = $this->profile('Amro Saleh', 'amro@example.test');
        $staff = $this->profile('Awss Haddad', 'awss@example.test');
        foreach (['One', 'Two'] as $name) {
            $this->member($staff, (int) $this->creator->createFor($owner, $name)['tenant_id']);
        }

        self::assertNull($this->creator->stateFor($staff)['starts_on']);
        $own = $this->creator->createFor($staff, 'Awss Shop');
        self::assertNull($own['paid_plan'] ?? null);
        self::assertSame($this->freeId, (int) ($this->planRow((int) $own['tenant_id'])['plan_id'] ?? 0));
    }

    public function testTheTrialReplacesTheClosedGateWhenRegistrationRequiresPayment(): void
    {
        $this->appWhityDevSettings();
        $this->settings->setGlobal(SettingsRegistry::REGISTRATION_PAYMENT_REQUIRED, 'true');
        $pid = $this->profile('Amro Saleh', 'amro@example.test');

        $first = $this->planRow((int) $this->creator->createFor($pid, 'Example Bakery')['tenant_id']);
        self::assertIsArray($first);
        // The first keeps sign-up's own closed gate, unchanged.
        self::assertSame(['expired', 'block_all'], [$first['status'], $first['enforcement_mode']]);

        $second = $this->planRow((int) $this->creator->createFor($pid, 'Example Cafe')['tenant_id']);
        self::assertIsArray($second);
        self::assertSame(['trialing', 'block_writes'], [$second['status'], $second['enforcement_mode']]);
        self::assertSame($this->teamId, (int) $second['plan_id']);
    }

    public function testAMissingOrRetiredAdditionalPlanFallsBackToTheDefaultPlan(): void
    {
        $this->appWhityDevSettings();
        $this->settings->setGlobal(SettingsRegistry::WORKSPACES_ADDITIONAL_PLAN, 'gone');
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->creator->createFor($pid, 'Example Bakery');

        self::assertNull($this->creator->stateFor($pid)['starts_on']);
        $second = $this->creator->createFor($pid, 'Example Cafe');
        self::assertNull($second['paid_plan'] ?? null);
        self::assertSame($this->freeId, (int) ($this->planRow((int) $second['tenant_id'])['plan_id'] ?? 0));

        (new PlanRepository($this->pdo))->updatePlan($this->teamId, ['is_active' => false]);
        $this->settings->setGlobal(SettingsRegistry::WORKSPACES_ADDITIONAL_PLAN, 'team');
        self::assertNull($this->creator->createFor($pid, 'Example Deli')['paid_plan'] ?? null);
    }

    public function testTheNoticeQuotesThePriceInTheInstallationsCurrency(): void
    {
        $this->appWhityDevSettings();
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->creator->createFor($pid, 'Example Bakery');

        $this->settings->setGlobal(SettingsRegistry::BILLING_DEFAULT_CURRENCY, 'JOD');
        self::assertSame(
            ['unit_amount' => 49000, 'currency' => 'JOD', 'billing_period' => 'month'],
            $this->creator->stateFor($pid)['starts_on']['price'] ?? null
        );
    }

    public function testTheNoticeHasNoPriceWhenThePlanHasNoneInTheInstallationsCurrency(): void
    {
        $this->appWhityDevSettings();
        $this->settings->setGlobal(SettingsRegistry::BILLING_DEFAULT_CURRENCY, 'EUR');
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->creator->createFor($pid, 'Example Bakery');

        $state = $this->creator->stateFor($pid)['starts_on'];
        self::assertIsArray($state);
        self::assertSame(30, $state['trial_days']);
        self::assertNull($state['price']);
    }

    public function testTheLimitStillComesFirst(): void
    {
        $this->appWhityDevSettings();
        $this->settings->setGlobal(SettingsRegistry::WORKSPACES_MAX_OWNED_PER_PERSON, '1');
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->creator->createFor($pid, 'Example Bakery');

        $state = $this->creator->stateFor($pid);
        self::assertSame(WorkspaceCreator::LIMIT_REACHED, $state['reason']);
        self::assertNull($state['starts_on']);
        self::assertSame(WorkspaceCreator::LIMIT_REACHED, $this->creator->createFor($pid, 'Example Cafe')['status']);
    }

    public function testTheSettingsAreValidated(): void
    {
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::WORKSPACES_ADDITIONAL_PLAN, ''));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::WORKSPACES_ADDITIONAL_PLAN, 'team'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::WORKSPACES_ADDITIONAL_PLAN, 'Team Plan!'));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::WORKSPACES_ADDITIONAL_TRIAL_DAYS, '0'));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::WORKSPACES_ADDITIONAL_TRIAL_DAYS, '90'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::WORKSPACES_ADDITIONAL_TRIAL_DAYS, '91'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::WORKSPACES_ADDITIONAL_TRIAL_DAYS, '-1'));
    }

    /** What app.whity.dev is meant to run: Free first, Team for the rest. */
    private function appWhityDevSettings(): void
    {
        $this->settings->setGlobal(SettingsRegistry::BILLING_DEFAULT_PLAN, 'free');
        $this->settings->setGlobal(SettingsRegistry::WORKSPACES_ADDITIONAL_PLAN, 'team');
    }

    private function subscriptions(?callable $clock = null): SubscriptionService
    {
        return new SubscriptionService(new SubscriptionRepository($this->pdo), $this->settings, $clock);
    }

    /** @return array<string, mixed>|null */
    private function planRow(int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT plan_id, assigned_by, status, current_period_end, enforcement_mode FROM tenant_plan WHERE tenant_id = :t'
        );
        $stmt->execute([':t' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function profile(string $displayName, string $email): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (:n, '', false, NULL, 0, 0, 'active', NOW(), NOW())"
        )->execute([':n' => $displayName]);
        $id = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($id, $email, true, true);
        return $id;
    }

    /** An active staff membership with the base admin role, not ownership. */
    private function member(int $profileId, int $tenantId): void
    {
        $find = $this->pdo->prepare("SELECT id FROM roles WHERE name = 'admin' AND tenant_id IS NULL");
        $find->execute();
        $role = $find->fetchColumn();
        self::assertNotFalse($role);
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES (:p, :t, :r, NULL, false, 'active', NOW())"
        )->execute([':p' => $profileId, ':t' => $tenantId, ':r' => (int) $role]);
    }
}
