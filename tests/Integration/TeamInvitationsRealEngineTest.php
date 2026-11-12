<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TeamInvitationsApiHandler;
use Whity\Api\WorkspaceSignupApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Identity\InvitationMailer;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Signup\CustomerSignupService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Http\Response;

/**
 * Customers invite their own team (#1872, WHIT-996), against the
 * migration-built schema: off by default, only for a person who is only a
 * customer, only into their own role and as a customer, never revealing who
 * is here, never stepping on an admin's invitation or a turned-away person,
 * rate-limited, and open to a plugin's veto or annotation.
 */
final class TeamInvitationsRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const ADMIN = 10;
    private const STAFF = 11;
    private const CLINIC_OWNER = 12;
    private const OTHER_CUSTOMER = 13;

    private PDO $pdo;
    private TeamInvitationsApiHandler $team;
    private WorkspaceSignupApiHandler $admin;
    private InvitationService $invitations;
    private RoleChecker $roleChecker;
    private HookManager $hooks;
    private int $customerRole;

    /** @var list<array{to: string, body: string}> */
    private array $sent = [];

    /** @var list<array<string, mixed>> */
    private array $joined = [];

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();

        $mailer = new class (function (string $to, string $body): void {
            $this->sent[] = ['to' => $to, 'body' => $body];
        }) implements Mailer {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                ($this->sink)($toEmail, $textBody);
            }
        };

        $globals = new GlobalSettingsRepository($this->pdo);
        $globals->set(SettingsRegistry::MAIL_EVENT_INVITATION, 'true');
        $settings = new SettingsService($globals, new TenantSettingsRepository($this->pdo));

        $this->hooks = new HookManager();
        $this->hooks->listen(Events::MEMBERSHIP_CUSTOMER_JOINED, function (array $data): array {
            $this->joined[] = $data;

            return $data;
        });

        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();
        $this->roleChecker = new RoleChecker($db, $registry);

        $audit = new AuditLogger($this->pdo, new NullLogger());
        $signupSettings = new WorkspaceSignupSettings($this->pdo);
        $safe = CustomerSafeRole::make($this->pdo);
        $this->invitations = new InvitationService($this->pdo, new ProfileProvisioner($this->pdo), $this->hooks);

        $this->team = new TeamInvitationsApiHandler(
            $this->pdo,
            $this->invitations,
            $this->roleChecker,
            $signupSettings,
            $safe,
            $audit,
            new DatabaseSharedStore($this->pdo),
            $settings,
            $this->hooks,
            new InvitationMailer($mailer, 'https://app.test/invite', new EmailLayout(), $settings),
        );
        $this->admin = new WorkspaceSignupApiHandler(
            $this->pdo,
            $this->roleChecker,
            $signupSettings,
            $safe,
            new CustomerSignupService($this->pdo, $signupSettings, $safe, $this->hooks),
            $audit,
        );
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    public function testItIsOffByDefaultAndTheAdminTurnsItOn(): void
    {
        $res = $this->invite(self::CLINIC_OWNER, 'dentist@example.test');
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('team_invites_off', $this->decode($res)['details']['code']);
        self::assertFalse($this->decode($this->list(self::CLINIC_OWNER))['data']['enabled']);

        $this->turnOn();
        $settings = $this->decode($this->admin->getSettings($this->authed(self::A, self::ADMIN, 'GET', '/api/signup/settings')));
        self::assertTrue($settings['data']['customer_invites']);
        self::assertTrue($this->decode($this->list(self::CLINIC_OWNER))['data']['enabled']);

        // Saving other settings later keeps it on.
        $this->admin->updateSettings($this->authed(self::A, self::ADMIN, 'PUT', '/api/signup/settings', ['allowed_domains' => []]));
        self::assertTrue((new WorkspaceSignupSettings($this->pdo))->get(self::A)['customer_invites']);

        $bad = $this->admin->updateSettings($this->authed(self::A, self::ADMIN, 'PUT', '/api/signup/settings', ['customer_invites' => 'yes']));
        self::assertSame(422, $bad->getStatusCode());
    }

    public function testStaffAndAgentsUsePeopleNotThis(): void
    {
        $this->turnOn();
        $res = $this->invite(self::STAFF, 'someone@example.test');
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('not_a_customer', $this->decode($res)['details']['code']);
        self::assertSame(403, $this->invite(self::ADMIN, 'someone@example.test')->getStatusCode());
    }

    public function testACustomerInvitesIntoTheirOwnRoleAsACustomer(): void
    {
        $this->turnOn();
        $this->hooks->listen(Events::MEMBERSHIP_CUSTOMER_INVITING, static function (array $data): array {
            // DLaaS binds the invitee to the inviter's clinic.
            $data['annotations'] = ['dlaas' => ['clinic_id' => 42]];
            $data['role_id'] = 1;               // ignored
            $data['email'] = 'evil@example.test'; // ignored

            return $data;
        });

        $res = $this->invite(self::CLINIC_OWNER, 'Dentist@Example.test', ['role_id' => 1, 'kind' => 'member']);
        self::assertSame(202, $res->getStatusCode(), $res->getBody());
        self::assertCount(1, $this->sent);
        self::assertSame('dentist@example.test', $this->sent[0]['to']);

        $row = $this->q("SELECT * FROM invitations WHERE email = 'dentist@example.test'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('customer', $row['kind']);
        self::assertSame($this->customerRole, (int) $row['role_id']);
        self::assertNull($row['ou_id']);
        self::assertSame(self::CLINIC_OWNER, (int) $row['invited_by']);

        $accepted = $this->invitations->accept($this->token(0), password_hash('a-long-password', PASSWORD_BCRYPT));
        self::assertSame(InvitationService::ACCEPT_JOINED, $accepted['result']);
        $membership = $this->q(
            'SELECT * FROM memberships WHERE tenant_id = ' . self::A . ' AND profile_id = ' . (int) $accepted['profile_id']
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($membership);
        self::assertSame('customer', $membership['kind']);
        self::assertSame($this->customerRole, (int) $membership['role_id']);
        self::assertTrue($this->roleChecker->isCustomerOnly((int) $accepted['profile_id'], self::A));

        self::assertCount(1, $this->joined);
        self::assertSame('invitation', $this->joined[0]['via']);
        self::assertSame(self::CLINIC_OWNER, $this->joined[0]['invited_by']);
        self::assertSame(['dlaas' => ['clinic_id' => 42]], $this->joined[0]['annotations']);

        // The inviter sees it in their own list, and staff see who invited whom.
        $mine = $this->decode($this->list(self::CLINIC_OWNER))['data']['items'];
        self::assertSame('accepted', $mine[0]['status']);
        $all = $this->invitations->listForTenant(self::A);
        self::assertSame('customer', $all[0]['kind']);
        self::assertSame(self::CLINIC_OWNER, $all[0]['invited_by']);
    }

    public function testThePluginCanRefuse(): void
    {
        $this->turnOn();
        $this->hooks->listen(Events::MEMBERSHIP_CUSTOMER_INVITING, static function (array $data): array {
            throw new HookVetoException(Events::MEMBERSHIP_CUSTOMER_INVITING, 'Only clinic owners can invite.');
        });

        $res = $this->invite(self::CLINIC_OWNER, 'dentist@example.test');
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('invite_refused', $this->decode($res)['details']['code']);
        self::assertSame([], $this->sent);
        self::assertSame(0, (int) $this->q('SELECT COUNT(*) FROM invitations')->fetchColumn());
    }

    public function testItRevealsNobodyAndStepsOnNobody(): void
    {
        $this->turnOn();

        // Already staff here: same answer, nothing sent.
        $here = $this->invite(self::CLINIC_OWNER, 'staff@example.test');
        self::assertSame(202, $here->getStatusCode());

        // An admin's invitation is never superseded by a customer's.
        $adminInvite = $this->invitations->invite(self::A, 'new-hire@example.test', $this->customerRole, null, self::ADMIN);
        $over = $this->invite(self::CLINIC_OWNER, 'new-hire@example.test');
        self::assertSame(202, $over->getStatusCode());
        self::assertSame($this->decode($here)['data'], $this->decode($over)['data']);
        self::assertSame('pending', $this->q('SELECT status FROM invitations WHERE id = ' . $adminInvite['id'])->fetchColumn());

        // Nor another customer's.
        $this->invite(self::OTHER_CUSTOMER, 'their-dentist@example.test');
        $this->invite(self::CLINIC_OWNER, 'their-dentist@example.test');
        self::assertSame(
            [self::OTHER_CUSTOMER],
            array_map('intval', $this->q("SELECT invited_by FROM invitations WHERE email = 'their-dentist@example.test'")->fetchAll(PDO::FETCH_COLUMN))
        );

        // Somebody the sign-up turned away stays out.
        $this->pdo->exec(
            "INSERT INTO customer_signups (tenant_id, email, display_name, status, expires_at, created_at)
             VALUES (" . self::A . ", 'turned-away@example.test', 'X', 'rejected', NOW(), NOW())"
        );
        self::assertSame(202, $this->invite(self::CLINIC_OWNER, 'turned-away@example.test')->getStatusCode());

        // Only the other customer's invitation went out.
        self::assertSame(['their-dentist@example.test'], array_column($this->sent, 'to'));
        self::assertSame(0, (int) $this->q(
            "SELECT COUNT(*) FROM invitations WHERE email IN ('staff@example.test', 'turned-away@example.test')"
        )->fetchColumn());
    }

    public function testACustomerInvitationNeverCompletesAMembershipAnAdminStaged(): void
    {
        $this->turnOn();
        $this->invite(self::CLINIC_OWNER, 'later@example.test');
        $profile = (new ProfileProvisioner($this->pdo))->findOrCreate('later@example.test', password_hash('x-long-password', PASSWORD_BCRYPT));
        $this->pdo->exec(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES ('
            . $profile . ', ' . self::A . ', ' . $this->adminRole() . ", 'invited', NOW())"
        );

        $accepted = $this->invitations->accept($this->token(0), null);
        self::assertSame(InvitationService::ACCEPT_INVALID, $accepted['result']);
        self::assertSame('invited', $this->q('SELECT status FROM memberships WHERE profile_id = ' . $profile)->fetchColumn());
    }

    public function testARoleThatCannotBePassedOnIsRefused(): void
    {
        $this->turnOn();
        $this->grant($this->customerRole, CorePermissions::ROLES_WRITE);

        $res = $this->invite(self::CLINIC_OWNER, 'dentist@example.test');
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('role_not_customer_safe', $this->decode($res)['details']['code']);
    }

    public function testTheDomainListAndTheLimitsApply(): void
    {
        (new WorkspaceSignupSettings($this->pdo))->save(self::A, 'off', null, ['example-clinic.com'], self::ADMIN, true);

        $res = $this->invite(self::CLINIC_OWNER, 'someone@gmail.example');
        self::assertSame(422, $res->getStatusCode());
        self::assertSame('domain_not_allowed', $this->decode($res)['details']['code']);

        for ($i = 0; $i < 10; $i++) {
            self::assertSame(202, $this->invite(self::CLINIC_OWNER, "p{$i}@example-clinic.com")->getStatusCode());
        }
        $over = $this->invite(self::CLINIC_OWNER, 'p11@example-clinic.com');
        self::assertSame(429, $over->getStatusCode());
    }

    public function testACustomerWithdrawsOnlyTheirOwn(): void
    {
        $this->turnOn();
        $this->invite(self::OTHER_CUSTOMER, 'theirs@example.test');
        $this->invite(self::CLINIC_OWNER, 'mine@example.test');
        $theirs = (int) $this->q("SELECT id FROM invitations WHERE email = 'theirs@example.test'")->fetchColumn();
        $mine = (int) $this->q("SELECT id FROM invitations WHERE email = 'mine@example.test'")->fetchColumn();

        self::assertSame(404, $this->revoke(self::CLINIC_OWNER, $theirs)->getStatusCode());
        self::assertSame(200, $this->revoke(self::CLINIC_OWNER, $mine)->getStatusCode());
        self::assertSame('revoked', $this->q('SELECT status FROM invitations WHERE id = ' . $mine)->fetchColumn());
        self::assertSame('pending', $this->q('SELECT status FROM invitations WHERE id = ' . $theirs)->fetchColumn());
        self::assertSame(['theirs@example.test'], array_column(
            $this->decode($this->list(self::OTHER_CUSTOMER))['data']['items'],
            'email'
        ));
    }

    // ==================== helpers ====================

    private function turnOn(): void
    {
        $res = $this->admin->updateSettings($this->authed(self::A, self::ADMIN, 'PUT', '/api/signup/settings', ['customer_invites' => true]));
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
    }

    /** @param array<string, mixed> $extra */
    private function invite(int $actor, string $email, array $extra = []): Response
    {
        return $this->team->create($this->authed(self::A, $actor, 'POST', '/api/team-invitations', ['email' => $email] + $extra));
    }

    private function list(int $actor): Response
    {
        return $this->team->list($this->authed(self::A, $actor, 'GET', '/api/team-invitations'));
    }

    private function revoke(int $actor, int $id): Response
    {
        return $this->team->revoke($this->authed(self::A, $actor, 'DELETE', '/api/team-invitations/' . $id), ['id' => $id]);
    }

    /** @param array<string, mixed>|null $body */
    private function authed(int $tenantId, int $actorId, string $method, string $path, ?array $body = null): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        RoleChecker::clearCache();

        $request = new Request($method, $path, ['Content-Type' => 'application/json'], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    private function token(int $index): string
    {
        self::assertArrayHasKey($index, $this->sent);
        self::assertSame(1, preg_match('/token=([0-9a-f]{64})/', $this->sent[$index]['body'], $m));

        return (string) ($m[1] ?? '');
    }

    private function q(string $sql): \PDOStatement
    {
        return self::on($this->pdo, $sql);
    }

    private static function on(PDO $pdo, string $sql): \PDOStatement
    {
        $stmt = $pdo->query($sql);
        self::assertNotFalse($stmt, $sql);

        return $stmt;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());

        return $decoded;
    }

    private function grant(int $roleId, string $permission): void
    {
        $id = (int) $this->q("SELECT id FROM permissions WHERE name = '" . $permission . "'")->fetchColumn();
        $this->pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES ({$roleId}, {$id}, NOW())");
        RoleChecker::clearCache();
    }

    private function adminRole(): int
    {
        return (int) $this->q("SELECT id FROM roles WHERE name = 'admin' AND tenant_id IS NULL")->fetchColumn();
    }

    /**
     * Workspace A with an admin, a staff member and two customers (clinic
     * owners) in the customer-safe role `clinic owners`; workspace B empty.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::A . ", 'Example Lab', 'example-lab')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::B . ", 'Other', 'other')");

        $admin = (int) self::on($pdo, "SELECT id FROM roles WHERE name = 'admin' AND tenant_id IS NULL")->fetchColumn();
        $user = (int) self::on($pdo, "SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL")->fetchColumn();

        $pdo->exec("INSERT INTO roles (name, description, tenant_id, created_at) VALUES ('clinic owners', '', " . self::A . ', CURRENT_TIMESTAMP)');
        $this->customerRole = (int) self::on($pdo, "SELECT id FROM roles WHERE name = 'clinic owners'")->fetchColumn();
        $submit = (int) self::on($pdo, "SELECT id FROM permissions WHERE name = 'forms:submit'")->fetchColumn();
        $pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES ({$this->customerRole}, {$submit}, CURRENT_TIMESTAMP)");

        $people = [
            [self::ADMIN, 'Amro Saleh', 'admin@example.test', $admin, 'member'],
            [self::STAFF, 'Awss Haddad', 'staff@example.test', $user, 'member'],
            [self::CLINIC_OWNER, 'Ahmad Khalil', 'owner@example-clinic.com', $this->customerRole, 'customer'],
            [self::OTHER_CUSTOMER, 'Emad Nasser', 'emad@example-clinic.com', $this->customerRole, 'customer'],
        ];
        foreach ($people as [$id, $name, $email, $role, $kind]) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES ({$id}, '{$email}', true, true, CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
                        VALUES ({$id}, " . self::A . ", {$role}, 'active', '{$kind}', CURRENT_TIMESTAMP)");
        }
        SchemaFromMigrations::syncSequences($pdo);

        return $pdo;
    }
}
