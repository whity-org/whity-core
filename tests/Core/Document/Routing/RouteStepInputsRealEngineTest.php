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
use Whity\Core\Document\Routing\RouteStepValueRepository;
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
 * WHIT-1016: values a person enters at a route step before letting it
 * through (a committee meeting number), kept on the route.
 *
 * Runs on SQLite, and on real PostgreSQL when PHPUNIT_PG_DSN is set
 * ({@see SchemaFromMigrations::make()}).
 */
final class RouteStepInputsRealEngineTest extends TestCase
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

    private const MEETING = ['key' => 'meeting_no', 'label' => 'Committee meeting number', 'label_ar' => 'رقم جلسة اللجنة'];

    public function testApprovingNeedsTheRequiredValueAndKeepsItOnTheRoute(): void
    {
        $route = $this->issueMemberThenSecretary($this->seedDocument(self::AUTHOR), [self::MEETING]);
        $this->approve(self::MEMBER_A, $route);

        $step = $this->steps->listForRoute((int) $route['id'], self::TENANT)[1];
        self::assertSame('meeting_no', $step['inputs'][0]['key']);
        self::assertTrue($step['inputs'][0]['required']);
        self::assertSame(100, $step['inputs'][0]['max_length']);
        self::assertSame('رقم جلسة اللجنة', RoutingPresenter::step($step)['inputs'][0]['label_ar']);
        self::assertSame([], RoutingPresenter::step($this->steps->listForRoute((int) $route['id'], self::TENANT)[0])['inputs']);

        try {
            $this->approve(self::CLERK, $route);
            self::fail('Approving without the meeting number must be refused.');
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('Committee meeting number', $e->clientMessage);
        }
        self::assertNotNull($this->open(self::CLERK), 'A refused approval changes nothing.');
        self::assertSame([], $this->router->enteredValues(self::TENANT, (int) $route['id']));

        $acted = $this->approve(self::CLERK, $route, ['meeting_no' => '  14  ']);
        self::assertSame('approved', $acted['decided']);

        $values = $this->router->enteredValues(self::TENANT, (int) $route['id']);
        self::assertSame('14', $values['meeting_no']['value'], 'Values are trimmed.');
        self::assertSame(self::CLERK, $values['meeting_no']['entered_by']);
        self::assertSame((int) $acted['event']['id'], $values['meeting_no']['event_id']);
        self::assertSame((int) $step['id'], $values['meeting_no']['step_id']);
        self::assertSame(
            [['key' => 'meeting_no', 'value' => '14']],
            array_map(
                static fn (array $v): array => ['key' => $v['key'], 'value' => $v['value']],
                RoutingPresenter::values($values),
            ),
        );
    }

    public function testValuesGoOnlyWithLettingItThrough(): void
    {
        $route = $this->issueMemberThenSecretary($this->seedDocument(self::AUTHOR), [self::MEETING]);
        $this->approve(self::MEMBER_A, $route);

        $carrying = [
            [RouteAction::ACKNOWLEDGED, 'No.', RouteVerdict::REJECTED],
            [RouteAction::SENT_BACK, 'Fix it.', null],
            [RouteAction::NOTED, 'A remark.', null],
            [RouteAction::RETURNED, 'Back to you.', null],
        ];
        foreach ($carrying as [$action, $note, $verdict]) {
            try {
                $this->router->act(self::TENANT, self::CLERK, $route, $action, $note, $verdict, null, false, ['meeting_no' => '14']);
                self::fail("'{$action}' must not carry values.");
            } catch (RoutingRejectedException) {
            }
        }

        // A rejection needs none of the step's values.
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, 'Not yet.', RouteVerdict::REJECTED);
        self::assertSame([], $this->router->enteredValues(self::TENANT, (int) $route['id']));
    }

    public function testAKeyTheStepDoesNotAskForAndATooLongValueAreRefused(): void
    {
        $route = $this->issueMemberThenSecretary($this->seedDocument(self::AUTHOR), [self::MEETING]);
        $this->approve(self::MEMBER_A, $route);

        foreach ([
            ['meeting_no' => '14', 'other' => 'x'],
            ['meeting_no' => str_repeat('9', 101)],
        ] as $values) {
            try {
                $this->approve(self::CLERK, $route, $values);
                self::fail('These values must be refused.');
            } catch (RoutingRejectedException) {
            }
        }
        self::assertNotNull($this->open(self::CLERK));
    }

    public function testAStepThatAsksForNothingRefusesValues(): void
    {
        $route = $this->issueMemberThenSecretary($this->seedDocument(self::AUTHOR), []);

        $this->expectException(RoutingRejectedException::class);
        $this->approve(self::MEMBER_A, $route, ['meeting_no' => '14']);
    }

    public function testAnOptionalValueMayBeLeftOutAndALaterEntryReplacesTheOld(): void
    {
        $route = $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $this->seedDocument(self::AUTHOR)], 'Ethics application', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::CLERK]],
                'decision' => true,
                'inputs' => [self::MEETING, ['key' => 'file_no', 'label' => 'File number', 'required' => false, 'max_length' => 20]],
            ],
        ])['route'];

        $this->approve(self::CLERK, $route, ['meeting_no' => '13', 'file_no' => '']);
        $values = $this->router->enteredValues(self::TENANT, (int) $route['id']);
        self::assertSame(['meeting_no'], array_keys($values), 'A blank optional value is not kept.');

        // The same key entered again reads as the newer value, and the older
        // row stays: the table is insert-only.
        $repo = new RouteStepValueRepository($this->pdo);
        $repo->record(self::TENANT, (int) $route['id'], $values['meeting_no']['step_id'], $values['meeting_no']['event_id'], self::CLERK, ['meeting_no' => '15']);
        self::assertSame(['meeting_no' => '15'], $repo->valuesForRoute(self::TENANT, (int) $route['id']));
        self::assertCount(2, $this->rows('SELECT id FROM route_step_values WHERE route_id = ' . (int) $route['id']));
        self::assertSame([], $repo->valuesForRoute(2, (int) $route['id']), 'Values belong to their workspace.');
    }

    public function testAStageThatOnlyTellsPeopleCannotAskForValues(): void
    {
        $this->expectException(RoutingRejectedException::class);
        $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $this->seedDocument(self::AUTHOR)], 'Ethics application', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::CLERK]],
                'satisfied_by' => 'delivery',
                'inputs' => [self::MEETING],
            ],
        ]);
    }

    public function testABadDeclarationIsRefusedWithTheStepNamedAndNothingWritten(): void
    {
        try {
            $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $this->seedDocument(self::AUTHOR)], 'Ethics application', [
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                    'rule_config' => ['profile_ids' => [self::CLERK]],
                    'inputs' => [['key' => 'Meeting No', 'label' => 'Meeting']],
                ],
            ]);
            self::fail('A key with spaces and capitals must be refused.');
        } catch (RoutingRejectedException $e) {
            self::assertStringStartsWith('Step 1:', $e->clientMessage);
        }
        self::assertSame([], $this->rows('SELECT id FROM document_routes'));
    }

    /**
     * Step 1: a committee member approves. Step 2: the secretary approves,
     * asked for `$secretaryInputs`.
     *
     * @param list<array<string, mixed>> $secretaryInputs
     * @return array<string, mixed>
     */
    private function issueMemberThenSecretary(int $documentId, array $secretaryInputs): array
    {
        return $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $documentId], 'Ethics application', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::MEMBER_A]],
                'decision' => true,
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::CLERK]],
                'decision' => true,
                'inputs' => $secretaryInputs,
            ],
        ])['route'];
    }

    /**
     * @param array<string, mixed>  $route
     * @param array<string, string> $values
     * @return array{event: array<string, mixed>, resolved: int, delivered: int, decided: ?string}
     */
    private function approve(int $profileId, array $route, array $values = []): array
    {
        return $this->router->act(self::TENANT, $profileId, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED, null, false, $values);
    }

    /** @return array<string, mixed>|null */
    private function open(int $profileId): ?array
    {
        $routeId = $this->column('SELECT id FROM document_routes ORDER BY id DESC LIMIT 1')[0] ?? 0;

        return $this->recipients->findOpenForProfile(self::TENANT, (int) $routeId, $profileId);
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        return array_map(static fn (array $r): mixed => array_values($r)[0], $this->rows($sql));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
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
