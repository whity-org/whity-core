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
 * WHIT-919 (#1723): send back to the author for changes, resubmit, and the
 * versions of what was reviewed.
 *
 * Runs on SQLite, and on real PostgreSQL when PHPUNIT_PG_DSN is set
 * ({@see SchemaFromMigrations::make()}), so the widened action CHECK is exercised
 * on both engines.
 */
final class RouteSendBackRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const AUTHOR = 10;
    private const CLERK = 11;
    private const MEMBER_A = 12;
    private const MEMBER_B = 13;

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

    public function testACommitteeMemberSendsItBackAtOnceAndTheAuthorResubmitsToThatStep(): void
    {
        $documentId = $this->seedDocument(self::AUTHOR);
        $route = $this->issueClerkThenCommittee($documentId);

        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        self::assertNotNull($this->open(self::MEMBER_A));
        self::assertNotNull($this->open(self::MEMBER_B));

        $sent = $this->router->act(
            self::TENANT,
            self::MEMBER_A,
            $route,
            RouteAction::SENT_BACK,
            'Please attach the consent form.',
            null,
            RouteResume::SENDER,
        );

        $event = RoutingPresenter::event($sent['event']);
        self::assertSame('sent_back', $event['action']);
        self::assertTrue($event['to_author']);
        self::assertSame('sender', $event['resume_from']);
        self::assertSame(1, $event['subject_version']);

        // One member's send-back goes at once: the other member's item closed
        // without a vote, and nobody but the author holds anything.
        self::assertNull($this->open(self::MEMBER_B));
        self::assertNull($this->open(self::MEMBER_A));
        $authorItem = $this->open(self::AUTHOR);
        self::assertNotNull($authorItem);
        self::assertSame(RouteRecipientRepository::ROLE_AUTHOR, $authorItem['role']);
        self::assertSame('author', RoutingPresenter::recipient($authorItem)['role']);

        // The author cannot answer the step; only resubmit.
        try {
            $this->router->act(self::TENANT, self::AUTHOR, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
            self::fail('The author item must not answer the step.');
        } catch (RoutingRejectedException) {
        }

        $resubmitted = $this->router->act(self::TENANT, self::AUTHOR, $route, RouteAction::RESUBMITTED, 'Attached.');
        self::assertSame('resubmitted', $resubmitted['event']['action']);
        self::assertSame(2, $resubmitted['delivered'], 'The committee step is resolved again.');

        // Back to the committee, not to the clerk, whose approval stands.
        self::assertNotNull($this->open(self::MEMBER_A));
        self::assertNotNull($this->open(self::MEMBER_B));
        self::assertNull($this->open(self::CLERK));
        self::assertNull($this->open(self::AUTHOR));

        $verdicts = $this->column(
            'SELECT verdict FROM document_route_events WHERE route_id = ' . (int) $route['id'] . ' AND verdict IS NOT NULL'
        );
        self::assertSame(['approved'], $verdicts, 'The clerk\'s approval stays in the trail.');
    }

    public function testStartOverResumesAtStepOne(): void
    {
        $route = $this->issueClerkThenCommittee($this->seedDocument(self::AUTHOR));
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        $this->router->act(self::TENANT, self::MEMBER_B, $route, RouteAction::SENT_BACK, 'Start again.', null, RouteResume::START);
        $this->router->act(self::TENANT, self::AUTHOR, $route, RouteAction::RESUBMITTED, null);

        self::assertNotNull($this->open(self::CLERK));
        self::assertNull($this->open(self::MEMBER_A));
        self::assertNull($this->open(self::MEMBER_B));
    }

    public function testEachSubmitIsAVersionAndTheTrailNamesTheVersionOfEachAct(): void
    {
        $documentId = $this->seedDocument(self::AUTHOR);
        $route = $this->issueClerkThenCommittee($documentId);

        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        $this->router->act(self::TENANT, self::MEMBER_A, $route, RouteAction::SENT_BACK, 'Fix the title.', null, null);

        // Resubmitting unchanged content keeps the version.
        $this->router->act(self::TENANT, self::AUTHOR, $route, RouteAction::RESUBMITTED, null);
        self::assertSame(1, $this->versions->latest(self::TENANT, 'document', $documentId)['version'] ?? null);

        $this->router->act(self::TENANT, self::MEMBER_A, $route, RouteAction::SENT_BACK, 'Still the title.', null, null);
        $this->pdo->exec("UPDATE documents SET title = 'Ethics application (revised)' WHERE id = " . $documentId);
        $this->router->act(self::TENANT, self::AUTHOR, $route, RouteAction::RESUBMITTED, null);

        $versions = $this->versions->listForSubject(self::TENANT, 'document', $documentId);
        self::assertSame([1, 2], array_column($versions, 'version'));
        self::assertSame(64, strlen($versions[1]['content_hash']));
        self::assertNotSame($versions[0]['content_hash'], $versions[1]['content_hash']);
        self::assertSame('Ethics application (revised)', $versions[1]['data']['title'] ?? null);

        $trail = $this->rows(
            'SELECT action, subject_version, content_hash FROM document_route_events
              WHERE route_id = ' . (int) $route['id'] . ' ORDER BY id'
        );
        self::assertSame(
            [
                ['issued', 1], ['acknowledged', 1], ['sent_back', 1], ['resubmitted', 1],
                ['sent_back', 1], ['resubmitted', 2],
            ],
            array_map(static fn (array $r): array => [(string) $r['action'], (int) $r['subject_version']], $trail)
        );
        self::assertSame($versions[1]['content_hash'], (string) $trail[5]['content_hash']);
    }

    public function testASendBackNeedsANoteForTheAuthor(): void
    {
        $route = $this->issueClerkThenCommittee($this->seedDocument(self::AUTHOR));

        $this->expectException(RoutingRejectedException::class);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::SENT_BACK, '  ', null, null);
    }

    public function testADesignCanTurnSendBackOffForAStep(): void
    {
        $documentId = $this->seedDocument(self::AUTHOR);
        $route = $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $documentId], 'Ethics application', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::CLERK]],
                'allow_send_back' => false,
            ],
        ])['route'];

        $step = $this->steps->listForRoute((int) $route['id'], self::TENANT)[0];
        self::assertFalse($step['allow_send_back']);
        self::assertFalse(RoutingPresenter::step($step)['allow_send_back']);

        $this->expectException(RoutingRejectedException::class);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::SENT_BACK, 'Fix it.', null, null);
    }

    public function testNothingIsSentBackWhenTheAuthorIsUnknown(): void
    {
        $route = $this->issueClerkThenCommittee($this->seedDocument(null));

        try {
            $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::SENT_BACK, 'Fix it.', null, null);
            self::fail('A send-back with no author to receive it must be refused.');
        } catch (RoutingRejectedException) {
        }

        self::assertNotNull($this->open(self::CLERK), 'A refused send-back changes nothing.');
    }

    public function testOnlyTheAuthorItemCanResubmitAndOnlyItemHoldersCanSendBack(): void
    {
        $route = $this->issueClerkThenCommittee($this->seedDocument(self::AUTHOR));

        try {
            $this->router->act(self::TENANT, self::MEMBER_A, $route, RouteAction::SENT_BACK, 'Not mine.', null, null);
            self::fail('Someone with no open item cannot send it back.');
        } catch (RoutingRejectedException) {
        }

        try {
            $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::RESUBMITTED, null);
            self::fail('A step item cannot be resubmitted.');
        } catch (RoutingRejectedException) {
        }

        try {
            $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::FORWARDED, null, null, RouteResume::START);
            self::fail('Only a send-back says where the route resumes.');
        } catch (RoutingRejectedException) {
        }

        self::assertNotNull($this->open(self::CLERK));
    }

    public function testNotesAreInternalUnlessMeantForTheAuthor(): void
    {
        $route = $this->issueClerkThenCommittee($this->seedDocument(self::AUTHOR));

        $internal = $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::NOTED, 'Checked the budget.');
        $toAuthor = $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::NOTED, 'Looks good so far.', null, null, true);

        self::assertFalse(RoutingPresenter::event($internal['event'])['to_author']);
        self::assertTrue(RoutingPresenter::event($toAuthor['event'])['to_author']);
        self::assertNotNull($this->open(self::CLERK), 'A note closes nothing.');
    }

    public function testTheTrailRefusesAVerbOutsideTheVocabulary(): void
    {
        $route = $this->issueClerkThenCommittee($this->seedDocument(self::AUTHOR));

        $this->expectException(\PDOException::class);
        $this->events->append(self::TENANT, (int) $route['document_id'], [
            'route_id' => (int) $route['id'],
            'action' => 'teleported',
        ]);
    }

    /**
     * Step 1: one clerk approves. Step 2: a two-member committee decides (all).
     *
     * @return array<string, mixed>
     */
    private function issueClerkThenCommittee(int $documentId): array
    {
        return $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $documentId], 'Ethics application', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::CLERK]],
                'decision' => true,
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::MEMBER_A, self::MEMBER_B]],
                'decision' => true,
                'decision_quorum' => 'all',
            ],
        ])['route'];
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
