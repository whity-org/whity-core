<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Billing\WorkspacePause;
use Whity\Core\Billing\WorkspaceTrial;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\WorkspaceOwners;
use Whity\Http\Middleware\PaymentWall;
use Whity\Sdk\Http\Request as SdkRequest;
use Whity\Sdk\Http\Response;

/** @internal Records the tokens minted, to read the switched session's claims. */
final class ExpiredSwitchJwtParser extends JwtParser
{
    /** @var array<string, mixed>|null */
    public ?array $lastAccess = null;

    /** @param array<string, mixed> $payload */
    public function create(array $payload, int $expiresIn = 3600, string $type = 'access'): string
    {
        if ($type === 'access') {
            $this->lastAccess = $payload;
        }

        return parent::create($payload, $expiresIn, $type);
    }
}

/**
 * Switching into an EXPIRED workspace (2026-11-22, Almolakhas Org. on
 * app.whity.dev): `POST /api/v1/auth/switch-tenant` answered 402, so the owner
 * of an expired workspace could never open it to reach Billing and pay.
 *
 * Now the switch succeeds and the session is minted, but the workspace stays
 * as locked as before: on `block_all` (how sign-up gates an unpaid workspace)
 * every normal page and every change is still 402, and only Billing, the plan
 * picker, checkout and the caller's own capabilities stay open. People who
 * can't pay learn it is paused and who its owners are. The trial-ended path
 * is pinned unchanged alongside.
 */
final class ExpiredWorkspaceSwitchTest extends TestCase
{
    private const SECRET = 'expired-workspace-switch-secret-padded-to-32-bytes';

    private const HOME = 1;      // where the person is now
    private const EXPIRED = 2;   // expired, no plan (Almolakhas)
    private const TRIAL_ENDED = 3; // a trial that ended unpaid (Coffee)

    private PDO $pdo;
    private ExpiredSwitchJwtParser $jwt;
    private AuthHandler $auth;
    private SubscriptionService $subscriptions;
    private SettingsService $settings;
    private int $owner;
    private int $member;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();
        $this->jwt = new ExpiredSwitchJwtParser(self::SECRET);
        $this->auth = new AuthHandler($this->pdo, $this->jwt, new TokenValidator($this->jwt, $this->pdo));
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->subscriptions = new SubscriptionService(new SubscriptionRepository($this->pdo), $this->settings);

        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES
            (1, 'Example Bakery', 'bakery'), (2, 'Example Studio', 'studio'), (3, 'Example Cafe', 'cafe')");
        // Roles 1 (admin) and 2 (user) come with the schema.

        $this->owner = $this->profile('Amro Saleh', 'amro@example.test');
        $this->member = $this->profile('Awss Haddad', 'awss@example.test');
        foreach ([self::HOME, self::EXPIRED, self::TRIAL_ENDED] as $tenant) {
            $this->membership($this->owner, $tenant, 1);
            $this->membership($this->member, $tenant, 2);
            (new WorkspaceOwners($this->pdo))->add($tenant, $this->owner, $this->owner);
        }
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        TenantContext::reset();
    }

    // ── the switch itself ───────────────────────────────────────────────────

    public function testTheOwnerSwitchesIntoAnExpiredWorkspaceAndGetsASession(): void
    {
        $this->expire(self::EXPIRED, SubscriptionService::MODE_BLOCK_ALL);

        $response = $this->switchAs($this->owner, from: self::HOME, to: self::EXPIRED);

        self::assertSame(200, $response->getStatusCode());
        $minted = $this->jwt->lastAccess;
        self::assertIsArray($minted, 'a session must be minted for the expired workspace');
        self::assertSame(self::EXPIRED, $minted['active_tenant_id'] ?? null);
    }

    /**
     * The bug as it shipped: the switch call itself went through the wall, so
     * someone sitting in a workspace walled with block_all could not leave it,
     * and the 402 sent the page to Billing with no message.
     */
    public function testTheSwitchCallIsNeverWalledEvenOnTheStrictestMode(): void
    {
        $this->expire(self::HOME, SubscriptionService::MODE_BLOCK_ALL);

        $res = $this->throughWall('POST', '/api/v1/auth/switch-tenant', self::HOME);

        self::assertSame(200, $res->getStatusCode());
    }

    /** As strict as before for everything but paying: pages and changes are 402. */
    public function testInsideAnExpiredWorkspaceOnlyBillingIsOpen(): void
    {
        $this->expire(self::EXPIRED, SubscriptionService::MODE_BLOCK_ALL);

        foreach (['/api/v1/billing', '/api/v1/billing/pause', '/api/v1/billing/plans', '/api/v1/subscription', '/api/v1/me/capabilities'] as $path) {
            self::assertSame(200, $this->throughWall('GET', $path, self::EXPIRED)->getStatusCode(), "{$path} must stay open");
        }
        self::assertSame(200, $this->throughWall('POST', '/api/v1/billing/checkout', self::EXPIRED)->getStatusCode());

        foreach (['/api/v1/contacts', '/api/v1/documents', '/api/v1/me/notifications', '/api/v1/navigation'] as $path) {
            self::assertSame(402, $this->throughWall('GET', $path, self::EXPIRED)->getStatusCode(), "{$path} must stay walled");
        }
        self::assertSame(402, $this->throughWall('POST', '/api/v1/contacts', self::EXPIRED)->getStatusCode());
    }

    /** A lapse an operator set to block writes only stays read-only, as before. */
    public function testALapseOnBlockWritesStaysReadOnly(): void
    {
        $this->expire(self::EXPIRED, SubscriptionService::MODE_BLOCK_WRITES);

        self::assertSame(200, $this->switchAs($this->owner, from: self::HOME, to: self::EXPIRED)->getStatusCode());
        self::assertSame(200, $this->throughWall('GET', '/api/v1/contacts', self::EXPIRED)->getStatusCode());
        self::assertSame(402, $this->throughWall('POST', '/api/v1/contacts', self::EXPIRED)->getStatusCode());
        self::assertFalse($this->pause()->stateFor(self::EXPIRED)['reads_refused'] ?? null);
    }

    // ── who is told what ────────────────────────────────────────────────────

    public function testAPausedWorkspaceNamesItsOwnersForEveryMember(): void
    {
        $this->expire(self::EXPIRED, SubscriptionService::MODE_BLOCK_ALL);

        $state = $this->pause()->stateFor(self::EXPIRED);

        self::assertIsArray($state);
        self::assertSame('expired', $state['status']);
        self::assertTrue($state['reads_refused']);
        self::assertSame([['name' => 'Amro Saleh']], $state['owners']);
    }

    public function testAnOwnerWithNoNameIsNamedByAddress(): void
    {
        $this->expire(self::EXPIRED, SubscriptionService::MODE_BLOCK_WRITES);
        $this->pdo->exec("UPDATE profiles SET display_name = '' WHERE id = {$this->owner}");

        self::assertSame([['name' => 'amro@example.test']], $this->pause()->stateFor(self::EXPIRED)['owners'] ?? null);
    }

    public function testAWorkspaceThatIsPaidOrNotEnforcedIsNotPaused(): void
    {
        self::assertNull($this->pause()->stateFor(self::HOME), 'no plan row');

        $this->subscriptions->setSubscription(self::HOME, ['status' => SubscriptionService::STATUS_ACTIVE]);
        self::assertNull($this->pause()->stateFor(self::HOME), 'active');

        $this->expire(self::EXPIRED, SubscriptionService::MODE_WARN);
        self::assertNull($this->pause()->stateFor(self::EXPIRED), 'expired but only warned');
    }

    // ── the trial-ended path, unchanged ─────────────────────────────────────

    public function testATrialThatEndedUnpaidIsStillReadOnlyAndNotPaused(): void
    {
        $this->subscriptions->setSubscription(self::TRIAL_ENDED, [
            'status' => SubscriptionService::STATUS_TRIALING,
            'current_period_end' => '2026-01-01 00:00:00',
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_WRITES,
        ]);

        self::assertSame(200, $this->switchAs($this->member, from: self::HOME, to: self::TRIAL_ENDED)->getStatusCode());
        self::assertSame(200, $this->throughWall('GET', '/api/v1/contacts', self::TRIAL_ENDED)->getStatusCode());
        self::assertSame(402, $this->throughWall('POST', '/api/v1/contacts', self::TRIAL_ENDED)->getStatusCode());
        // Its own banner, as before; not the paused page.
        self::assertNull($this->pause()->stateFor(self::TRIAL_ENDED));
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name) VALUES (7, 'team', 'Team')");
        $this->pdo->exec('UPDATE tenant_plan SET plan_id = 7 WHERE tenant_id = ' . self::TRIAL_ENDED);
        $trial = (new WorkspaceTrial($this->pdo, $this->subscriptions, $this->settings))->stateFor(self::TRIAL_ENDED);
        self::assertIsArray($trial);
        self::assertTrue($trial['read_only']);
        self::assertSame('trialing', $trial['status']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function expire(int $tenantId, string $mode): void
    {
        $this->subscriptions->setSubscription($tenantId, [
            'status' => SubscriptionService::STATUS_EXPIRED,
            'enforcement_mode' => $mode,
        ]);
    }

    private function pause(): WorkspacePause
    {
        return new WorkspacePause($this->subscriptions, new WorkspaceOwners($this->pdo));
    }

    private function switchAs(int $profileId, int $from, int $to): Response
    {
        $_COOKIE['access_token'] = $this->jwt->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $from,
            'email'            => 'person@example.test',
            'role'             => 'admin',
            'token_epoch'      => 0,
        ], 900, 'access');

        return $this->auth->handleSwitchTenant(new Request(
            'POST',
            '/api/auth/switch-tenant',
            ['Content-Type' => 'application/json'],
            (string) json_encode(['tenant_id' => $to])
        ));
    }

    /** The wall as the application wires it, for a request made in $tenantId. */
    private function throughWall(string $method, string $path, int $tenantId): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $wall = new PaymentWall($this->subscriptions, enabled: true, exemptPrefixes: PaymentWall::DEADLOCK_EXEMPT_PREFIXES);

        return $wall->handle(new SdkRequest($method, $path), static fn (SdkRequest $r): Response => new Response(200, 'ok'));
    }

    private function profile(string $name, string $email): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([$name]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, CURRENT_TIMESTAMP)"
        )->execute([$id, $email]);

        return $id;
    }

    private function membership(int $profileId, int $tenantId, int $roleId): void
    {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', CURRENT_TIMESTAMP)"
        )->execute([$profileId, $tenantId, $roleId]);
    }
}
