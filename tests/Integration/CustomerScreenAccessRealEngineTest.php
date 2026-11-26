<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\CustomerAreaSettingsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\CustomerArea\CustomerScreenAccess;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\RoleGrantGuard;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Settings › Customer area: plugin screens' switches, and giving customers a
 * screen after the admin confirms (WHIT-1438; design section 41 §9, Q8).
 *
 * Example Bakery (1) has the Courses plugin's screen "courses", which needs
 * `courses_course:read`. Its customers hold four roles:
 *  - 903 "customer", the sign-up role, held by Ahmad Khalil (21);
 *  - 904 "front desk", which Emad Nasser (22) holds as a customer but Awss
 *    Haddad (12) holds as staff, so it is never changed from here;
 *  - 902 "guest", a global role (Ibrahim Mansour, 23), never changed from here.
 * Amro Saleh (11) is the admin and holds the permission himself.
 */
final class CustomerScreenAccessRealEngineTest extends TestCase
{
    private const ADMIN = 11;
    private const PERMISSION = 'courses_course:read';

    private PDO $pdo;
    private SettingsService $settings;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
             (901, 'owner', '', 1, CURRENT_TIMESTAMP),
             (902, 'guest', '', NULL, CURRENT_TIMESTAMP),
             (903, 'customer', '', 1, CURRENT_TIMESTAMP),
             (904, 'front desk', '', 1, CURRENT_TIMESTAMP),
             (905, 'other customer', '', 2, CURRENT_TIMESTAMP)"
        );
        foreach ([11 => 'Amro Saleh', 12 => 'Awss Haddad', 21 => 'Ahmad Khalil', 22 => 'Emad Nasser', 23 => 'Ibrahim Mansour', 31 => 'Other Customer'] as $id => $name) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
        }
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES
             (11, 1, 901, 'active', 'member', CURRENT_TIMESTAMP),
             (12, 1, 904, 'active', 'member', CURRENT_TIMESTAMP),
             (21, 1, 903, 'active', 'customer', CURRENT_TIMESTAMP),
             (22, 1, 904, 'active', 'customer', CURRENT_TIMESTAMP),
             (23, 1, 902, 'active', 'customer', CURRENT_TIMESTAMP),
             (31, 2, 905, 'active', 'customer', CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec("INSERT INTO permissions (id, name, description, created_at) VALUES (7001, 'courses_course:read', '', CURRENT_TIMESTAMP), (7002, 'courses_course:delete', '', CURRENT_TIMESTAMP)");
        $this->pdo->exec('INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (901, 7001, CURRENT_TIMESTAMP), (901, 7002, CURRENT_TIMESTAMP)');
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setTenant(1, 'signup.role_id', '903');
        $this->settings->setTenant(2, 'signup.role_id', '905');
        RoleChecker::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(1);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    public function testTheSettingsListEachScreenWithItsSwitchAndWhoHasIt(): void
    {
        $data = $this->call('show')['body']['data'];
        self::assertSame([], $data['hidden_screens']);
        $screen = $data['screens'][0];
        self::assertSame('courses', $screen['id']);
        self::assertTrue($screen['on'], 'on by default');
        self::assertFalse($screen['customers_have']);
        self::assertSame(
            [['customer', true, 1, false, null], ['front desk', false, 1, false, 'staff'], ['guest', false, 1, false, 'global']],
            array_map(static fn (array $r): array => [$r['name'], $r['signup'], $r['customers'], $r['has'], $r['skipped']], $screen['roles'])
        );
        self::assertNotContains('other customer', array_column($screen['roles'], 'name'), 'another workspace\'s roles never show');

        $off = $this->call('update', ['hidden_screens' => ['courses']]);
        self::assertSame(200, $off['status']);
        self::assertFalse($off['body']['data']['screens'][0]['on']);
        self::assertSame(422, $this->call('update', ['hidden_screens' => ['Not An Id']])['status']);
    }

    public function testGivingAScreenAsksFirstThenChangesOnlyTheWorkspacesOwnCustomerRoles(): void
    {
        $this->settings->setTenant(1, 'customer_area.hidden_screens', '["courses"]');

        $unconfirmed = $this->call('grant', [], ['screen' => 'courses']);
        self::assertSame(422, $unconfirmed['status']);
        self::assertSame('confirm_required', $unconfirmed['body']['details']['reason'] ?? null);
        self::assertSame(0, $this->holders(), 'nothing changes before the admin confirms');

        $out = $this->call('grant', ['confirm' => true], ['screen' => 'courses']);
        self::assertSame(200, $out['status'], (string) json_encode($out['body']));
        self::assertSame([['id' => 903, 'name' => 'customer']], $out['body']['data']['grant']['granted']);
        self::assertSame(
            [['id' => 904, 'name' => 'front desk', 'reason' => 'staff'], ['id' => 902, 'name' => 'guest', 'reason' => 'global']],
            $out['body']['data']['grant']['skipped']
        );
        self::assertTrue($out['body']['data']['screens'][0]['on'], 'giving turns the screen on');
        self::assertSame([], $out['body']['data']['hidden_screens']);

        // Only the sign-up role gained it; the staff-shared, global and other
        // workspace's roles did not.
        self::assertSame(1, $this->holders());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM role_permissions WHERE role_id = 903 AND permission_id = 7001'));
        self::assertSame(['role.updating', 'role.updated'], $this->events, 'audited like any role edit');
        $checker = $this->roleChecker();
        self::assertTrue($checker->hasPermissionForProfile(21, self::PERMISSION, 1), 'Ahmad sees the screen at once');
        self::assertFalse($checker->hasPermissionForProfile(12, self::PERMISSION, 1), 'staff are not widened');

        // Asking again changes nothing more.
        $this->events = [];
        $again = $this->call('grant', ['confirm' => true], ['screen' => 'courses']);
        self::assertSame([], $again['body']['data']['grant']['granted']);
        self::assertSame([], $this->events);
    }

    public function testOnlyWhatTheAdminHoldsAndOnlyCustomerGradeCanBeGiven(): void
    {
        // Awss has roles:write through the route, but not the screen's permission.
        $refused = $this->call('grant', ['confirm' => true], ['screen' => 'courses'], 12);
        self::assertSame(403, $refused['status']);
        self::assertSame(RoleGrantGuard::REASON, $refused['body']['details']['reason'] ?? null);
        self::assertSame(0, $this->holders());

        // A screen whose permission is not customer-grade is never given.
        $privileged = $this->call('grant', ['confirm' => true], ['screen' => 'manage'], self::ADMIN);
        self::assertSame(409, $privileged['status']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM role_permissions WHERE permission_id = 7002 AND role_id <> 901'));

        self::assertSame(404, $this->call('grant', ['confirm' => true], ['screen' => 'nothing'])['status']);
    }

    private function holders(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM role_permissions WHERE permission_id = 7001 AND role_id <> 901');
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $params
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $method, ?array $body = null, array $params = [], int $actor = self::ADMIN): array
    {
        $request = new Request($body === null ? 'GET' : 'POST', '/api/settings/customer-area', [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $actor];
        $response = $this->handler()->{$method}($request, $params);

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getBody(), true) ?? []];
    }

    private function handler(): CustomerAreaSettingsApiHandler
    {
        $hooks = new HookManager();
        $hooks->listen('role.updating', function (): void {
            $this->events[] = 'role.updating';
        });
        $hooks->listen('role.updated', function (): void {
            $this->events[] = 'role.updated';
        });
        $checker = $this->roleChecker();

        return new CustomerAreaSettingsApiHandler(
            $this->settings,
            static fn (int $tenantId): string => 'example-bakery',
            static fn (): array => [
                ['id' => 'courses', 'label' => ['en' => 'Courses', 'ar' => 'الدورات'], 'placement' => 'tab', 'screen' => 'blocks', 'requiredPermission' => self::PERMISSION, 'plugin' => 'Courses', 'namespace' => 'Courses'],
                ['id' => 'manage', 'label' => 'Manage', 'placement' => 'page', 'screen' => 'blocks', 'requiredPermission' => 'courses_course:delete', 'plugin' => 'Courses', 'namespace' => 'Courses'],
            ],
            new CustomerScreenAccess($this->pdo, $checker, new WorkspaceSignupSettings($this->pdo), $hooks),
            new RoleGrantGuard($checker),
        );
    }

    private function roleChecker(): RoleChecker
    {
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $registry->register('Courses', [self::PERMISSION, 'courses_course:delete']);
        $pdo = $this->pdo;

        return new RoleChecker(Database::withFactory(static fn (): PDO => $pdo), $registry);
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }
}
