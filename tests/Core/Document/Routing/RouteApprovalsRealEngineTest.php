<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteResume;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteApprovals;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteSubjectVersionRepository;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingPresenter;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * WHIT-1018: who approved a route, as its final document prints them.
 *
 * Runs on SQLite, and on real PostgreSQL when PHPUNIT_PG_DSN is set
 * ({@see SchemaFromMigrations::make()}).
 */
final class RouteApprovalsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const AUTHOR = 10;
    private const CLERK = 11;
    private const MEMBER_A = 12;

    private PDO $pdo;
    private DocumentRouter $router;
    private RouteRepository $routes;
    private RouteStepRepository $steps;
    private RouteEventRepository $events;
    private RouteRecipientRepository $recipients;
    private RouteSubjectVersionRepository $versions;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->routes = new RouteRepository($this->pdo);
        $this->steps = new RouteStepRepository($this->pdo);
        $this->events = new RouteEventRepository($this->pdo);
        $this->recipients = new RouteRecipientRepository($this->pdo);
        $this->versions = new RouteSubjectVersionRepository($this->pdo);

        $rules = new RoutingRuleRegistry();
        $registry = $rules;
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver(
                $this->pdo,
                new UserGroupRepository($this->pdo),
                static fn (): RoutingRuleRegistry => $registry
            ))
        );

        $subjects = new RouteSubjectRegistry();
        $subjects->register(new DocumentRouteSubject($this->pdo));

        $this->router = new DocumentRouter(
            $this->pdo,
            $this->routes,
            $this->steps,
            $this->events,
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $rules,
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo)
            ),
            null,
            $subjects,
            $this->versions,
        );
    }


    public function testAPersonsLatestVerdictAtAStepIsWhatCountsAndEachApprovalIsListedOnce(): void
    {
        $route = $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $this->seedDocument(self::AUTHOR)], 'Ethics application', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::CLERK]],
                'label' => 'Pre-check',
                'decision' => true,
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::MEMBER_A, 13]],
                'label' => 'Ethics committee',
                'decision' => true,
                'decision_quorum' => 'all',
            ],
        ])['route'];

        $this->approve(self::CLERK, $route);
        $this->approve(self::MEMBER_A, $route);
        // The other member sends it back; on resubmit the committee is asked
        // again and both approve a second time.
        $this->router->act(self::TENANT, 13, $route, RouteAction::SENT_BACK, 'Attach the consent form.', null, RouteResume::SENDER);
        $this->router->act(self::TENANT, self::AUTHOR, $route, RouteAction::RESUBMITTED, 'Attached.');
        $this->approve(13, $route);
        $this->approve(self::MEMBER_A, $route);

        $approvals = new RouteApprovals($this->pdo);
        self::assertSame(
            [['clerk', 1, 'Pre-check'], ['member b', 2, 'Ethics committee'], ['member a', 2, 'Ethics committee']],
            array_map(
                static fn (array $a): array => [$a['name'], $a['position'], $a['step']],
                $approvals->forRoute(self::TENANT, (int) $route['id']),
            ),
            'each person once, in the order of their standing approval',
        );

        $values = $approvals->values(self::TENANT, (int) $route['id'], 'Approved');
        self::assertSame('3', $values['route.approvals.count']);
        self::assertSame('clerk', $values['route.approvals.1.name']);
        self::assertSame('2', $values['route.step.2.approvals.count']);
        self::assertSame('member a', $values['route.step.2.approvals.2.name']);
        self::assertSame('Ethics committee', $values['route.step.2.approvals.2.step']);
        self::assertStringStartsWith('Approved ', (string) $values['route.step.1.approvals.1.mark']);
        self::assertSame([], $approvals->forRoute(2, (int) $route['id']), 'approvals belong to their workspace');
    }

    public function testAnApprovalLaterRejectedAtTheSameStepIsNotListed(): void
    {
        $route = $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $this->seedDocument(self::AUTHOR)], 'Ethics application', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::MEMBER_A, 13]],
                'decision' => true,
                'decision_quorum' => 'all',
            ],
        ])['route'];

        $this->approve(self::MEMBER_A, $route);
        $this->router->act(self::TENANT, 13, $route, RouteAction::SENT_BACK, 'Fix it.', null, RouteResume::SENDER);
        $this->router->act(self::TENANT, self::AUTHOR, $route, RouteAction::RESUBMITTED, null);
        $this->router->act(self::TENANT, self::MEMBER_A, $route, RouteAction::ACKNOWLEDGED, 'Not now.', RouteVerdict::REJECTED);

        self::assertSame([], (new RouteApprovals($this->pdo))->forRoute(self::TENANT, (int) $route['id']));
        self::assertSame('0', (new RouteApprovals($this->pdo))->values(self::TENANT, (int) $route['id'], 'Approved')['route.approvals.count']);
    }

    /**
     * @param array<string, mixed> $route
     * @return array{event: array<string, mixed>, resolved: int, delivered: int, decided: ?string}
     */
    private function approve(int $profileId, array $route): array
    {
        return $this->router->act(self::TENANT, $profileId, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
    }

    private function seedDocument(?int $createdBy): int
    {
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Application') . ', ' . $this->pdo->quote('Ethics application')
             . ', 20, ' . ($createdBy === null ? 'NULL' : (string) $createdBy) . ', ' . $this->now() . ')'
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (1, ' . $quote('Example Bakery') . ') ON CONFLICT DO NOTHING');
        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (20, 1, NULL, ' . $quote('Kitchen') . ', ' . $quote('kitchen') . ', ' . $now . ')'
        );
        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, ' . $quote('member') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );
        foreach ([[10, 'author'], [11, 'clerk'], [12, 'member a'], [13, 'member b']] as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, ' . $now . ', ' . $now . ')'
            );
            $pdo->exec(
                "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                    (" . (1000 + $id) . ", {$id}, 1, 100, 20, true, 'active', {$now})"
            );
        }

        return $pdo;
    }
}
