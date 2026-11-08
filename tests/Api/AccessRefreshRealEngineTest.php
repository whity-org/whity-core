<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Billing\External\AccessRecorder;
use Whity\Core\Billing\External\AccessSnapshot;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\HostAccessRefresh;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;

/**
 * Any plugin may ask the host to re-read a workspace's access (WHIT-1013).
 * Asking can only ever make the host record what the PORTAL reports: no state
 * goes in, repeated calls are throttled, and the call is audited.
 */
final class AccessRefreshRealEngineTest extends TestCase
{
    private PDO $pdo;
    private FakeBillingPortal $portal;
    private ArraySharedStore $store;
    private HostAccessRefresh $refresh;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a-customer', 'a-customer')");
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, created_at, updated_at)
                          VALUES (1, 'pro', 'Pro', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($this->pdo);

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->portal = new FakeBillingPortal();
        $this->store = new ArraySharedStore();
        $this->refresh = new HostAccessRefresh(
            $this->portal,
            new AccessRecorder(new SubscriptionService(new SubscriptionRepository($this->pdo), $settings), new PlanRepository($this->pdo), new NullLogger()),
            $this->store,
        );
    }

    public function testItRecordsExactlyWhatThePortalReports(): void
    {
        $this->portal->access = new AccessSnapshot('tenant-1', true, 'pro', 'active', '2026-12-08T00:00:00Z', false, 'sub_1', null, true);

        self::assertTrue($this->refresh->refresh('tenant-1'));

        self::assertSame(['active', 'sub_1', 1], $this->row());
    }

    public function testAnotherPluginAskingCannotGrantWhatThePortalDoesNot(): void
    {
        // Recorded as active from an earlier (say, test-mode) subscription...
        $this->portal->access = new AccessSnapshot('tenant-1', true, 'pro', 'active', null, false, 'sub_test', null, true);
        $this->refresh->refresh('tenant-1');
        $this->store->delete('billing:refresh:tenant-1');

        // ...and now the portal reports nothing. Whoever asks, and however often,
        // the only outcome is what the portal says: the workspace lapses.
        $this->portal->access = AccessSnapshot::none('tenant-1');
        self::assertTrue($this->refresh->refresh('tenant-1'));

        self::assertSame('expired', $this->row()[0]);
    }

    public function testARepeatInsideTheWindowIsANoOp(): void
    {
        $this->portal->access = new AccessSnapshot('tenant-1', true, 'pro', 'active', null, false, 'sub_1', null, true);

        self::assertTrue($this->refresh->refresh('tenant-1'));
        self::assertFalse($this->refresh->refresh('tenant-1'));

        self::assertSame(1, $this->portal->accessCalls, 'the provider was asked once');
    }

    public function testOnlyWorkspacesAndOnlyWithAPortal(): void
    {
        self::assertFalse($this->refresh->refresh('install:abc'));
        self::assertFalse($this->refresh->refresh('tenant-0'));
        self::assertFalse($this->refresh->refresh('tenant-1 OR 1=1'));

        $this->portal->configured = false;
        self::assertFalse($this->refresh->refresh('tenant-1'));
        self::assertSame(0, $this->portal->accessCalls);
    }

    public function testAFailedReadCanBeRetriedAtOnce(): void
    {
        $this->portal->failWith = BillingPortalException::unreachable('down');
        self::assertFalse($this->refresh->refresh('tenant-1'));

        $this->portal->failWith = null;
        $this->portal->access = new AccessSnapshot('tenant-1', true, 'pro', 'active', null, false, 'sub_1', null, true);
        self::assertTrue($this->refresh->refresh('tenant-1'), 'a failure does not hold the throttle');
    }

    /** @return array{0: ?string, 1: ?string, 2: ?int} */
    private function row(): array
    {
        $statement = $this->pdo->query('SELECT status, external_ref, plan_id FROM tenant_plan WHERE tenant_id = 1');
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            ? [$row['status'] === null ? null : (string) $row['status'], $row['external_ref'] === null ? null : (string) $row['external_ref'], $row['plan_id'] === null ? null : (int) $row['plan_id']]
            : [null, null, null];
    }
}
