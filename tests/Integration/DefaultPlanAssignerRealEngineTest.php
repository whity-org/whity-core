<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\RegisterApiHandler;
use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Plan\DefaultPlanAssigner;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\FederatedWorkspaceProvisioner;

/**
 * `billing.default_plan`: what a new workspace starts on, and the ways that must
 * not get in the way of creating one.
 */
final class DefaultPlanAssignerRealEngineTest extends TestCase
{
    private const TENANT = 7;

    private PDO $pdo;
    private PlanRepository $plans;
    private GlobalSettingsRepository $globals;
    private DefaultPlanAssigner $assigner;
    private string $errorLog;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (7, 'seven', 'seven')");
        $this->plans = new PlanRepository($this->pdo);
        $this->globals = new GlobalSettingsRepository($this->pdo);
        $this->assigner = DefaultPlanAssigner::forDatabase($this->pdo);

        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'default-plan');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    public function testANewWorkspaceLandsOnTheDefaultPlan(): void
    {
        $id = $this->plans->createPlan('starter', 'Starter', null, true, 0);
        $this->globals->set(SettingsRegistry::BILLING_DEFAULT_PLAN, 'starter');

        $this->assigner->assign(self::TENANT);

        self::assertSame($id, $this->plans->getTenantPlan(self::TENANT)['plan_id'] ?? null);
    }

    public function testNothingHappensWhileTheSettingIsEmpty(): void
    {
        $this->plans->createPlan('starter', 'Starter', null, true, 0);

        $this->assigner->assign(self::TENANT);

        self::assertNull($this->plans->getTenantPlan(self::TENANT));
    }

    public function testARetiredPlanDoesNotBreakCreationAndIsLogged(): void
    {
        $this->plans->createPlan('starter', 'Starter', null, false, 0);
        $this->globals->set(SettingsRegistry::BILLING_DEFAULT_PLAN, 'starter');

        $this->assigner->assign(self::TENANT);

        self::assertNull($this->plans->getTenantPlan(self::TENANT));
        self::assertStringContainsString('retired', (string) file_get_contents($this->errorLog));
    }

    public function testAMissingPlanDoesNotBreakCreationAndIsLogged(): void
    {
        $this->globals->set(SettingsRegistry::BILLING_DEFAULT_PLAN, 'gone');

        $this->assigner->assign(self::TENANT);

        self::assertNull($this->plans->getTenantPlan(self::TENANT));
        self::assertStringContainsString('not in the catalogue', (string) file_get_contents($this->errorLog));
    }

    /** The system tenant is refused by applyToTenant, which must not escape. */
    public function testAFailureInsideDoesNotEscape(): void
    {
        $this->plans->createPlan('starter', 'Starter', null, true, 0);
        $this->globals->set(SettingsRegistry::BILLING_DEFAULT_PLAN, 'starter');

        $this->assigner->assign(0);

        self::assertStringContainsString('was not given a plan', (string) file_get_contents($this->errorLog));
    }

    public function testRegistrationPutsTheNewWorkspaceOnTheDefaultPlan(): void
    {
        $id = $this->plans->createPlan('starter', 'Starter', null, true, 0);
        $this->globals->set(SettingsRegistry::BILLING_DEFAULT_PLAN, 'starter');

        $tenantId = $this->register(paymentRequired: false);

        self::assertSame($id, $this->plans->getTenantPlan($tenantId)['plan_id'] ?? null);
    }

    public function testRegistrationSurvivesARetiredDefaultPlan(): void
    {
        $this->plans->createPlan('starter', 'Starter', null, false, 0);
        $this->globals->set(SettingsRegistry::BILLING_DEFAULT_PLAN, 'starter');

        $tenantId = $this->register(paymentRequired: false);

        self::assertNull($this->plans->getTenantPlan($tenantId));
    }

    /** A workspace that has to pay first keeps its closed gate and no plan. */
    public function testAClosedPaymentGateIsLeftClosed(): void
    {
        $this->plans->createPlan('starter', 'Starter', null, true, 0);
        $this->globals->set(SettingsRegistry::BILLING_DEFAULT_PLAN, 'starter');

        $tenantId = $this->register(paymentRequired: true);

        $row = $this->pdo->query("SELECT plan_id, status, enforcement_mode FROM tenant_plan WHERE tenant_id = {$tenantId}")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['plan_id']);
        self::assertSame('expired', $row['status']);
        self::assertSame('block_all', $row['enforcement_mode']);
    }

    public function testASingleSignOnWorkspaceLandsOnTheDefaultPlan(): void
    {
        $id = $this->plans->createPlan('starter', 'Starter', null, true, 0);
        $this->globals->set(SettingsRegistry::BILLING_DEFAULT_PLAN, 'starter');
        $this->globals->set(SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE, 'true');

        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_secret,
                                   two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (40, 'Example Baker', 'x', false, NULL, 0, 0, NOW(), NOW())"
        );

        $tenantId = (new FederatedWorkspaceProvisioner($this->pdo, $this->settings()))
            ->provisionFor(40, 'baker@example.test', new FederatedProviderContext(1, 'oidc', 0));

        self::assertNotNull($tenantId);
        self::assertSame($id, $this->plans->getTenantPlan($tenantId)['plan_id'] ?? null);
    }

    public function testTheSettingIsGlobalOnlyAndEmptyByDefault(): void
    {
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::BILLING_DEFAULT_PLAN));
        self::assertSame('', SettingsRegistry::defaultFor(SettingsRegistry::BILLING_DEFAULT_PLAN));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_DEFAULT_PLAN, ''));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_DEFAULT_PLAN, 'starter'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_DEFAULT_PLAN, 'not a key'));
    }

    private function settings(): SettingsService
    {
        return new SettingsService($this->globals, new TenantSettingsRepository($this->pdo));
    }

    private function register(bool $paymentRequired): int
    {
        $settings = $this->settings();
        $settings->setGlobal(SettingsRegistry::SELF_REGISTRATION_ENABLED, 'true');
        $settings->setGlobal(SettingsRegistry::REGISTRATION_APPROVAL_REQUIRED, 'false');
        $settings->setGlobal(SettingsRegistry::REGISTRATION_PAYMENT_REQUIRED, $paymentRequired ? 'true' : 'false');

        $handler = new RegisterApiHandler($this->pdo, $settings);
        $response = $handler->register(new Request('POST', '/api/register', [], (string) json_encode([
            'email' => 'owner@example.test',
            'password' => 'Sufficiently-Long-Passw0rd!',
            'name' => 'Example Owner',
            'tenant_name' => 'Example Bakery',
        ])));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->pdo->query("SELECT id FROM tenants WHERE name = 'Example Bakery'")->fetchColumn();
    }
}
