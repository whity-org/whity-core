<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Workspace;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\Plugin\Workspace\PluginLimitPacks;
use Whity\Core\Seat\SeatService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-1156. Owner: "no need for the 5 limit if they pay by chair". While Whity
 * for Salons is on in a Free workspace (trial, paid, cash, grace), the Salons
 * add-on plan's `members.max = -1` lifts the staff limit; when it goes
 * read-only, the Free limit is back and nobody is removed.
 */
final class PluginLimitPacksRealEngineTest extends TestCase
{
    private const T = 5;

    private PDO $pdo;
    private int $now;
    private CodePluginActivation $activation;
    private EntitlementService $entitlements;
    private SettingsService $settings;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (5, 'example-barbers')");
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, sort_order, is_addon, created_at, updated_at) VALUES
            (30, 'free', 'Free', true, 1, false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (40, 'plugin.salons.monthly', 'Whity for Salons', true, 2, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (41, 'plugin.salons.yearly', 'Whity for Salons', true, 3, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO plan_entitlements (plan_id, entitlement_key, value) VALUES
            (30, 'members.max', '2'), (30, 'customers.per_month', '100'),
            (40, 'members.max', '-1'), (40, 'salons.access', 'true'),
            (41, 'members.max', '-1'), (41, 'salons.access', 'true')");
        $this->pdo->exec("INSERT INTO tenant_plan (tenant_id, plan_id, status, assigned_at) VALUES (5, 30, 'active', CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        for ($id = 11; $id <= 16; $id++) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$id}, 'p{$id}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        for ($id = 11; $id <= 15; $id++) {
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, 5, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }

        $this->now = (int) strtotime('2026-11-16 10:00:00 UTC');
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->activation = new CodePluginActivation($this->pdo, fn (): int => $this->now);
        $paid = static fn (): array => ['Salons' => ['entitlement' => 'salons.access', 'product' => 'plugin.salons', 'trialDays' => 14]];
        $this->activation->usePaidAccess($paid);
        $this->activation->useEntitlements(function (int $t, string $k): bool {
            try {
                return $this->entitlements->isGranted($t, $k);
            } catch (\Throwable) {
                return false;
            }
        });
        $packs = new PluginLimitPacks($this->pdo, $this->activation, $paid);
        $this->entitlements->usePluginPacks(static fn (int $t): array => $packs->packsFor($t));
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setGlobal(SettingsRegistry::SEATS_ENFORCEMENT, 'block');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function staffLimit(): int
    {
        $this->activation->resetRequest();

        return $this->entitlements->limit(self::T, EntitlementRegistry::MEMBERS_MAX);
    }

    /** @return array<string, string> */
    private function packValues(): array
    {
        $packs = new PluginLimitPacks($this->pdo, $this->activation, static fn (): array => ['Salons' => ['entitlement' => 'salons.access', 'product' => 'plugin.salons', 'trialDays' => 14]]);
        $this->activation->resetRequest();

        return $packs->packsFor(self::T)[0]['values'] ?? [];
    }

    public function testFreeAloneStopsAtTwoStaff(): void
    {
        self::assertSame(2, $this->staffLimit());
        self::assertFalse((new SeatService($this->pdo, $this->entitlements, $this->settings))->decide(self::T)->allowed);
    }

    public function testTheTrialLiftsItButNeverGrantsItsOwnAccess(): void
    {
        $this->activation->startTrial(self::T, 'Salons', null);

        self::assertSame(EntitlementRegistry::UNLIMITED, $this->staffLimit());
        self::assertTrue((new SeatService($this->pdo, $this->entitlements, $this->settings))->decide(self::T)->allowed);
        self::assertArrayNotHasKey('salons.access', $this->packValues(), 'Only number limits: a trial never entitles itself');
        self::assertSame(100, $this->entitlements->limit(self::T, EntitlementRegistry::CUSTOMERS_PER_MONTH), 'Customers stay as they are');
        self::assertSame('trial', $this->activation->stateFor('Salons', self::T)['reason']);
    }

    public function testGraceKeepsItThenReadOnlyEndsItAndNobodyIsRemoved(): void
    {
        $this->activation->startTrial(self::T, 'Salons', null);
        $this->now += 16 * 86400; // trial over: 7 days' grace
        self::assertSame(EntitlementRegistry::UNLIMITED, $this->staffLimit());

        $this->now += 7 * 86400; // read-only
        self::assertSame(2, $this->staffLimit());
        $count = $this->pdo->query("SELECT COUNT(*) FROM memberships WHERE tenant_id = 5 AND status = 'active'");
        self::assertNotFalse($count);
        self::assertSame(5, (int) $count->fetchColumn());
    }

    public function testABoughtAddonIsItsOwnPackNotCountedTwice(): void
    {
        $this->pdo->exec('INSERT INTO tenant_plan_addons (tenant_id, plan_id, quantity, assigned_at) VALUES (5, 40, 3, CURRENT_TIMESTAMP)');
        $this->activation->setEnabled(self::T, 'Salons', true);

        self::assertSame(EntitlementRegistry::UNLIMITED, $this->staffLimit());
        $packs = new PluginLimitPacks($this->pdo, $this->activation, static fn (): array => ['Salons' => ['entitlement' => 'salons.access', 'product' => 'plugin.salons', 'trialDays' => 14]]);
        self::assertSame([], $packs->packsFor(self::T));
    }

    public function testOffInTheWorkspaceLiftsNothing(): void
    {
        self::assertSame(2, $this->staffLimit());
        $packs = new PluginLimitPacks($this->pdo, $this->activation, static fn (): array => ['Salons' => ['entitlement' => 'salons.access', 'product' => 'plugin.salons', 'trialDays' => 14]]);
        self::assertSame([], $packs->packsFor(self::T));
    }
}
