<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PublicSignupApiHandler;
use Whity\Api\WorkspaceSignupApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Http\HttpFetcher;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Signup\CustomerSignupMailer;
use Whity\Core\Signup\CustomerSignupService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * Workspace-controlled customer sign-up (WHIT-915) end to end, against the
 * migration-built schema: in-memory SQLite locally, real PostgreSQL on the
 * postgres jobs.
 *
 * Covers each mode, the customer-safe role rule on save and at sign-up, that no
 * tenant is ever created, that workspace A's sign-up cannot reach workspace B,
 * that the public endpoint cannot be used to enumerate addresses, the domain
 * restriction, approval by the workspace's own admin, and the RBAC ceiling on a
 * customer membership.
 */
final class CustomerSignupRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const ADMIN_A = 10;
    private const ADMIN_B = 11;
    private const STAFF_A = 12;

    private PDO $pdo;
    private PublicSignupApiHandler $public;
    private WorkspaceSignupApiHandler $admin;
    private RoleChecker $roleChecker;
    private HookManager $hooks;

    /** @var list<array{event: string, data: array<string, mixed>}> */
    private array $events = [];

    /** @var list<array{to: string, subject: string, body: string}> */
    private array $sent = [];

    private int $adminRole;
    private int $userRole;
    private int $customerRole;
    private int $existingProfileId;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->pdo = $this->makeSchema();

        $mailer = new class (function (string $to, string $subject, string $body): void {
            $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        }) implements Mailer {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                ($this->sink)($toEmail, $subject, $textBody);
            }
        };

        $this->hooks = new HookManager();
        foreach (['membership.customer_joined', 'user.membership.added'] as $event) {
            $this->hooks->listen($event, function (array $data) use ($event): array {
                $this->events[] = ['event' => $event, 'data' => $data];

                return $data;
            });
        }

        $globals = new GlobalSettingsRepository($this->pdo);
        $globals->set(BotCheck::SETTING_ALLOW_NONE, '1');
        $bot = new BotCheck($globals, new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'), new HttpFetcher());

        $settingsService = new SettingsService($globals, new TenantSettingsRepository($this->pdo));
        $signupSettings = new WorkspaceSignupSettings($this->pdo);
        $safe = CustomerSafeRole::make($this->pdo);
        $service = new CustomerSignupService($this->pdo, $signupSettings, $safe, $this->hooks);
        $audit = new AuditLogger($this->pdo, new NullLogger());
        $hostRepo = new TenantHostRepository($this->pdo);

        $this->public = new PublicSignupApiHandler(
            $service,
            $signupSettings,
            $hostRepo,
            new HostResolver($hostRepo, 'whity.test'),
            $bot,
            new CustomerSignupMailer($mailer, 'https://app.test/join/confirm', new EmailLayout(), $settingsService),
            new DatabaseSharedStore($this->pdo),
            $audit
        );

        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $this->roleChecker = new RoleChecker($this->databaseFor($this->pdo), $registry);

        $this->admin = new WorkspaceSignupApiHandler(
            $this->pdo,
            $this->roleChecker,
            $signupSettings,
            $safe,
            $service,
            $audit,
            $bot
        );
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        unset($_GET['token'], $_GET['status']);
    }

    // ==================== modes ====================

    public function testSignupIsOffByDefaultAndAnswersLikeAnUnknownWorkspace(): void
    {
        $off = $this->info('tenant-a');
        $unknown = $this->info('no-such-workspace');

        self::assertSame(404, $off->getStatusCode());
        self::assertSame($unknown->getStatusCode(), $off->getStatusCode());
        self::assertSame($unknown->getBody(), $off->getBody());

        self::assertSame(404, $this->request('tenant-a', 'someone@example.test')->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM customer_signups'));
        self::assertSame([], $this->sent);
    }

    public function testOpenModeJoinsAsACustomerOnceTheEmailIsConfirmed(): void
    {
        $this->configure(self::TENANT_A, self::ADMIN_A, 'open', $this->customerRole);
        $tenantsBefore = $this->tenantCount();

        $info = $this->decode($this->info('tenant-a'))['data'];
        self::assertSame('tenant-a', $info['workspace_slug']);
        self::assertFalse($info['approval_required']);

        self::assertSame(202, $this->request('tenant-a', 'new.customer@example.test', 'Nadia Customer')->getStatusCode());
        // Nothing is granted before the mailbox is proven.
        self::assertSame(0, $this->membershipCount(self::TENANT_A, 'new.customer@example.test'));

        $token = $this->tokenFromMail(0);
        $preview = $this->decode($this->preview($token))['data'];
        self::assertTrue($preview['requires_password']);
        self::assertSame('tenant-a', $preview['workspace_name']);

        // A new address must choose a password; the link survives the miss.
        self::assertSame(422, $this->confirm($token, null)->getStatusCode());

        $res = $this->confirm($token, 'a-long-enough-password');
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame('joined', $this->decode($res)['data']['status']);

        $row = $this->membership(self::TENANT_A, 'new.customer@example.test');
        self::assertSame('active', $row['status']);
        self::assertSame('customer', $row['kind']);
        self::assertSame($this->customerRole, (int) $row['role_id']);
        self::assertSame('local', $this->scalar(
            "SELECT p.auth_method FROM profiles p JOIN profile_emails pe ON pe.profile_id = p.id WHERE pe.email = 'new.customer@example.test'"
        ));
        self::assertSame('Nadia Customer', $this->scalar(
            "SELECT p.display_name FROM profiles p JOIN profile_emails pe ON pe.profile_id = p.id WHERE pe.email = 'new.customer@example.test'"
        ));

        $joined = $this->eventsNamed('membership.customer_joined');
        self::assertCount(1, $joined);
        self::assertSame(self::TENANT_A, $joined[0]['tenant_id']);
        self::assertSame('open', $joined[0]['via']);
        self::assertSame((int) $row['id'], $joined[0]['membership_id']);

        // Single use.
        self::assertSame(400, $this->confirm($token, 'a-long-enough-password')->getStatusCode());
        self::assertSame($tenantsBefore, $this->tenantCount(), 'customer sign-up must never create a workspace');
    }

    public function testApprovalModeWaitsForTheWorkspaceAdmin(): void
    {
        $this->configure(self::TENANT_A, self::ADMIN_A, 'approval', $this->customerRole);
        $tenantsBefore = $this->tenantCount();

        $this->request('tenant-a', 'waiting@example.test', 'Waiting Person');
        $res = $this->confirm($this->tokenFromMail(0), 'a-long-enough-password');
        self::assertSame('pending_approval', $this->decode($res)['data']['status']);
        self::assertSame(0, $this->membershipCount(self::TENANT_A, 'waiting@example.test'));
        self::assertSame([], $this->eventsNamed('membership.customer_joined'));

        // Somebody without signup:manage cannot see or decide the queue.
        self::assertSame(403, $this->listRequests(self::TENANT_A, self::STAFF_A)->getStatusCode());

        $queue = $this->decode($this->listRequests(self::TENANT_A, self::ADMIN_A));
        self::assertCount(1, $queue['data']);
        self::assertSame('waiting@example.test', $queue['data'][0]['email']);
        self::assertSame('Waiting Person', $queue['data'][0]['display_name']);
        $requestId = (int) $queue['data'][0]['id'];

        self::assertSame(403, $this->decide(self::TENANT_A, self::STAFF_A, $requestId, true)->getStatusCode());

        $approved = $this->decide(self::TENANT_A, self::ADMIN_A, $requestId, true);
        self::assertSame(200, $approved->getStatusCode(), $approved->getBody());

        $row = $this->membership(self::TENANT_A, 'waiting@example.test');
        self::assertSame('active', $row['status']);
        self::assertSame('customer', $row['kind']);

        $joined = $this->eventsNamed('membership.customer_joined');
        self::assertCount(1, $joined);
        self::assertSame('approval', $joined[0]['via']);
        self::assertSame(self::ADMIN_A, $joined[0]['approved_by']);

        self::assertSame([], $this->decode($this->listRequests(self::TENANT_A, self::ADMIN_A))['data']);
        self::assertSame($tenantsBefore, $this->tenantCount());
    }

    public function testARejectedPersonCannotQueueAgainThroughSignup(): void
    {
        $this->configure(self::TENANT_A, self::ADMIN_A, 'approval', $this->customerRole);

        $this->request('tenant-a', 'turned.away@example.test', 'Turned Away');
        $this->confirm($this->tokenFromMail(0), 'a-long-enough-password');
        $id = (int) $this->decode($this->listRequests(self::TENANT_A, self::ADMIN_A))['data'][0]['id'];

        self::assertSame(200, $this->decide(self::TENANT_A, self::ADMIN_A, $id, false)->getStatusCode());
        self::assertSame(0, $this->membershipCount(self::TENANT_A, 'turned.away@example.test'));

        // The same address asks again: the request looks like any other, the
        // link says no, and the queue stays empty.
        self::assertSame(202, $this->request('tenant-a', 'turned.away@example.test', 'Turned Away')->getStatusCode());
        $again = $this->confirm($this->tokenFromMail(1), null);
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('refused', $this->decode($again)['details']['reason']);
        self::assertSame([], $this->decode($this->listRequests(self::TENANT_A, self::ADMIN_A))['data']);
    }

    // ==================== customer-safe roles ====================

    public function testARoleThatIsNotCustomerSafeIsRefusedOnSave(): void
    {
        $res = $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['mode' => 'open', 'role_id' => $this->adminRole]);
        self::assertSame(422, $res->getStatusCode());
        self::assertArrayHasKey('role_id', $this->decode($res)['details']);

        // A workspace role holding one privileged permission is refused too.
        $risky = $this->makeTenantRole(self::TENANT_A, 'helpdesk', [CorePermissions::USERS_READ]);
        self::assertSame(422, $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['mode' => 'open', 'role_id' => $risky])->getStatusCode());

        // ... including through its parent.
        $child = $this->makeTenantRole(self::TENANT_A, 'helpdesk-junior', [], $risky);
        self::assertSame(422, $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['mode' => 'open', 'role_id' => $child])->getStatusCode());

        // Another workspace's role is not assignable at all.
        $foreign = $this->makeTenantRole(self::TENANT_B, 'b-customers', []);
        self::assertSame(422, $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['mode' => 'open', 'role_id' => $foreign])->getStatusCode());

        // A mode other than Off needs a role.
        self::assertSame(422, $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['mode' => 'open'])->getStatusCode());

        $settings = $this->decode($this->getSettings(self::TENANT_A, self::ADMIN_A))['data'];
        self::assertSame('off', $settings['mode']);
        $eligible = array_column($settings['eligible_roles'], 'id');
        self::assertContains($this->customerRole, $eligible);
        self::assertNotContains($this->adminRole, $eligible);
        self::assertNotContains($risky, $eligible);
        self::assertNotContains($foreign, $eligible);
    }

    public function testTheRoleIsCheckedAgainWhenSomeoneSignsUp(): void
    {
        $role = $this->makeTenantRole(self::TENANT_A, 'shoppers', [CorePermissions::FORMS_SUBMIT]);
        $this->configure(self::TENANT_A, self::ADMIN_A, 'open', $role);
        $this->request('tenant-a', 'late@example.test', 'Late Arrival');

        // After saving, somebody gives the role an admin permission.
        $this->grant($role, CorePermissions::ROLES_WRITE);

        $res = $this->confirm($this->tokenFromMail(0), 'a-long-enough-password');
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('closed', $this->decode($res)['details']['reason']);
        self::assertSame(0, $this->membershipCount(self::TENANT_A, 'late@example.test'));
        self::assertFalse($this->decode($this->getSettings(self::TENANT_A, self::ADMIN_A))['data']['role_customer_safe']);
    }

    public function testACustomerNeverHoldsPrivilegedPermissionsWhateverTheirRole(): void
    {
        $this->configure(self::TENANT_A, self::ADMIN_A, 'open', $this->customerRole);
        $this->request('tenant-a', 'promoted@example.test', 'Promoted');
        $this->confirm($this->tokenFromMail(0), 'a-long-enough-password');
        $row = $this->membership(self::TENANT_A, 'promoted@example.test');
        $profileId = (int) $row['profile_id'];

        // Somebody puts the customer straight into the admin role.
        $this->pdo->exec('UPDATE memberships SET role_id = ' . $this->adminRole . ' WHERE id = ' . (int) $row['id']);
        RoleChecker::clearCache();

        self::assertTrue($this->roleChecker->isCustomerOnly($profileId, self::TENANT_A));
        self::assertFalse($this->roleChecker->hasPermissionForProfile($profileId, CorePermissions::ROLES_WRITE, self::TENANT_A));
        self::assertFalse($this->roleChecker->hasPermissionForProfile($profileId, CorePermissions::SIGNUP_MANAGE, self::TENANT_A));
        foreach ($this->roleChecker->getEffectivePermissionsForProfile($profileId, self::TENANT_A) as $permission) {
            self::assertFalse(CustomerSafeRole::isPrivilegedPermission($permission), $permission);
        }

        // Staff in the same role keep it.
        self::assertFalse($this->roleChecker->isCustomerOnly(self::ADMIN_A, self::TENANT_A));
        self::assertTrue($this->roleChecker->hasPermissionForProfile(self::ADMIN_A, CorePermissions::ROLES_WRITE, self::TENANT_A));
    }

    // ==================== isolation ====================

    public function testWorkspaceASignupCannotCreateAMembershipInWorkspaceB(): void
    {
        $this->configure(self::TENANT_A, self::ADMIN_A, 'approval', $this->customerRole);
        $this->configure(self::TENANT_B, self::ADMIN_B, 'open', $this->makeTenantRole(self::TENANT_B, 'b-customers', [CorePermissions::FORMS_SUBMIT]));

        // A's slug on B's host is refused like an unknown workspace.
        self::assertSame(404, $this->request('tenant-a', 'mixed@example.test', 'Mixed', 'tenant-b.whity.test')->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM customer_signups'));

        // On A's own host it is accepted, and the link only ever reaches A.
        self::assertSame(202, $this->request('tenant-a', 'mixed@example.test', 'Mixed', 'tenant-a.whity.test')->getStatusCode());
        self::assertSame('pending_approval', $this->decode($this->confirm($this->tokenFromMail(0), 'a-long-enough-password'))['data']['status']);
        self::assertSame(0, $this->membershipCount(self::TENANT_B, 'mixed@example.test'));

        // B's admin cannot see or decide A's queue.
        $aId = (int) $this->scalar("SELECT id FROM customer_signups WHERE email = 'mixed@example.test' AND status = 'pending'");
        self::assertSame([], $this->decode($this->listRequests(self::TENANT_B, self::ADMIN_B))['data']);
        self::assertSame(404, $this->decide(self::TENANT_B, self::ADMIN_B, $aId, true)->getStatusCode());
        self::assertSame(0, $this->membershipCount(self::TENANT_B, 'mixed@example.test'));
        self::assertSame(0, $this->membershipCount(self::TENANT_A, 'mixed@example.test'));

        // A's admin approving it lands in A only.
        self::assertSame(200, $this->decide(self::TENANT_A, self::ADMIN_A, $aId, true)->getStatusCode());
        self::assertSame(1, $this->membershipCount(self::TENANT_A, 'mixed@example.test'));
        self::assertSame(0, $this->membershipCount(self::TENANT_B, 'mixed@example.test'));
    }

    // ==================== enumeration ====================

    public function testTheRequestNeverRevealsWhetherAnAddressHasAnAccount(): void
    {
        $this->configure(self::TENANT_A, self::ADMIN_A, 'open', $this->customerRole);
        $existingStaffEmail = (string) $this->scalar('SELECT email FROM profile_emails WHERE profile_id = ' . self::STAFF_A);

        $unknown = $this->request('tenant-a', 'nobody@example.test', 'Someone');
        $known = $this->request('tenant-a', 'bob@elsewhere.example', 'Someone');
        $member = $this->request('tenant-a', $existingStaffEmail, 'Someone');

        foreach ([$known, $member] as $res) {
            self::assertSame($unknown->getStatusCode(), $res->getStatusCode());
            self::assertSame($unknown->getBody(), $res->getBody());
        }
        self::assertSame(202, $unknown->getStatusCode());

        // The three mails read the same apart from the token.
        self::assertCount(3, $this->sent);
        self::assertSame($this->stripToken($this->sent[0]['body']), $this->stripToken($this->sent[1]['body']));
        self::assertSame($this->stripToken($this->sent[0]['body']), $this->stripToken($this->sent[2]['body']));
        self::assertSame($this->sent[0]['subject'], $this->sent[1]['subject']);

        // Over the per-address limit the answer is unchanged and nothing is sent.
        $this->request('tenant-a', 'nobody@example.test', 'Someone');
        $this->request('tenant-a', 'nobody@example.test', 'Someone');
        $over = $this->request('tenant-a', 'nobody@example.test', 'Someone');
        self::assertSame($unknown->getBody(), $over->getBody());
        self::assertCount(5, $this->sent);
    }

    public function testAnExistingProfileJoinsWithItsOwnPasswordUnchanged(): void
    {
        $this->configure(self::TENANT_A, self::ADMIN_A, 'open', $this->customerRole);
        $this->request('tenant-a', 'bob@elsewhere.example', 'Not Bob');
        $token = $this->tokenFromMail(0);

        self::assertFalse($this->decode($this->preview($token))['data']['requires_password']);
        $res = $this->confirm($token, 'an-attempt-to-reset-it');
        self::assertSame('joined', $this->decode($res)['data']['status']);

        self::assertSame('$2y$10$original', $this->scalar('SELECT password_hash FROM profiles WHERE id = ' . $this->existingProfileId));
        self::assertSame('Bob', $this->scalar('SELECT display_name FROM profiles WHERE id = ' . $this->existingProfileId));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM profile_emails WHERE email = 'bob@elsewhere.example'"));

        // Staff in B, customer in A: one profile, two kinds.
        $kinds = $this->statement(
            'SELECT tenant_id, kind FROM memberships WHERE profile_id = ' . $this->existingProfileId . ' ORDER BY tenant_id'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([self::TENANT_A => 'customer', self::TENANT_B => 'member'], array_map('strval', $kinds));
    }

    public function testStaffWhoSignUpStayStaff(): void
    {
        $this->configure(self::TENANT_A, self::ADMIN_A, 'open', $this->customerRole);
        $email = (string) $this->scalar('SELECT email FROM profile_emails WHERE profile_id = ' . self::STAFF_A);

        $this->request('tenant-a', $email, 'Staff');
        $res = $this->confirm($this->tokenFromMail(0), null);
        self::assertSame('already_member', $this->decode($res)['data']['status']);
        self::assertSame(['member'], array_map('strval', $this->statement(
            'SELECT kind FROM memberships WHERE tenant_id = ' . self::TENANT_A . ' AND profile_id = ' . self::STAFF_A
        )->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame([], $this->eventsNamed('membership.customer_joined'));
    }

    // ==================== domains ====================

    public function testTheDomainRestrictionIsEnforced(): void
    {
        $save = $this->saveSettings(self::TENANT_A, self::ADMIN_A, [
            'mode' => 'open',
            'role_id' => $this->customerRole,
            'allowed_domains' => ['@Uni.Example', 'partner.example'],
        ]);
        self::assertSame(200, $save->getStatusCode(), $save->getBody());
        self::assertSame(['uni.example', 'partner.example'], $this->decode($save)['data']['allowed_domains']);
        self::assertSame(['uni.example', 'partner.example'], $this->decode($this->info('tenant-a'))['data']['allowed_domains']);

        self::assertSame(422, $this->request('tenant-a', 'outsider@gmail.example', 'Outsider')->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM customer_signups'));

        self::assertSame(202, $this->request('tenant-a', 'student@uni.example', 'Student')->getStatusCode());

        // Narrowed after the request: the link no longer gets in.
        $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['allowed_domains' => ['partner.example']]);
        self::assertSame(409, $this->confirm($this->tokenFromMail(0), 'a-long-enough-password')->getStatusCode());
        self::assertSame(0, $this->membershipCount(self::TENANT_A, 'student@uni.example'));

        $bad = $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['allowed_domains' => ['partner.example', 'not a domain']]);
        self::assertSame(422, $bad->getStatusCode());
        // A stable code and the line at fault, so the page can say it in Arabic too (WHIT-916).
        self::assertSame('domain_invalid', $this->decode($bad)['details']['code']);
        self::assertSame('not a domain', $this->decode($bad)['details']['value']);
        self::assertSame(422, $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['allowed_domains' => ['10.0.0.1']])->getStatusCode());
    }

    public function testAWorkspaceWithNoSlugCannotTurnSignupOn(): void
    {
        // Its sign-up page lives at /join/<slug>; with no slug nobody could reach it (WHIT-916).
        $this->pdo->exec("UPDATE tenants SET slug = '' WHERE id = " . self::TENANT_A);

        $res = $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['mode' => 'open', 'role_id' => $this->customerRole]);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame('workspace_slug_missing', $this->decode($res)['details']['code']);

        // Off, and the other settings, can still be saved.
        self::assertSame(200, $this->saveSettings(self::TENANT_A, self::ADMIN_A, ['mode' => 'off', 'role_id' => $this->customerRole])->getStatusCode());
        self::assertNull($this->decode($this->getSettings(self::TENANT_A, self::ADMIN_A))['data']['workspace_slug']);
    }

    public function testARejectedRequestIsListedAndCanStillBeApproved(): void
    {
        $this->configure(self::TENANT_A, self::ADMIN_A, 'approval', $this->customerRole);
        $this->request('tenant-a', 'second.chance@example.test', 'Second Chance');
        $this->confirm($this->tokenFromMail(0), 'a-long-enough-password');
        $id = (int) $this->decode($this->listRequests(self::TENANT_A, self::ADMIN_A))['data'][0]['id'];
        $this->decide(self::TENANT_A, self::ADMIN_A, $id, false);

        $rejected = $this->decode($this->admin->listRequests(
            $this->authed(self::TENANT_A, self::ADMIN_A, 'GET', '/api/signup/requests?status=rejected')
        ))['data'];
        self::assertSame([$id], array_column($rejected, 'id'));
        self::assertSame('admin-a', $rejected[0]['decided_by_name']);

        self::assertSame(200, $this->decide(self::TENANT_A, self::ADMIN_A, $id, true)->getStatusCode());
        self::assertSame(1, $this->membershipCount(self::TENANT_A, 'second.chance@example.test'));
    }

    public function testPrivilegedPermissionClassification(): void
    {
        foreach (['roles:write', 'users:read', 'settings:read', 'forms:manage', 'documents:approve', 'shop:manage', '*', 'nonsense'] as $p) {
            self::assertTrue(CustomerSafeRole::isPrivilegedPermission($p), $p);
        }
        foreach (['forms:submit', 'documents:read', 'documents:comment', 'shop:order'] as $p) {
            self::assertFalse(CustomerSafeRole::isPrivilegedPermission($p), $p);
        }
    }

    // ==================== helpers ====================

    private function configure(int $tenantId, int $actorId, string $mode, int $roleId): void
    {
        $res = $this->saveSettings($tenantId, $actorId, ['mode' => $mode, 'role_id' => $roleId]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
    }

    /** @param array<string, mixed> $body */
    private function saveSettings(int $tenantId, int $actorId, array $body): Response
    {
        return $this->admin->updateSettings($this->authed($tenantId, $actorId, 'PUT', '/api/signup/settings', $body));
    }

    private function getSettings(int $tenantId, int $actorId): Response
    {
        return $this->admin->getSettings($this->authed($tenantId, $actorId, 'GET', '/api/signup/settings'));
    }

    private function listRequests(int $tenantId, int $actorId): Response
    {
        return $this->admin->listRequests($this->authed($tenantId, $actorId, 'GET', '/api/signup/requests?status=pending'));
    }

    private function decide(int $tenantId, int $actorId, int $id, bool $approve): Response
    {
        $request = $this->authed($tenantId, $actorId, 'POST', '/api/signup/requests/' . $id . ($approve ? '/approve' : '/reject'));

        return $approve
            ? $this->admin->approve($request, ['id' => $id])
            : $this->admin->reject($request, ['id' => $id]);
    }

    private function info(string $slug, string $host = 'app.test'): Response
    {
        TenantContext::reset();

        return $this->public->info(
            new Request('GET', '/api/public/signup/workspaces/' . $slug, ['Host' => $host, ClientIp::HEADER => '203.0.113.7'], ''),
            ['workspace' => $slug]
        );
    }

    private function request(string $slug, string $email, string $name = 'Someone', string $host = 'app.test'): Response
    {
        TenantContext::reset();

        return $this->public->request(
            new Request(
                'POST',
                '/api/public/signup/workspaces/' . $slug,
                ['Content-Type' => 'application/json', 'Host' => $host, ClientIp::HEADER => '203.0.113.7'],
                (string) json_encode(['email' => $email, 'display_name' => $name])
            ),
            ['workspace' => $slug]
        );
    }

    private function preview(string $token): Response
    {
        TenantContext::reset();

        return $this->public->preview(new Request(
            'GET',
            '/api/public/signup/confirm?token=' . urlencode($token),
            [ClientIp::HEADER => '203.0.113.8'],
            ''
        ));
    }

    private function confirm(string $token, ?string $password): Response
    {
        TenantContext::reset();
        $body = ['token' => $token];
        if ($password !== null) {
            $body['password'] = $password;
        }

        return $this->public->confirm(new Request(
            'POST',
            '/api/public/signup/confirm',
            ['Content-Type' => 'application/json', ClientIp::HEADER => '203.0.113.8'],
            (string) json_encode($body)
        ));
    }

    /** @param array<string, mixed>|null $body */
    private function authed(int $tenantId, int $actorId, string $method, string $path, ?array $body = null): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        RoleChecker::clearCache();

        $request = new Request(
            $method,
            $path,
            ['Content-Type' => 'application/json'],
            $body !== null ? (string) json_encode($body) : ''
        );
        $request->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    private function tokenFromMail(int $index): string
    {
        self::assertArrayHasKey($index, $this->sent);
        $matches = [];
        self::assertSame(1, preg_match('/token=([0-9a-f]{64})/', $this->sent[$index]['body'], $matches));

        return (string) ($matches[1] ?? '');
    }

    private function stripToken(string $body): string
    {
        return (string) preg_replace('/[0-9a-f]{64}/', 'TOKEN', $body);
    }

    /** @return list<array<string, mixed>> */
    private function eventsNamed(string $event): array
    {
        return array_values(array_map(
            static fn (array $e): array => $e['data'],
            array_filter($this->events, static fn (array $e): bool => $e['event'] === $event)
        ));
    }

    /** @return array<string, mixed> */
    private function membership(int $tenantId, string $email): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id
              WHERE m.tenant_id = ? AND pe.email = ?'
        );
        $stmt->execute([$tenantId, $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'no membership for ' . $email);

        return $row;
    }

    private function membershipCount(int $tenantId, string $email): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id
              WHERE m.tenant_id = ? AND pe.email = ?'
        );
        $stmt->execute([$tenantId, $email]);

        return (int) $stmt->fetchColumn();
    }

    private function tenantCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM tenants');
    }

    /** @param list<string> $permissions */
    private function makeTenantRole(int $tenantId, string $name, array $permissions, ?int $parentId = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO roles (name, description, tenant_id, parent_id, created_at) VALUES (?, ?, ?, ?, NOW())'
        )->execute([$name, '', $tenantId, $parentId]);
        $id = (int) $this->scalar("SELECT id FROM roles WHERE name = '" . $name . "' AND tenant_id = " . $tenantId);
        foreach ($permissions as $permission) {
            $this->grant($id, $permission);
        }

        return $id;
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT INTO permissions (name, description, created_at) VALUES (?, ?, NOW()) ON CONFLICT (name) DO NOTHING')
            ->execute([$permission, '']);
        $permissionId = (int) $this->scalar("SELECT id FROM permissions WHERE name = '" . $permission . "'");
        $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $permissionId]);
        RoleChecker::clearCache();
    }

    private function scalar(string $sql): mixed
    {
        return $this->statement($sql)->fetchColumn();
    }

    private function statement(string $sql, ?PDO $pdo = null): \PDOStatement
    {
        $stmt = ($pdo ?? $this->pdo)->query($sql);
        if ($stmt === false) {
            self::fail('query failed: ' . $sql);
        }

        return $stmt;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());

        return $decoded;
    }

    private function databaseFor(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * Two workspaces, each with an admin (the seeded `admin` role, which holds
     * settings:write and so signup:manage), a staff member in A with the base
     * `user` role, and Bob, who already has an account as staff in B.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT_A . ", 'tenant-a', 'tenant-a')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT_B . ", 'tenant-b', 'tenant-b')");

        $this->adminRole = (int) $this->statement("SELECT id FROM roles WHERE name = 'admin' AND tenant_id IS NULL", $pdo)->fetchColumn();
        $this->userRole = (int) $this->statement("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL", $pdo)->fetchColumn();
        self::assertGreaterThan(0, $this->adminRole);

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (" . self::ADMIN_A . ", 'admin-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::ADMIN_B . ", 'admin-b', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::STAFF_A . ", 'staff-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (" . self::ADMIN_A . ", 'admin-a@example.test', true, true, CURRENT_TIMESTAMP),
                (" . self::ADMIN_B . ", 'admin-b@example.test', true, true, CURRENT_TIMESTAMP),
                (" . self::STAFF_A . ", 'staff-a@example.test', true, true, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES
                (" . self::ADMIN_A . ", " . self::TENANT_A . ", {$this->adminRole}, 'active', CURRENT_TIMESTAMP),
                (" . self::ADMIN_B . ", " . self::TENANT_B . ", {$this->adminRole}, 'active', CURRENT_TIMESTAMP),
                (" . self::STAFF_A . ", " . self::TENANT_A . ", {$this->userRole}, 'active', CURRENT_TIMESTAMP)
        ");

        $pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Bob', '\$2y\$10\$original', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->existingProfileId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$this->existingProfileId, 'bob@elsewhere.example']);
        $pdo->exec(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES ('
            . $this->existingProfileId . ', ' . self::TENANT_B . ", {$this->userRole}, 'active', NOW())"
        );

        SchemaFromMigrations::syncSequences($pdo);

        // A role that may only submit forms, owned by A: customer-safe.
        $pdo->exec("INSERT INTO roles (name, description, tenant_id, created_at) VALUES ('customers', '', " . self::TENANT_A . ', CURRENT_TIMESTAMP)');
        $this->customerRole = (int) $this->statement("SELECT id FROM roles WHERE name = 'customers' AND tenant_id = " . self::TENANT_A, $pdo)->fetchColumn();
        $permissionId = (int) $this->statement("SELECT id FROM permissions WHERE name = 'forms:submit'", $pdo)->fetchColumn();
        self::assertGreaterThan(0, $permissionId);
        $pdo->exec('INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (' . $this->customerRole . ', ' . $permissionId . ', CURRENT_TIMESTAMP)');

        return $pdo;
    }
}
