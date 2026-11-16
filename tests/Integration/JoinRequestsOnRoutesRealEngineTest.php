<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Signup\CustomerSignupRouteSubject;
use Whity\Core\Signup\CustomerSignupRoutes;
use Whity\Core\Signup\CustomerSignupService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Database\Database;

/**
 * Join requests decided on a route (merge map A4).
 *
 * Example Bakery takes customers by approval. Amro and Awss can manage
 * sign-ups; Emad can't. Ibrahim asks to join.
 */
final class JoinRequestsOnRoutesRealEngineTest extends TestCase
{
    private const AMRO = 10;
    private const AWSS = 11;
    private const EMAD = 12;
    private const IBRAHIM = 13;

    private PDO $pdo;
    private int $tenant;
    private DocumentRouter $router;
    private CustomerSignupService $signups;
    private CustomerSignupRoutes $routes;
    private WorkspaceSignupSettings $settings;
    private RouteRecipientRepository $recipients;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();

        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();

        $this->settings = new WorkspaceSignupSettings($this->pdo);
        $this->signups = new CustomerSignupService($this->pdo, $this->settings, CustomerSafeRole::make($this->pdo));
        $subjects = new RouteSubjectRegistry();
        $subjects->register(new CustomerSignupRouteSubject($this->pdo, $this->signups));

        $rules = new RoutingRuleRegistry();
        $registry = $rules;
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver($this->pdo, new UserGroupRepository($this->pdo), static fn (): RoutingRuleRegistry => $registry)),
        );
        $this->recipients = new RouteRecipientRepository($this->pdo);
        $routes = new RouteRepository($this->pdo);
        $this->router = new DocumentRouter(
            $this->pdo,
            $routes,
            new RouteStepRepository($this->pdo),
            new RouteEventRepository($this->pdo),
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $rules,
            new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)),
            null,
            $subjects,
        );
        $this->routes = new CustomerSignupRoutes(
            $this->pdo,
            $this->router,
            $routes,
            new RouteTemplateRepository($this->pdo),
            $this->settings,
            new RoleChecker($database, new PermissionRegistry()),
        );
        $this->signups->whenPending(function (int $tenantId, int $signupId): void {
            $this->routes->issue($tenantId, $signupId);
        });
    }

    public function testARequestWaitsForEveryoneWhoCanManageSignUpsAndOneApprovalLetsThemIn(): void
    {
        $signupId = $this->ask();
        $route = $this->routes->openRoute($this->tenant, $signupId);
        self::assertNotNull($route, 'the request is on its route');
        self::assertNotNull($this->recipients->findOpenForProfile($this->tenant, (int) $route['id'], self::AMRO));
        self::assertNotNull($this->recipients->findOpenForProfile($this->tenant, (int) $route['id'], self::AWSS));
        self::assertNull($this->recipients->findOpenForProfile($this->tenant, (int) $route['id'], self::EMAD), 'only people who can manage sign-ups');

        $this->router->act($this->tenant, self::AMRO, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        self::assertSame(['approved', (string) self::AMRO], $this->signup($signupId));
        self::assertSame('active', $this->membershipStatus(self::IBRAHIM));
        self::assertNull($this->routes->openRoute($this->tenant, $signupId), 'one approval decides it');
    }

    public function testThePersonIsTurnedAwayOnceNobodyLeftWouldLetThemIn(): void
    {
        $signupId = $this->ask();
        $route = $this->routes->openRoute($this->tenant, $signupId);
        self::assertNotNull($route);

        // Any one of them can let Ibrahim in, so one "no" leaves it with Amro.
        $this->router->act($this->tenant, self::AWSS, $route, RouteAction::ACKNOWLEDGED, 'Not one of our customers', RouteVerdict::REJECTED);
        self::assertSame('pending', $this->signup($signupId)[0]);

        $this->router->act($this->tenant, self::AMRO, $route, RouteAction::ACKNOWLEDGED, 'Agreed', RouteVerdict::REJECTED);
        self::assertSame(['rejected', (string) self::AMRO], $this->signup($signupId));
        self::assertNull($this->membershipStatus(self::IBRAHIM));
    }

    public function testAWorkspacesOwnRouteTemplateReplacesTheDefault(): void
    {
        $templates = new RouteTemplateRepository($this->pdo);
        $design = $templates->create($this->tenant, 'New customers', null, null);
        $templates->replaceGraph($design, $this->tenant, [[
            'position' => 1,
            'rule_kind' => 'explicit',
            'rule_config' => ['profile_ids' => [self::EMAD]],
            'label' => 'Front desk checks the customer',
            'decision' => true,
            'decision_quorum' => null,
            'satisfied_by' => 'act',
            'canvas_x' => 0,
            'canvas_y' => 0,
        ]], []);
        $this->settings->saveRouteTemplate($this->tenant, $design);

        $route = $this->routes->openRoute($this->tenant, $this->ask());
        self::assertNotNull($route);
        self::assertNotNull($this->recipients->findOpenForProfile($this->tenant, (int) $route['id'], self::EMAD));
        self::assertNull($this->recipients->findOpenForProfile($this->tenant, (int) $route['id'], self::AMRO));
    }

    private function ask(): int
    {
        $result = $this->signups->joinVerified($this->tenant, self::IBRAHIM, 'ibrahim@example.com', 'Ibrahim Mansour');
        self::assertSame(CustomerSignupService::CONFIRM_PENDING, $result['result']);

        return (int) $this->q("SELECT MAX(id) FROM customer_signups WHERE status = 'pending'")->fetchColumn();
    }

    /** @return array{string, string} status and who decided */
    private function signup(int $id): array
    {
        $row = $this->q("SELECT status, decided_by FROM customer_signups WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [(string) $row['status'], (string) $row['decided_by']];
    }

    private function membershipStatus(int $profileId): ?string
    {
        $status = $this->q("SELECT status FROM memberships WHERE profile_id = {$profileId} AND tenant_id = {$this->tenant}")->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    private function q(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt;
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Example Bakery', 'bakery', CURRENT_TIMESTAMP)");
        $this->tenant = (int) $this->q("SELECT id FROM tenants WHERE slug = 'bakery'")->fetchColumn();

        $role = function (string $name, string $permission): int {
            $this->pdo->prepare('INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)')->execute([$name, '', $this->tenant]);
            $id = (int) $this->q("SELECT id FROM roles WHERE name = '{$name}' AND tenant_id = {$this->tenant}")->fetchColumn();
            $perm = (int) $this->q("SELECT id FROM permissions WHERE name = '{$permission}'")->fetchColumn();
            $this->pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES ({$id}, {$perm}, CURRENT_TIMESTAMP)");

            return $id;
        };
        $customers = $role('customers', 'forms:submit');
        $managers = $role('front-office', 'signup:manage');
        $staff = $role('bakers', 'forms:submit');

        foreach ([self::AMRO => ['Amro Saleh', $managers], self::AWSS => ['Awss Haddad', $managers], self::EMAD => ['Emad Nasser', $staff], self::IBRAHIM => ['Ibrahim Mansour', null]] as $id => [$name, $roleId]) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                              VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            if ($roleId !== null) {
                $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, {$this->tenant}, {$roleId}, 'active', 'member', CURRENT_TIMESTAMP)");
            }
        }
        (new WorkspaceSignupSettings($this->pdo))->save($this->tenant, WorkspaceSignupSettings::MODE_APPROVAL, $customers, [], self::AMRO);
        SchemaFromMigrations::syncSequences($this->pdo);
    }
}
