<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Api\FakeBillingPortal;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AuditLogApiHandler;
use Whity\Api\ExternalBillingApiHandler;
use Whity\Api\InvitationAcceptHandler;
use Whity\Api\StatusApiHandler;
use Whity\Api\TenantsApiHandler;
use Whity\Api\TwoFactorPoliciesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Auth\TwoFactorPolicyResolver;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Billing\External\AccessRecorder;
use Whity\Core\Billing\External\AccessSnapshot;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\EventLedger;
use Whity\Core\Billing\External\WebhookVerifier;
use Whity\Core\Health\HealthSampleRepository;
use Whity\Core\Health\StatusReport;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\CsvCell;
use Whity\Sdk\Http\StreamedResponse;

/**
 * Design 2.9 server item S8 on a real engine, and on real Postgres in CI's
 * Postgres job. All the SQL here is written for both.
 *
 * What it's here to prove, beyond the shape: another workspace's rows and names
 * never show, a permission is required, and a cell can't run in a spreadsheet.
 */
final class WorkspaceServerRealEngineTest extends TestCase
{
    private const TENANT_A = 1001;
    private const TENANT_B = 1002;
    private const ADMIN_A = 2001;
    private const ADMIN_B = 2002;
    private const NOPERM_A = 2003;
    private const OPERATOR = 2004;
    private const MEMBER_A = 2005;
    private const MEMBER_B = 2006;
    private const ROLE_ADMIN_A = 3001;
    private const ROLE_ADMIN_B = 3002;
    private const ROLE_NONE = 3003;
    private const ROLE_OPERATOR = 3004;

    private PDO $pdo;
    private RoleChecker $roleChecker;
    private AuditLogApiHandler $audit;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $_GET = [];

        $this->pdo = SchemaFromMigrations::make(true);

        $this->exec("INSERT INTO tenants (id, name, slug) VALUES (0, 'system', 'system') ON CONFLICT DO NOTHING");
        $this->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::TENANT_A . ", 'Example Bakery', 'bakery')");
        $this->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::TENANT_B . ", 'Example Clinic', 'clinic')");

        foreach ([
            self::ROLE_ADMIN_A => 's8-admin-a',
            self::ROLE_ADMIN_B => 's8-admin-b',
            self::ROLE_NONE => 's8-none',
            self::ROLE_OPERATOR => 's8-operator',
        ] as $id => $name) {
            $this->exec("INSERT INTO roles (id, name, description, created_at) VALUES ({$id}, '{$name}', '', CURRENT_TIMESTAMP)");
        }
        foreach ([self::ROLE_ADMIN_A, self::ROLE_ADMIN_B, self::ROLE_OPERATOR] as $role) {
            foreach ([
                CorePermissions::AUDIT_READ, CorePermissions::TENANTS_READ, CorePermissions::SECURITY_MANAGE,
                CorePermissions::BILLING_PAY, CorePermissions::BILLING_VIEW,
            ] as $permission) {
                $this->grant($role, $permission);
            }
        }

        $this->person(self::ADMIN_A, 'Rana Haddad', self::TENANT_A, self::ROLE_ADMIN_A);
        $this->person(self::ADMIN_B, 'Layla Clinic', self::TENANT_B, self::ROLE_ADMIN_B);
        $this->person(self::NOPERM_A, 'Sami Nopermission', self::TENANT_A, self::ROLE_NONE);
        $this->person(self::OPERATOR, 'Olivia Operator', 0, self::ROLE_OPERATOR);
        $this->person(self::MEMBER_A, 'Omar Khalil', self::TENANT_A, self::ROLE_NONE);
        $this->person(self::MEMBER_B, 'Nour Secret', self::TENANT_B, self::ROLE_NONE);

        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $this->roleChecker = new RoleChecker($database, $registry);

        $this->audit = new AuditLogApiHandler(
            $this->pdo,
            $this->roleChecker,
            null,
            new AuditLogger($this->pdo, new NullLogger())
        );
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $_GET = [];
    }

    // ── audit: names and sentences ──────────────────────────────────────────

    public function testEntriesSayWhatHappenedByName(): void
    {
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'user.updated', 'user', self::MEMBER_A);

        $entry = $this->auditList(self::TENANT_A, self::ADMIN_A)['data'][0];

        self::assertSame('Rana Haddad changed Omar Khalil', $entry['sentence']);
        self::assertSame('{actor} changed {target}', $entry['sentenceTemplate']);
        self::assertSame('person', $entry['actor']['kind']);
        self::assertSame('Rana Haddad', $entry['actor']['name']);
        self::assertSame('Omar Khalil', $entry['target']['name']);
        self::assertSame('people', $entry['area']);
    }

    public function testAnAgentReadsAsTheAgentForItsPerson(): void
    {
        $this->agentToken('jti-a', self::TENANT_A, self::ADMIN_A, 'Billing assistant');
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.updated', null, null, 'agent', 'jti-a', self::ADMIN_A);

        $entry = $this->auditList(self::TENANT_A, self::ADMIN_A)['data'][0];

        self::assertSame('agent', $entry['actor']['kind']);
        self::assertSame('Billing assistant', $entry['actor']['agent']);
        self::assertSame('Rana Haddad', $entry['actor']['forPerson']);
        self::assertStringStartsWith('Billing assistant for Rana Haddad', $entry['sentence']);
    }

    public function testNoIdEverStandsInForAName(): void
    {
        $this->auditRow(self::TENANT_A, 999999, 'user.deleted', 'user', 999998);
        $this->auditRow(self::TENANT_A, null, 'auth.login.failure', null, null);

        $rows = $this->auditList(self::TENANT_A, self::ADMIN_A)['data'];

        self::assertSame('Someone failed to sign in', $rows[0]['sentence']);
        self::assertSame('Someone removed a person', $rows[1]['sentence']);
    }

    public function testAnActionNobodyKnowsStillReads(): void
    {
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'plugin.acme.stock_counted', null, null);

        $entry = $this->auditList(self::TENANT_A, self::ADMIN_A)['data'][0];

        self::assertSame('Rana Haddad: Plugin acme stock counted', $entry['sentence']);
        self::assertSame('plugins', $entry['area']);
    }

    public function testATargetInAnotherWorkspaceIsNeverNamed(): void
    {
        // A plugin can write any target id. Tenant A's row must not name B's member,
        // role or unit, or a guessed id would read out another workspace.
        $this->exec('INSERT INTO organizational_units (id, tenant_id, name, slug) VALUES (4002, ' . self::TENANT_B . ", 'Secret ward', 'secret-ward')");
        $this->exec("UPDATE roles SET tenant_id = " . self::TENANT_B . ' WHERE id = ' . self::ROLE_ADMIN_B);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'user.updated', 'user', self::MEMBER_B);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'ou.updated', 'ou', 4002);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.updated', 'role', self::ROLE_ADMIN_B);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'tenant.updated', 'tenant', self::TENANT_B);

        $body = json_encode($this->auditList(self::TENANT_A, self::ADMIN_A), JSON_UNESCAPED_UNICODE);

        foreach (['Nour Secret', 'Secret ward', 's8-admin-b', 'Example Clinic'] as $foreign) {
            self::assertStringNotContainsString($foreign, (string) $body);
        }
    }

    public function testAnotherWorkspacesAgentCredentialIsNeverNamed(): void
    {
        $this->agentToken('jti-b', self::TENANT_B, self::ADMIN_B, 'Clinic secret agent');
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.updated', null, null, 'agent', 'jti-b', self::ADMIN_A);

        $entry = $this->auditList(self::TENANT_A, self::ADMIN_A)['data'][0];

        self::assertSame('An agent', $entry['actor']['agent']);
    }

    // ── audit: filters ──────────────────────────────────────────────────────

    public function testAgentFilterNarrowsToThatAgent(): void
    {
        $this->agentToken('jti-a', self::TENANT_A, self::ADMIN_A, 'Billing assistant');
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.created', null, null, 'agent', 'jti-a', self::ADMIN_A);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.deleted', null, null);

        $rows = $this->auditList(self::TENANT_A, self::ADMIN_A, 'agent=jti-a')['data'];

        self::assertCount(1, $rows);
        self::assertSame('role.created', $rows[0]['action']);
    }

    public function testAreaFilterGivesEachRowToOneArea(): void
    {
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'tenant.invitation.created', null, null);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'tenant.updated', null, null);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'auth.login.success', null, null);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'something.else', null, null);

        $actions = fn (string $area): array => array_column($this->auditList(self::TENANT_A, self::ADMIN_A, "area={$area}")['data'], 'action');

        self::assertSame(['tenant.invitation.created'], $actions('people'));
        self::assertSame(['tenant.updated'], $actions('workspace'));
        self::assertSame(['auth.login.success'], $actions('security'));
        self::assertSame(['something.else'], $actions('other'));
        self::assertSame([], $actions('no-such-area'), 'An unknown area matches nothing, it does not drop the filter.');
    }

    public function testAnActionEndingInADotAsksForTheFamily(): void
    {
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'notification.sent', null, null);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'notification.failed', null, null);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'notifications.other', null, null);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.created', null, null);
        $this->auditRow(self::TENANT_B, self::ADMIN_B, 'notification.sent', null, null);

        $actions = fn (string $filter): array => array_column($this->auditList(self::TENANT_A, self::ADMIN_A, "action={$filter}")['data'], 'action');

        $family = $actions('notification.');
        sort($family);
        self::assertSame(['notification.failed', 'notification.sent'], $family, 'The family, in this tenant only.');
        self::assertSame(['notification.sent'], $actions('notification.sent'), 'A whole code stays an exact match.');
        self::assertSame([], $actions('notification'), 'No dot, no family.');
        self::assertSame([], $actions('%25.'), 'A wildcard in the value is a character, not a wildcard.');
    }

    public function testFacetsListOnlyWhoAppearsInThisWorkspace(): void
    {
        $this->agentToken('jti-a', self::TENANT_A, self::ADMIN_A, 'Billing assistant');
        $this->agentToken('jti-b', self::TENANT_B, self::ADMIN_B, 'Clinic agent');
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.created', null, null, 'agent', 'jti-a', self::ADMIN_A);
        $this->auditRow(self::TENANT_B, self::ADMIN_B, 'role.created', null, null, 'agent', 'jti-b', self::ADMIN_B);

        self::actIn(self::TENANT_A);
        $facets = self::json($this->audit->facets($this->requestAs('/api/audit-logs/facets', self::ADMIN_A)))['data'];

        self::assertSame(['Rana Haddad'], array_column($facets['people'], 'name'));
        self::assertSame(['Billing assistant'], array_column($facets['agents'], 'name'));
        self::assertContains('security', array_column($facets['areas'], 'id'));
    }

    public function testFacetsAndExportRequireThePermission(): void
    {
        self::actIn(self::TENANT_A);

        self::assertSame(403, $this->audit->facets($this->requestAs('/x', self::NOPERM_A))->getStatusCode());
        self::assertSame(403, $this->audit->export($this->requestAs('/x', self::NOPERM_A))->getStatusCode());
    }

    public function testExportWithNoTenantContextFailsClosed(): void
    {
        TenantContext::reset();

        self::assertSame(403, $this->audit->export($this->requestAs('/x', self::ADMIN_A))->getStatusCode());
    }

    // ── audit: CSV ──────────────────────────────────────────────────────────

    public function testExportIsTenantScoped(): void
    {
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.created', null, null);
        $this->auditRow(self::TENANT_B, self::ADMIN_B, 'role.deleted', null, null);

        $csv = $this->csv(self::TENANT_A);

        self::assertStringContainsString('Rana Haddad', $csv);
        self::assertStringNotContainsString('Layla Clinic', $csv);
    }

    public function testASystemOperatorsExportNamesEachWorkspace(): void
    {
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.created', null, null);
        $this->auditRow(self::TENANT_B, self::ADMIN_B, 'role.deleted', null, null);

        $csv = $this->csv(0);

        self::assertStringContainsString('Example Bakery', $csv);
        self::assertStringContainsString('Example Clinic', $csv);
    }

    public function testAResponseIsACsvAttachment(): void
    {
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.created', null, null);
        self::actIn(self::TENANT_A);

        $response = $this->audit->export($this->requestAs('/api/audit-logs/export', self::ADMIN_A));

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $headers = array_change_key_case($response->getHeaders());
        self::assertStringStartsWith('text/csv', $headers['content-type']);
        self::assertStringContainsString('attachment', $headers['content-disposition']);
    }

    public function testAFormulaInADisplayNameIsDefused(): void
    {
        $this->exec("UPDATE profiles SET display_name = '=HYPERLINK(\"http://evil.test\",\"x\")' WHERE id = " . self::ADMIN_A);
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'auth.login.success', null, null);

        $rows = $this->csvRows(self::TENANT_A);

        // The sentence starts with the name too, so both cells must be safe.
        self::assertStringStartsWith("'=", (string) $rows[1][1]);
        self::assertStringStartsWith("'=", (string) $rows[1][2]);
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousCells(): iterable
    {
        yield 'equals' => ['=1+1'];
        yield 'plus' => ['+1+1'];
        yield 'minus' => ['-1+1'];
        yield 'at' => ['@SUM(A1)'];
        yield 'tab first' => ["\t=1+1"];
        yield 'carriage return first' => ["\r=1+1"];
        yield 'space first' => ['  =1+1'];
        yield 'bidi mark first' => ["\u{200F}=1+1"];
        yield 'zero width first' => ["\u{200B}=1+1"];
        yield 'full-width equals' => ['＝1+1'];
    }

    #[DataProvider('dangerousCells')]
    public function testADangerousCellGetsAnApostrophe(string $cell): void
    {
        self::assertSame("'" . $cell, CsvCell::safe($cell));
    }

    public function testAnOrdinaryCellIsLeftAlone(): void
    {
        foreach (['Rana Haddad', 'مريم', 'a=b', '2026-11-03', ''] as $cell) {
            self::assertSame($cell, CsvCell::safe($cell));
        }
    }

    public function testCodesAndIpAddressesNeedTheTechnicalColumns(): void
    {
        $this->auditRow(self::TENANT_A, self::ADMIN_A, 'role.created', null, null);

        self::assertStringNotContainsString('role.created', $this->csv(self::TENANT_A));
        self::assertStringNotContainsString('203.0.113.9', $this->csv(self::TENANT_A));

        $technical = $this->csv(self::TENANT_A, ['technical' => '1']);
        self::assertStringContainsString('role.created', $technical);
        self::assertStringContainsString('203.0.113.9', $technical);
    }

    public function testExportCrossesBatchesWithoutLosingARow(): void
    {
        $this->exec(
            "INSERT INTO audit_log (tenant_id, actor_user_id, action, metadata, created_at)
             WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 2500)
             SELECT " . self::TENANT_A . ', ' . self::ADMIN_A . ", 'role.created', '{}', CURRENT_TIMESTAMP FROM n"
        );

        self::assertCount(2501, $this->csvRows(self::TENANT_A), 'A header and every row.');
    }

    public function testTooBigAnExportIsRefusedNotCutShort(): void
    {
        $this->exec(
            "INSERT INTO audit_log (tenant_id, actor_user_id, action, metadata, created_at)
             WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i <= " . AuditLogApiHandler::EXPORT_MAX_ROWS . ")
             SELECT " . self::TENANT_A . ', ' . self::ADMIN_A . ", 'role.created', '{}', CURRENT_TIMESTAMP FROM n"
        );
        self::actIn(self::TENANT_A);

        self::assertSame(422, $this->audit->export($this->requestAs('/x', self::ADMIN_A))->getStatusCode());
    }

    public function testExportingIsItselfInTheTrail(): void
    {
        self::actIn(self::TENANT_A);
        $this->audit->export($this->requestAs('/x?area=people', self::ADMIN_A));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = ?');
        $stmt->execute([self::TENANT_A, 'audit.exported']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    // ── tenants: plan and status ────────────────────────────────────────────

    public function testTenantsCarryTheirPlanAndStatus(): void
    {
        $this->plan(5001, 'pro', 'Pro', false);
        $this->tenantPlan(self::TENANT_A, 5001, 'trialing', gmdate('Y-m-d H:i:s', time() + 3 * 86400 - 60));
        $this->tenantPlan(self::TENANT_B, 5001, 'past_due', null);

        $rows = $this->tenants(0, self::OPERATOR);

        self::assertSame(['key' => 'pro', 'name' => 'Pro'], $rows[self::TENANT_A]['plan']);
        self::assertSame(['state' => 'trial', 'daysLeft' => 3], $rows[self::TENANT_A]['status']);
        self::assertSame(['state' => 'overdue', 'daysLeft' => null], $rows[self::TENANT_B]['status']);
    }

    public function testAWorkspaceWithNoSubscriptionIsActive(): void
    {
        $rows = $this->tenants(0, self::OPERATOR);

        self::assertNull($rows[self::TENANT_A]['plan']);
        self::assertSame(['state' => 'active', 'daysLeft' => null], $rows[self::TENANT_A]['status']);
    }

    public function testAnEndedSubscriptionReadsSuspended(): void
    {
        $this->plan(5001, 'pro', 'Pro', false);
        $this->tenantPlan(self::TENANT_A, 5001, 'expired', null);

        self::assertSame('suspended', $this->tenants(0, self::OPERATOR)[self::TENANT_A]['status']['state']);
    }

    public function testAWorkspaceSeesOnlyItsOwnPlan(): void
    {
        $this->plan(5001, 'pro', 'Pro', false);
        $this->plan(5002, 'secret', 'Secret deal', false);
        $this->tenantPlan(self::TENANT_A, 5001, 'active', null);
        $this->tenantPlan(self::TENANT_B, 5002, 'active', null);

        $rows = $this->tenants(self::TENANT_A, self::ADMIN_A);

        self::assertSame([self::TENANT_A], array_keys($rows));
        self::assertSame('pro', $rows[self::TENANT_A]['plan']['key']);
    }

    // ── remind for two-step ─────────────────────────────────────────────────

    public function testRemindSendsOneNotificationAndRecordsIt(): void
    {
        $this->policy(self::TENANT_A);
        $sent = [];
        $handler = $this->twoStep($sent);
        self::actIn(self::TENANT_A);

        $response = $handler->remind($this->post('/api/2fa-policies/remind', ['profile_id' => self::MEMBER_A], self::ADMIN_A));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $sent);
        self::assertSame(self::MEMBER_A, $sent[0]['recipient_profile_id']);
        self::assertSame(self::TENANT_A, $sent[0]['tenant_id']);
        self::assertSame('member' . self::MEMBER_A . '@example.test', $sent[0]['options']['to']);

        $status = self::json($handler->status($this->requestAs('/x', self::ADMIN_A)))['data'];
        $member = array_values(array_filter($status, static fn (array $r): bool => $r['profile_id'] === self::MEMBER_A))[0];
        self::assertNotNull($member['last_reminded_at']);
        self::assertSame('Omar Khalil', $member['name']);
        $by = $this->pdo->prepare('SELECT reminded_by FROM two_factor_reminders WHERE profile_id = ?');
        $by->execute([self::MEMBER_A]);
        self::assertSame(self::ADMIN_A, (int) $by->fetchColumn(), 'Who reminded is the signed-in caller.');
    }

    public function testASecondReminderTheSameDayIsRefusedAndSendsNothing(): void
    {
        $this->policy(self::TENANT_A);
        $sent = [];
        $handler = $this->twoStep($sent);
        self::actIn(self::TENANT_A);
        $handler->remind($this->post('/x', ['profile_id' => self::MEMBER_A], self::ADMIN_A));

        $response = $handler->remind($this->post('/x', ['profile_id' => self::MEMBER_A], self::ADMIN_A));

        self::assertSame(429, $response->getStatusCode());
        self::assertCount(1, $sent);
    }

    public function testAReminderAfterADayGoesOut(): void
    {
        $this->policy(self::TENANT_A);
        $sent = [];
        $handler = $this->twoStep($sent);
        self::actIn(self::TENANT_A);
        $handler->remind($this->post('/x', ['profile_id' => self::MEMBER_A], self::ADMIN_A));
        $this->exec("UPDATE two_factor_reminders SET reminded_at = '2020-01-01 00:00:00'");

        self::assertSame(200, $handler->remind($this->post('/x', ['profile_id' => self::MEMBER_A], self::ADMIN_A))->getStatusCode());
        self::assertCount(2, $sent);
    }

    public function testRemindCannotReachAnotherWorkspace(): void
    {
        $this->policy(self::TENANT_A);
        $this->policy(self::TENANT_B);
        $sent = [];
        $handler = $this->twoStep($sent);
        self::actIn(self::TENANT_A);

        $response = $handler->remind($this->post('/x', ['profile_id' => self::MEMBER_B], self::ADMIN_A));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $sent);
    }

    public function testRemindRefusesSomebodyAlreadySetUp(): void
    {
        $this->policy(self::TENANT_A);
        $this->exec('UPDATE profiles SET two_factor_enabled = true WHERE id = ' . self::MEMBER_A);
        $sent = [];
        $handler = $this->twoStep($sent);
        self::actIn(self::TENANT_A);

        self::assertSame(409, $handler->remind($this->post('/x', ['profile_id' => self::MEMBER_A], self::ADMIN_A))->getStatusCode());
        self::assertSame([], $sent);
    }

    public function testARuleSaysWhoItCoversByName(): void
    {
        $this->exec('INSERT INTO organizational_units (id, tenant_id, name, slug) VALUES (4001, ' . self::TENANT_A . ", 'Abdoun', 'abdoun')");
        $this->pdo->prepare(
            "INSERT INTO two_factor_policies (tenant_id, scope_type, scope_id, grace_period_days, created_at, updated_at)
             VALUES (?, 'ou', 4001, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([self::TENANT_A]);
        $sent = [];
        self::actIn(self::TENANT_A);

        $rows = self::json($this->twoStep($sent)->list($this->requestAs('/x', self::ADMIN_A)))['data'];

        self::assertSame('Abdoun', $rows[0]['scope_name']);
    }

    // ── plan change ─────────────────────────────────────────────────────────

    public function testMovingUpIsChargedNowWithTheCallersKey(): void
    {
        $portal = $this->billing('starter', 'sub_1');

        $response = $this->billingHandler($portal)->changePlan(
            $this->post('/x', ['plan_key' => 'pro', 'idempotency_key' => 'dialog-0001'], self::ADMIN_A)
        );

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame('now', self::json($response)['data']['takes_effect']);
        self::assertCount(1, $portal->planChanges);
        $change = $portal->planChanges[0];
        self::assertSame('sub_1', $change['subscription']);
        self::assertSame('price_pro', $change['price']);
        self::assertSame(BillingPortal::PRORATION_IMMEDIATE, $change['proration']);
    }

    public function testMovingDownWaitsForRenewal(): void
    {
        $portal = $this->billing('pro', 'sub_1');

        $response = $this->billingHandler($portal)->changePlan(
            $this->post('/x', ['plan_key' => 'starter', 'idempotency_key' => 'dialog-0001'], self::ADMIN_A)
        );

        self::assertSame('renewal', self::json($response)['data']['takes_effect']);
        self::assertSame(BillingPortal::PRORATION_AT_PERIOD_END, $portal->planChanges[0]['proration']);
    }

    public function testASecondConfirmedChangeIsADifferentKey(): void
    {
        $portal = $this->billing('starter', 'sub_1');
        $handler = $this->billingHandler($portal);

        $handler->changePlan($this->post('/x', ['plan_key' => 'pro', 'idempotency_key' => 'dialog-0001'], self::ADMIN_A));
        $this->tenantPlan(self::TENANT_A, 5001, 'active', null, 'sub_1');
        $handler->changePlan($this->post('/x', ['plan_key' => 'pro', 'idempotency_key' => 'dialog-0002'], self::ADMIN_A));

        self::assertNotSame($portal->planChanges[0]['idempotency_key'], $portal->planChanges[1]['idempotency_key']);
    }

    public function testChangePlanRefusals(): void
    {
        $portal = $this->billing('starter', 'sub_1');
        $handler = $this->billingHandler($portal);
        $key = ['idempotency_key' => 'dialog-0001'];

        self::assertSame(409, $handler->changePlan($this->post('/x', ['plan_key' => 'starter'] + $key, self::ADMIN_A))->getStatusCode(), 'Same plan');
        self::assertSame(422, $handler->changePlan($this->post('/x', ['plan_key' => 'devices'] + $key, self::ADMIN_A))->getStatusCode(), 'Add-on');
        self::assertSame(422, $handler->changePlan($this->post('/x', ['plan_key' => 'nope'] + $key, self::ADMIN_A))->getStatusCode(), 'Unknown plan');
        self::assertSame(422, $handler->changePlan($this->post('/x', ['plan_key' => 'pro'], self::ADMIN_A))->getStatusCode(), 'No key');
        self::assertSame(422, $handler->changePlan($this->post('/x', ['plan_key' => 'pro', 'idempotency_key' => 'x y'], self::ADMIN_A))->getStatusCode(), 'Bad key');
        self::assertSame(403, $handler->changePlan($this->post('/x', ['plan_key' => 'pro'] + $key, self::NOPERM_A))->getStatusCode(), 'No permission');
        self::assertSame([], $portal->planChanges);
    }

    public function testChangePlanUsesOnlyThisWorkspacesSubscription(): void
    {
        $portal = $this->billing('starter', 'sub_a');
        $this->tenantPlan(self::TENANT_B, 5002, 'active', null, 'sub_b');

        // A caller can't name a subscription: anything sent is ignored.
        $this->billingHandler($portal)->changePlan($this->post(
            '/x',
            ['plan_key' => 'pro', 'idempotency_key' => 'dialog-0001', 'subscription_ref' => 'sub_b'],
            self::ADMIN_A
        ));

        self::assertSame('sub_a', $portal->planChanges[0]['subscription']);
    }

    public function testAWorkspaceWithoutASubscriptionHasNothingToChange(): void
    {
        $portal = $this->billing('starter', null);

        $response = $this->billingHandler($portal)->changePlan(
            $this->post('/x', ['plan_key' => 'pro', 'idempotency_key' => 'dialog-0001'], self::ADMIN_A)
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([], $portal->planChanges);
    }

    public function testPlansMarkTheCurrentOne(): void
    {
        $portal = $this->billing('starter', 'sub_1');

        $rows = self::json($this->billingHandler($portal)->plans($this->requestAs('/x', self::ADMIN_A)))['data'];
        $current = array_column(array_filter($rows, static fn (array $r): bool => $r['is_current']), 'plan_key');

        self::assertSame(['starter'], $current);
    }

    // ── invitation ──────────────────────────────────────────────────────────

    public function testAnInvitationNamesWhoInvitedYouAndAsWhat(): void
    {
        $service = new InvitationService($this->pdo, new ProfileProvisioner($this->pdo));
        $raw = 'raw-token-for-s8-test';
        $this->pdo->prepare(
            "INSERT INTO invitations (tenant_id, email, role_id, token_hash, status, invited_by, expires_at)
             VALUES (?, 'new@example.test', ?, ?, 'pending', ?, ?)"
        )->execute([self::TENANT_A, self::ROLE_NONE, hash('sha256', $raw), self::ADMIN_A, gmdate('Y-m-d H:i:s', time() + 3600)]);

        $handler = new InvitationAcceptHandler($service, new DatabaseSharedStore($this->pdo), new AuditLogger($this->pdo, new NullLogger()));
        $data = self::json($handler->preview(new Request('GET', '/api/invitations/accept?token=' . $raw)))['data'];

        self::assertSame('Rana Haddad', $data['inviter_name']);
        self::assertSame('s8-none', $data['role_name']);
        self::assertSame('Example Bakery', $data['tenant_name']);
    }

    // ── maintenance signal ──────────────────────────────────────────────────

    public function testStatusSaysWhenMaintenanceIsAnnounced(): void
    {
        $settings = $this->settings();
        $handler = new StatusApiHandler(new StatusReport(new HealthSampleRepository($this->pdo)), $settings);

        self::assertSame(['active' => false, 'until' => null], self::json($handler->get(new Request('GET', '/x')))['maintenance']);

        $settings->setGlobal(SettingsRegistry::MAINTENANCE_ACTIVE, 'true');
        $settings->setGlobal(SettingsRegistry::MAINTENANCE_UNTIL, '2026-11-03T02:30:00+03:00');

        self::assertSame(
            ['active' => true, 'until' => '2026-11-03T02:30:00+03:00'],
            self::json($handler->get(new Request('GET', '/x')))['maintenance']
        );
    }

    public function testMaintenanceUntilMustBeADate(): void
    {
        $this->expectException(\Throwable::class);
        $this->settings()->setGlobal(SettingsRegistry::MAINTENANCE_UNTIL, 'soon');
    }

    // ── migrations ──────────────────────────────────────────────────────────

    public function testMigrationsDownReverseUp(): void
    {
        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();

        \Database\Migrations\CreateTwoFactorReminders::down($database);
        self::assertFalse($this->tableExists('two_factor_reminders'));
        \Database\Migrations\CreateTwoFactorReminders::up($database);
        self::assertTrue($this->tableExists('two_factor_reminders'));

        \Database\Migrations\AddAuditLogAgentIndex::down($database);
        self::assertFalse($this->indexExists('idx_audit_log_tenant_agent'));
        \Database\Migrations\AddAuditLogAgentIndex::up($database);
        self::assertTrue($this->indexExists('idx_audit_log_tenant_agent'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private static function actIn(int $tenantId): void
    {
        // The context locks once set, as it does for a request.
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
    }

    private function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    private function indexExists(string $index): bool
    {
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? 'SELECT 1 FROM pg_indexes WHERE indexname = ?'
            : "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$index]);

        return $stmt->fetchColumn() !== false;
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT INTO permissions (name, description, created_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT (name) DO NOTHING')
            ->execute([$permission, '']);
        $select = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $select->execute([$permission]);
        $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING')
            ->execute([$roleId, (int) $select->fetchColumn()]);
    }

    private function person(int $id, string $name, int $tenantId, int $roleId): void
    {
        $this->pdo->prepare(
            'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        )->execute([$id, $name, 'x']);
        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, is_primary, created_at) VALUES (?, ?, true, CURRENT_TIMESTAMP)'
        )->execute([$id, 'member' . $id . '@example.test']);
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, ?, ?, 'active', CURRENT_TIMESTAMP)"
        )->execute([$id, $tenantId, $roleId]);
    }

    private function agentToken(string $jti, int $tenantId, int $profileId, string $name): void
    {
        $this->pdo->prepare(
            'INSERT INTO mcp_tokens (jti, tenant_id, profile_id, name, expires_at, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([$jti, $tenantId, $profileId, $name, '2099-01-01 00:00:00']);
    }

    private function auditRow(
        int $tenantId,
        ?int $actorId,
        string $action,
        ?string $targetType,
        ?int $targetId,
        ?string $kind = null,
        ?string $principal = null,
        ?int $onBehalfOf = null,
    ): void {
        $this->pdo->prepare(
            "INSERT INTO audit_log (tenant_id, actor_user_id, actor_kind, agent_principal_id, on_behalf_of_profile_id,
                 action, target_type, target_id, metadata, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, '{}', '203.0.113.9', CURRENT_TIMESTAMP)"
        )->execute([$tenantId, $actorId, $kind, $principal, $onBehalfOf, $action, $targetType, $targetId]);
    }

    /** @return array<string, mixed> */
    private function auditList(int $tenantId, int $userId, string $query = ''): array
    {
        self::actIn($tenantId);

        return self::json($this->audit->list($this->requestAs('/api/audit-logs?' . $query, $userId)));
    }

    /** @param array<string, string> $query */
    private function csv(int $tenantId, array $query = []): string
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->audit->writeExport($stream, $tenantId, $query);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** @return list<list<string|null>> */
    private function csvRows(int $tenantId): array
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, ltrim($this->csv($tenantId), "\xEF\xBB\xBF"));
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function requestAs(string $path, int $userId): Request
    {
        $request = new Request('GET', $path);
        $request->user = (object) ['profile_id' => $userId];

        return $request;
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, int $userId): Request
    {
        $request = new Request('POST', $path, [], (string) json_encode($body));
        $request->user = (object) ['profile_id' => $userId];

        return $request;
    }

    /** @return array<string, mixed> */
    private static function json(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());

        return $decoded;
    }

    /** @return array<int, array<string, mixed>> */
    private function tenants(int $tenantId, int $userId): array
    {
        self::actIn($tenantId);
        $handler = new TenantsApiHandler($this->pdo, new HookManager());
        $rows = self::json($handler->list($this->requestAs('/api/tenants', $userId)))['data'];

        return array_column($rows, null, 'id');
    }

    private function plan(int $id, string $key, string $name, bool $addon): void
    {
        $this->pdo->prepare(
            'INSERT INTO plans (id, plan_key, name, is_active, is_addon, created_at, updated_at)
             VALUES (?, ?, ?, true, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        )->execute([$id, $key, $name, $addon ? 1 : 0]);
    }

    private function price(int $planId, int $amount, string $ref): void
    {
        $this->pdo->prepare(
            "INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, external_ref, created_at, updated_at)
             VALUES (?, 'JOD', ?, 'month', false, true, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([$planId, $amount, $ref]);
    }

    private function tenantPlan(int $tenantId, int $planId, ?string $status, ?string $periodEnd, ?string $externalRef = null): void
    {
        $this->pdo->prepare('DELETE FROM tenant_plan WHERE tenant_id = ?')->execute([$tenantId]);
        $this->pdo->prepare(
            'INSERT INTO tenant_plan (tenant_id, plan_id, status, current_period_end, external_ref)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$tenantId, $planId, $status, $periodEnd, $externalRef]);
    }

    private function policy(int $tenantId): void
    {
        $this->pdo->prepare(
            "INSERT INTO two_factor_policies (tenant_id, scope_type, scope_id, grace_period_days, created_at, updated_at)
             VALUES (?, 'tenant', NULL, 7, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([$tenantId]);
    }

    /** @param list<array<string, mixed>> $sent */
    private function twoStep(array &$sent): TwoFactorPoliciesApiHandler
    {
        $hooks = new HookManager();
        $hooks->listen('notification.dispatch', function (array $data) use (&$sent): array {
            $sent[] = $data;

            return $data;
        });
        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();

        return new TwoFactorPoliciesApiHandler(
            $this->pdo,
            new TwoFactorPolicyResolver($database),
            new AuditLogger($this->pdo, new NullLogger()),
            $hooks
        );
    }

    private function settings(): SettingsService
    {
        return new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
    }

    private function billing(string $current, ?string $subscriptionRef): FakeBillingPortal
    {
        $this->plan(5001, 'starter', 'Starter', false);
        $this->plan(5002, 'pro', 'Pro', false);
        $this->plan(5003, 'devices', 'Devices', true);
        $this->price(5001, 10000, 'price_starter');
        $this->price(5002, 49000, 'price_pro');
        $this->price(5003, 1000, 'price_devices');
        $this->tenantPlan(self::TENANT_A, $current === 'starter' ? 5001 : 5002, 'active', null, $subscriptionRef);

        $portal = new FakeBillingPortal();
        $portal->access = new AccessSnapshot('tenant:' . self::TENANT_A, true, $current, 'active');

        return $portal;
    }

    private function billingHandler(FakeBillingPortal $portal): ExternalBillingApiHandler
    {
        self::actIn(self::TENANT_A);
        $settings = $this->settings();

        return new ExternalBillingApiHandler(
            $portal,
            new AccessRecorder(
                new SubscriptionService(new SubscriptionRepository($this->pdo), $settings),
                new PlanRepository($this->pdo),
                new NullLogger()
            ),
            new EventLedger(new DatabaseSharedStore($this->pdo)),
            new WebhookVerifier('secret', 300),
            $settings,
            $this->roleChecker,
            $this->pdo,
            'https://app.example.test',
            new NullLogger(),
            new AuditLogger($this->pdo, new NullLogger()),
        );
    }
}
