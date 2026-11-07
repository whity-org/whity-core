<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\ExternalBillingApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Billing\External\AccessRecorder;
use Whity\Core\Billing\External\EventLedger;
use Whity\Core\Billing\External\NullBillingPortal;
use Whity\Core\Billing\External\PluginBillingPortals;
use Whity\Core\Billing\External\ResolvingBillingPortal;
use Whity\Core\Billing\External\WebhookVerifier;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Billing\AccessSnapshot;
use Whity\Sdk\Billing\BillingCapabilities;
use Whity\Sdk\Billing\BillingNotice;
use Whity\Sdk\Billing\BillingPortal;
use Whity\Sdk\Billing\BillingPortalException;
use Whity\Sdk\Billing\CheckoutHandoff;
use Whity\Sdk\Billing\CheckoutPage;
use Whity\Sdk\Billing\HostedCheckoutPage;
use Whity\Sdk\Billing\PlanPriceTerms;
use Whity\Sdk\Billing\Receipt;
use Whity\Sdk\Http\Request;

/**
 * A payment-provider plugin as the deployment's billing portal (WHIT-1010).
 *
 * The same handler, recorder and ledger as the hosted service, with a plugin's
 * SDK portal behind {@see ResolvingBillingPortal}. What these pin:
 *
 *   - the plugin is used only when no hosted service is configured;
 *   - the price sent to the provider is resolved from core's own plan price,
 *     with no external_ref needed;
 *   - a provider notification is verified by the plugin, acted on once, and
 *     never a write path: access comes from accessFor(), and the plan it
 *     names is applied (and with it the plan's entitlements);
 *   - refunds are the system workspace's alone.
 */
final class ProviderBillingPortalRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const ADMIN = 10;

    private PDO $pdo;
    private ExternalBillingApiHandler $handler;
    private FakeProviderPortal $plugin;
    private PluginBillingPortals $slot;
    private ArraySharedStore $store;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a-customer', 'a-customer')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        foreach ([0, 1] as $tenant) {
            $this->pdo->exec("
                INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
                VALUES (10, {$tenant}, 1, 'active', CURRENT_TIMESTAMP)
            ");
        }
        foreach ([CorePermissions::BILLING_PAY, CorePermissions::BILLING_VIEW, CorePermissions::PLANS_MANAGE] as $permission) {
            $this->grant(1, $permission);
        }

        // A plan priced in core with NO external_ref: the plugin makes its own.
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, created_at, updated_at)
                          VALUES (1, 'pro', 'Pro', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO plan_prices (id, plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, created_at, updated_at)
                          VALUES (7, 1, 'JOD', 15000, 'month', false, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($this->pdo);

        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $subscriptions = new SubscriptionService(new SubscriptionRepository($this->pdo), $settings);

        $this->plugin = new FakeProviderPortal();
        $this->slot = new PluginBillingPortals();
        $this->slot->offer('WhityPay', $this->plugin);
        $this->store = new ArraySharedStore();

        $this->handler = new ExternalBillingApiHandler(
            new ResolvingBillingPortal(new NullBillingPortal(), $this->slot),
            new AccessRecorder($subscriptions, new PlanRepository($this->pdo), new NullLogger()),
            new EventLedger($this->store),
            new WebhookVerifier(''),
            $settings,
            new RoleChecker($database, new PermissionRegistry()),
            $this->pdo,
            'https://app.example.test',
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testASecondPluginCannotTakeTheSlot(): void
    {
        self::assertFalse($this->slot->offer('Other', new FakeProviderPortal()));
        self::assertSame('WhityPay', $this->slot->ownerName());
        self::assertSame($this->plugin, $this->slot->portal());
    }

    public function testTheHostedServiceWinsWhenItIsConfigured(): void
    {
        $hosted = new FakeBillingPortal();
        $resolving = new ResolvingBillingPortal($hosted, $this->slot);

        self::assertSame($hosted, $resolving->active());
        self::assertNull($resolving->pluginPortal());
    }

    public function testAnUnconfiguredPluginIsNotUsed(): void
    {
        $this->plugin->configured = false;
        $resolving = new ResolvingBillingPortal(new NullBillingPortal(), $this->slot);

        self::assertInstanceOf(NullBillingPortal::class, $resolving->active());
    }

    public function testPlansAreSellableWithoutAnExternalRef(): void
    {
        $response = $this->handler->plans($this->asTenant('GET', '/api/v1/billing/plans'));

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->json($response);
        self::assertCount(1, $rows);
        self::assertSame('pro', $rows[0]['plan_key']);
    }

    public function testPlansInACurrencyTheProviderDoesNotTakeAreNotOffered(): void
    {
        $this->plugin->currencies = ['USD'];

        $rows = $this->json($this->handler->plans($this->asTenant('GET', '/api/v1/billing/plans')));

        self::assertSame([], $rows);
    }

    public function testCheckoutSendsCoresOwnPriceToThePlugin(): void
    {
        $response = $this->handler->checkout($this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'pro']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://provider.example.test/c/cs_1', $this->json($response)['url']);
        self::assertNotNull($this->plugin->lastTerms);
        self::assertSame(7, $this->plugin->lastTerms->priceId);
        self::assertSame(15000, $this->plugin->lastTerms->unitAmountMinor, 'the amount is core\'s, in minor units');
        self::assertSame('pro', $this->plugin->lastTerms->planKey);
        self::assertSame('prov_price_7', $this->plugin->lastPrice);
        self::assertSame('tenant-1', $this->plugin->lastSubject);
    }

    public function testAPriceTheProviderRefusesCannotBeBought(): void
    {
        $this->plugin->refusePrice = true;

        $response = $this->handler->checkout($this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'pro']));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testAnUnverifiedNotificationIsRefusedAndReadsNothing(): void
    {
        $this->plugin->verifies = false;

        $response = $this->handler->providerWebhook($this->notification());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->plugin->accessCalls);
    }

    public function testAVerifiedNotificationRecordsWhatTheProviderSaysNow(): void
    {
        $this->plugin->access = new AccessSnapshot('tenant-1', true, 'pro', 'active', '2026-12-07T00:00:00Z', false, 'sub_1');

        $response = $this->handler->providerWebhook($this->notification());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->plugin->accessCalls);
        $statement = $this->pdo->query('SELECT status, external_ref, plan_id FROM tenant_plan WHERE tenant_id = 1');
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
        self::assertSame('sub_1', $row['external_ref']);
        self::assertSame(1, (int) $row['plan_id'], 'the plan the provider names is applied');
    }

    public function testARedeliveryIsActedOnOnce(): void
    {
        $this->plugin->access = new AccessSnapshot('tenant-1', true, 'pro', 'active', null, false, 'sub_1');

        $this->handler->providerWebhook($this->notification());
        $second = $this->handler->providerWebhook($this->notification());

        self::assertSame(200, $second->getStatusCode());
        self::assertSame(1, $this->plugin->accessCalls);
        self::assertSame(1, $this->store->count('billing:event:whity-pay:evt_1') > 0 ? 1 : 0, 'claimed under the portal name');
    }

    public function testANotificationForNoTenantIsAcknowledgedAndIgnored(): void
    {
        $this->plugin->noticeSubject = 'customer-somewhere-else';

        $response = $this->handler->providerWebhook($this->notification());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->plugin->accessCalls);
    }

    public function testNoPluginMeansNoProviderWebhook(): void
    {
        $this->plugin->configured = false;

        self::assertSame(404, $this->handler->providerWebhook($this->notification())->getStatusCode());
    }

    public function testTheCustomerPortalIsForThisWorkspaceOnly(): void
    {
        $response = $this->handler->customerPortal($this->asTenant('POST', '/api/v1/billing/portal'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://provider.example.test/portal/tenant-1', $this->json($response)['url']);
    }

    public function testAWorkspaceCannotRefundItself(): void
    {
        $response = $this->handler->refund($this->asTenant('POST', '/api/v1/billing/refunds', [
            'tenant_id' => 1,
            'receipt' => 'in_1',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->plugin->lastRefund);
    }

    public function testTheOperatorRefundsThroughThePlugin(): void
    {
        $response = $this->handler->refund($this->asTenant('POST', '/api/v1/billing/refunds', [
            'tenant_id' => 1,
            'receipt' => 'in_1',
            'amount_minor' => 5000,
        ], 0));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['tenant-1', 'in_1', 5000], $this->plugin->lastRefund);
    }

    public function testRefundsAreRefusedWhereTheProviderHasNone(): void
    {
        $this->plugin->refunds = false;

        $response = $this->handler->refund($this->asTenant('POST', '/api/v1/billing/refunds', [
            'tenant_id' => 1,
            'receipt' => 'in_1',
        ], 0));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testTheCheckoutPageIsServedWithThePluginsPolicy(): void
    {
        $response = $this->handler->checkoutPage(new Request('GET', '/api/v1/billing/checkout-page?_ptxn=txn_1'));

        self::assertSame(200, $response->getStatusCode());
        $headers = array_change_key_case($response->getHeaders());
        self::assertSame('text/html; charset=utf-8', $headers['content-type']);
        self::assertStringContainsString('cdn.example.test', $headers['content-security-policy']);
        self::assertSame('no-store', $headers['cache-control']);
    }

    public function testAnUnknownCheckoutPageIs404(): void
    {
        self::assertSame(404, $this->handler->checkoutPage(new Request('GET', '/api/v1/billing/checkout-page?_ptxn=txn_other'))->getStatusCode());
    }

    private function notification(): Request
    {
        return new Request('POST', '/api/v1/billing/provider-webhook', ['Stripe-Signature' => 't=1,v1=x'], '{"id":"evt_1"}');
    }

    /** @param array<string, mixed>|null $body */
    private function asTenant(string $method, string $path, ?array $body = null, int $tenant = self::TENANT): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenant);

        $request = new Request($method, $path, [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => self::ADMIN];

        return $request;
    }

    /** @return array<mixed> */
    private function json(\Whity\Sdk\Http\Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded['data'] ?? $decoded;
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $select = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $select->execute([$permission]);
        $this->pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, (int) $select->fetchColumn()]);
    }
}

/** A provider plugin's portal, recording what core asked of it. */
final class FakeProviderPortal implements BillingPortal, HostedCheckoutPage
{
    public bool $configured = true;
    public bool $verifies = true;
    public bool $refunds = true;
    public bool $refusePrice = false;
    /** @var list<string> */
    public array $currencies = [];
    public string $noticeSubject = 'tenant-1';
    public ?AccessSnapshot $access = null;
    public int $accessCalls = 0;
    public ?PlanPriceTerms $lastTerms = null;
    public ?string $lastPrice = null;
    public ?string $lastSubject = null;
    /** @var array{0: string, 1: string, 2: ?int}|null */
    public ?array $lastRefund = null;

    public function name(): string
    {
        return 'whity-pay';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function capabilities(): BillingCapabilities
    {
        return new BillingCapabilities(true, true, $this->refunds, true, true, $this->currencies);
    }

    public function priceRefFor(PlanPriceTerms $terms): string
    {
        $this->lastTerms = $terms;
        if ($this->refusePrice) {
            throw BillingPortalException::refused('currency');
        }

        return 'prov_price_' . $terms->priceId;
    }

    public function startCheckout(string $actor, string $subjectRef, string $priceRef, string $returnUrl, ?string $cancelUrl = null, array $payer = []): CheckoutHandoff
    {
        $this->lastPrice = $priceRef;
        $this->lastSubject = $subjectRef;

        return new CheckoutHandoff('https://provider.example.test/c/cs_1', 'cs_1');
    }

    public function accessFor(string $subjectRef): AccessSnapshot
    {
        $this->accessCalls++;

        return $this->access ?? AccessSnapshot::none($subjectRef);
    }

    public function checkoutStatus(string $reference): string
    {
        return 'complete';
    }

    public function receiptsFor(string $subjectRef): array
    {
        return [new Receipt('in_1', 'INV-1', 'paid', 15000, 'JOD', '2026-11-07T00:00:00Z')];
    }

    public function subscriptionsFor(string $subjectRef): array
    {
        return [];
    }

    public function changeQuantity(string $actor, string $subscriptionRef, int $quantity): void
    {
    }

    public function changePlan(string $actor, string $subscriptionRef, string $priceRef, string $proration = self::PRORATION_IMMEDIATE, bool $invoice = true, ?string $idempotencyKey = null): void
    {
    }

    public function customerPortalUrl(string $subjectRef, string $returnUrl): string
    {
        return 'https://provider.example.test/portal/' . $subjectRef;
    }

    public function refund(string $actor, string $subjectRef, string $receiptRef, ?int $amountMinor = null): void
    {
        $this->lastRefund = [$subjectRef, $receiptRef, $amountMinor];
    }

    public function checkoutPage(array $query): ?CheckoutPage
    {
        return ($query['_ptxn'] ?? '') === 'txn_1'
            ? new CheckoutPage('<!doctype html><title>Pay</title>', "default-src 'none'; script-src https://cdn.example.test")
            : null;
    }

    public function verifyNotification(string $rawBody, array $headers): BillingNotice
    {
        if (!$this->verifies) {
            throw BillingPortalException::unverified('bad signature');
        }

        return new BillingNotice('evt_1', $this->noticeSubject);
    }
}
