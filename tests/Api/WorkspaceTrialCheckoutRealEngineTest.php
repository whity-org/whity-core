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
use Whity\Core\Billing\WorkspaceTrial;
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
use Whity\Sdk\Billing\CheckoutHandoff;
use Whity\Sdk\Billing\PlanPriceTerms;
use Whity\Sdk\Billing\TrialCheckout;
use Whity\Sdk\Http\Request;

/**
 * The payment step for a further workspace (WHIT-1207, owner 2026-11-18): the
 * card is taken at creation and the first charge is when the 30-day trial
 * ends; "Not now" keeps the trial, then read-only.
 *
 * What these pin:
 *   - GET /billing/trial says where the workspace stands, and offers the card
 *     step only when the provider can keep the trial free, the trial still
 *     runs and no card is on file;
 *   - checkout during the trial keeps the rest of it (days from core's own
 *     record, never the request), and only for the workspace's own plan;
 *   - after the trial, or for another plan, checkout charges now as before;
 *   - the payment step's checkout comes back to the setup flow.
 */
final class WorkspaceTrialCheckoutRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const ADMIN = 10;

    private PDO $pdo;
    private PluginBillingPortals $slot;
    private SettingsService $settings;
    private SubscriptionService $subscriptions;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO app_settings (setting_key, value) VALUES ('billing.default_currency', 'USD')");
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'example-cafe', 'example-cafe')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
            VALUES (10, 1, 1, 'active', CURRENT_TIMESTAMP)
        ");
        foreach ([CorePermissions::BILLING_PAY, CorePermissions::BILLING_VIEW] as $permission) {
            $this->grant(1, $permission);
        }

        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, created_at, updated_at)
                          VALUES (1, 'team', 'Team', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, created_at, updated_at)
                          VALUES (2, 'business', 'Business', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO plan_prices (id, plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, created_at, updated_at)
                          VALUES (7, 1, 'USD', 6900, 'month', false, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO plan_prices (id, plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, created_at, updated_at)
                          VALUES (8, 2, 'USD', 19900, 'month', false, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $this->subscriptions = new SubscriptionService(new SubscriptionRepository($this->pdo), $this->settings);
        $this->slot = new PluginBillingPortals();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAWorkspaceOnTheDefaultPlanHasNoTrial(): void
    {
        $this->slot->offer('WhityPay', new FakeTrialPortal());

        $response = $this->handler()->trial($this->asTenant('GET', '/api/v1/billing/trial'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => null], $this->body($response));
    }

    public function testATrialWithNoCardOffersTheCardStep(): void
    {
        $this->slot->offer('WhityPay', new FakeTrialPortal());
        $this->trial('+30 days');

        $state = $this->body($this->handler()->trial($this->asTenant('GET', '/api/v1/billing/trial')))['data'];

        self::assertSame('team', $state['plan_key']);
        self::assertSame('Team', $state['plan_name']);
        self::assertSame('trialing', $state['status']);
        self::assertSame(30, $state['days_left']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $state['trial_ends_at']);
        self::assertFalse($state['read_only']);
        self::assertFalse($state['card_added']);
        self::assertSame(['unit_amount' => 6900, 'currency' => 'USD', 'billing_period' => 'month'], $state['price']);
        self::assertTrue($state['pay_online']);
        self::assertTrue($state['card_step']);
    }

    public function testTheCardStepIsNotOfferedWhenTheProviderCannotKeepTheTrialFree(): void
    {
        $this->slot->offer('WhityPay', new FakePlainPortal());
        $this->trial('+30 days');

        $state = $this->body($this->handler()->trial($this->asTenant('GET', '/api/v1/billing/trial')))['data'];

        self::assertTrue($state['pay_online']);
        self::assertFalse($state['card_step']);
    }

    public function testAProviderThatSaysItCannotTakeTrialsChargesNow(): void
    {
        $portal = new FakeTrialPortal();
        $portal->trials = false;
        $this->slot->offer('WhityPay', $portal);
        $this->trial('+30 days');

        $state = $this->body($this->handler()->trial($this->asTenant('GET', '/api/v1/billing/trial')))['data'];
        $response = $this->handler()->checkout($this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'team']));

        self::assertFalse($state['card_step']);
        self::assertSame(['url' => 'https://provider.example.test/now'], $this->body($response)['data']);
        self::assertNull($portal->lastTrialDays);
    }

    public function testWithNoProviderNothingCanBePaidOnline(): void
    {
        $this->trial('+30 days');

        $state = $this->body($this->handler()->trial($this->asTenant('GET', '/api/v1/billing/trial')))['data'];

        self::assertFalse($state['pay_online']);
        self::assertFalse($state['card_step']);
    }

    public function testACardOnFileEndsTheCardStep(): void
    {
        $this->slot->offer('WhityPay', new FakeTrialPortal());
        $this->trial('+20 days', 'sub_123');

        $state = $this->body($this->handler()->trial($this->asTenant('GET', '/api/v1/billing/trial')))['data'];

        self::assertTrue($state['card_added']);
        self::assertFalse($state['card_step']);
    }

    public function testAnEndedUnpaidTrialIsReadOnly(): void
    {
        $this->slot->offer('WhityPay', new FakeTrialPortal());
        $this->trial('-1 day');

        $state = $this->body($this->handler()->trial($this->asTenant('GET', '/api/v1/billing/trial')))['data'];

        self::assertTrue($state['read_only']);
        self::assertSame(0, $state['days_left']);
        self::assertFalse($state['card_step']);
    }

    public function testCheckoutDuringTheTrialKeepsTheRestOfItFree(): void
    {
        $portal = new FakeTrialPortal();
        $this->slot->offer('WhityPay', $portal);
        $this->trial('+12 days');

        $response = $this->handler()->checkout($this->asTenant('POST', '/api/v1/billing/checkout', [
            'plan_key' => 'team',
            // A caller can't choose the length: only core's record counts.
            'trial_days' => 90,
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['url' => 'https://provider.example.test/trial', 'trial_days' => 12], $this->body($response)['data']);
        self::assertSame(12, $portal->lastTrialDays);
        self::assertSame('prov_price_7', $portal->lastPrice);
        self::assertSame('tenant-1', $portal->lastSubject);
        self::assertSame('https://app.example.test/billing/return', $portal->lastReturnUrl);
        self::assertSame('https://app.example.test/billing', $portal->lastCancelUrl);
    }

    public function testThePaymentStepComesBackToTheSetupFlow(): void
    {
        $portal = new FakeTrialPortal();
        $this->slot->offer('WhityPay', $portal);
        $this->trial('+30 days');

        $this->handler()->checkout($this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'team', 'after' => 'setup']));

        self::assertSame('https://app.example.test/setup/pay/done', $portal->lastReturnUrl);
        self::assertSame('https://app.example.test/setup/pay', $portal->lastCancelUrl);
    }

    public function testAnAddressInAfterIsIgnored(): void
    {
        $portal = new FakeTrialPortal();
        $this->slot->offer('WhityPay', $portal);
        $this->trial('+30 days');

        $this->handler()->checkout($this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'team', 'after' => 'https://evil.example.test']));

        self::assertSame('https://app.example.test/billing/return', $portal->lastReturnUrl);
    }

    public function testAfterTheTrialCheckoutChargesNow(): void
    {
        $portal = new FakeTrialPortal();
        $this->slot->offer('WhityPay', $portal);
        $this->trial('-2 days');

        $response = $this->handler()->checkout($this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'team']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['url' => 'https://provider.example.test/now'], $this->body($response)['data']);
        self::assertNull($portal->lastTrialDays);
    }

    public function testAnotherPlanIsChargedNow(): void
    {
        $portal = new FakeTrialPortal();
        $this->slot->offer('WhityPay', $portal);
        $this->trial('+30 days');

        $response = $this->handler()->checkout($this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'business']));

        self::assertSame(['url' => 'https://provider.example.test/now'], $this->body($response)['data']);
        self::assertNull($portal->lastTrialDays);
        self::assertSame('prov_price_8', $portal->lastPrice);
    }

    public function testAProviderWithoutTrialsChargesNowAsBillingAlwaysDid(): void
    {
        $portal = new FakePlainPortal();
        $this->slot->offer('WhityPay', $portal);
        $this->trial('+30 days');

        $response = $this->handler()->checkout($this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'team']));

        self::assertSame(['url' => 'https://provider.example.test/now'], $this->body($response)['data']);
    }

    public function testTrialDaysRoundUpAndNeverReachZeroWhileItRuns(): void
    {
        $trial = new WorkspaceTrial($this->pdo, $this->subscriptions, $this->settings);
        $this->trial('+90 minutes');

        self::assertSame(1, $trial->trialDaysForCheckout(self::TENANT, 'team'));
        self::assertSame(1, $trial->stateFor(self::TENANT)['days_left'] ?? null);
        self::assertNull($trial->trialDaysForCheckout(self::TENANT, 'business'));
    }

    private function handler(): ExternalBillingApiHandler
    {
        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();

        $handler = new ExternalBillingApiHandler(
            new ResolvingBillingPortal(new NullBillingPortal(), $this->slot),
            new AccessRecorder($this->subscriptions, new PlanRepository($this->pdo), new NullLogger()),
            new EventLedger(new ArraySharedStore()),
            new WebhookVerifier(''),
            $this->settings,
            new RoleChecker($database, new PermissionRegistry()),
            $this->pdo,
            'https://app.example.test',
            new NullLogger(),
        );
        $handler->useWorkspaceTrial(new WorkspaceTrial($this->pdo, $this->subscriptions, $this->settings));

        return $handler;
    }

    /** The further workspace's plan, as WHIT-1203 writes it, ending at $ends. */
    private function trial(string $ends, ?string $externalRef = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO tenant_plan (tenant_id, plan_id, assigned_by, assigned_at, status, current_period_end, enforcement_mode, external_ref)
             VALUES (1, 1, 10, CURRENT_TIMESTAMP, :status, :ends, :mode, :ref)'
        )->execute([
            ':status' => 'trialing',
            ':ends'   => date('Y-m-d H:i:s', (int) strtotime($ends) - 30),
            ':mode'   => SubscriptionService::MODE_BLOCK_WRITES,
            ':ref'    => $externalRef,
        ]);
    }

    /** @param array<string, mixed>|null $body */
    private function asTenant(string $method, string $path, ?array $body = null): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $request = new Request($method, $path, [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => self::ADMIN];

        return $request;
    }

    /** @return array<string, mixed> */
    private function body(\Whity\Sdk\Http\Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded;
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

/** A provider plugin that can only charge now. */
class FakePlainPortal implements BillingPortal
{
    public ?string $lastPrice = null;
    public ?string $lastSubject = null;
    public ?string $lastReturnUrl = null;
    public ?string $lastCancelUrl = null;
    public ?int $lastTrialDays = null;

    public function name(): string
    {
        return 'whity-pay';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function capabilities(): BillingCapabilities
    {
        return new BillingCapabilities(true, true, true, true, true, ['USD']);
    }

    public function priceRefFor(PlanPriceTerms $terms): string
    {
        return 'prov_price_' . $terms->priceId;
    }

    public function startCheckout(string $actor, string $subjectRef, string $priceRef, string $returnUrl, ?string $cancelUrl = null, array $payer = []): CheckoutHandoff
    {
        $this->lastPrice = $priceRef;
        $this->lastSubject = $subjectRef;
        $this->lastReturnUrl = $returnUrl;
        $this->lastCancelUrl = $cancelUrl;

        return new CheckoutHandoff('https://provider.example.test/now', 'cs_now');
    }

    public function accessFor(string $subjectRef): AccessSnapshot
    {
        return AccessSnapshot::none($subjectRef);
    }

    public function checkoutStatus(string $reference): string
    {
        return 'complete';
    }

    public function receiptsFor(string $subjectRef): array
    {
        return [];
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
        return 'https://provider.example.test/portal';
    }

    public function refund(string $actor, string $subjectRef, string $receiptRef, ?int $amountMinor = null): void
    {
    }

    public function verifyNotification(string $rawBody, array $headers): ?BillingNotice
    {
        return null;
    }
}

/** A provider plugin that can take the card now and charge when the trial ends (SDK 1.118). */
final class FakeTrialPortal extends FakePlainPortal implements TrialCheckout
{
    public bool $trials = true;

    public function takesTrials(): bool
    {
        return $this->trials;
    }

    public function startCheckoutWithTrial(string $actor, string $subjectRef, string $priceRef, int $trialDays, string $returnUrl, ?string $cancelUrl = null, array $payer = []): CheckoutHandoff
    {
        $this->lastPrice = $priceRef;
        $this->lastSubject = $subjectRef;
        $this->lastReturnUrl = $returnUrl;
        $this->lastCancelUrl = $cancelUrl;
        $this->lastTrialDays = $trialDays;

        return new CheckoutHandoff('https://provider.example.test/trial', 'cs_trial');
    }
}
