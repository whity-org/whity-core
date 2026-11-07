<?php

declare(strict_types=1);

namespace Tests\Core\Form;

use Database\Migrations\AddFormSubmissionRouteSubjects;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\RoutesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\DocumentRoutingInboxSource;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteResume;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteSubjectChanges;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteSubjectVersionRepository;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormSubmissionRouteSubject;
use Whity\Core\Form\FormUploadRepository;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Form\SubmissionProgress;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * WHIT-920 (#1724): form submissions as route subjects, with edit and resubmit.
 *
 * Runs on SQLite, and on real PostgreSQL when PHPUNIT_PG_DSN is set
 * ({@see SchemaFromMigrations::make()}), so migration 233's nullable document
 * columns and its re-pointing are exercised on both engines.
 */
final class FormSubmissionRoutesRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const APPLICANT = 10;
    private const CLERK = 11;
    private const MEMBER_A = 12;
    private const MEMBER_B = 13;
    private const OUTSIDER = 14;

    private PDO $pdo;
    private DocumentRouter $router;
    private RouteRepository $routes;
    private RouteRecipientRepository $recipients;
    private RouteEventRepository $events;
    private RouteSubjectVersionRepository $versions;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormSubmissionRepository $submissions;
    private SubmissionIssuer $issuer;
    private SubmissionProgress $progress;
    private SettingsService $settings;
    private RouteSubjectRegistry $subjects;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->routes = new RouteRepository($this->pdo);
        $this->events = new RouteEventRepository($this->pdo);
        $this->recipients = new RouteRecipientRepository($this->pdo);
        $this->versions = new RouteSubjectVersionRepository($this->pdo);
        $steps = new RouteStepRepository($this->pdo);

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

        $this->subjects = new RouteSubjectRegistry();
        $this->subjects->register(new DocumentRouteSubject($this->pdo));
        $this->subjects->register(new FormSubmissionRouteSubject($this->pdo));

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $this->router = new DocumentRouter(
            $this->pdo,
            $this->routes,
            $steps,
            $this->events,
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $rules,
            $this->settings,
            null,
            $this->subjects,
            $this->versions,
        );

        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->submissions = new FormSubmissionRepository($this->pdo);
        $this->issuer = new SubmissionIssuer(
            $this->pdo,
            $this->submissions,
            new RouteTemplateRepository($this->pdo),
            $this->router,
            new FormUploadRepository($this->pdo),
            $this->routes,
            $this->recipients,
        );
        $this->progress = new SubmissionProgress(
            $this->pdo,
            $this->routes,
            $steps,
            $this->events,
            $this->recipients,
            $this->settings,
        );

        $this->formId = $this->publishedForm($this->clerkThenCommittee());
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testASubmissionTravelsOnItselfAndRaisesNoDocument(): void
    {
        $submission = $this->send(self::APPLICANT, ['supplier' => 'Example Mill', 'amount' => '100']);

        self::assertNull($submission['document_id']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM documents'));
        self::assertSame('in progress', $submission['state']);
        self::assertSame('Clerk check', $submission['current_step']);

        $route = $this->routeOf((int) $submission['id']);
        self::assertSame(FormSubmissionRouteSubject::TYPE, $route['subject_type']);
        self::assertSame((int) $submission['id'], $route['subject_id']);
        self::assertNull($route['document_id']);
        self::assertSame('Supplier request', $route['title']);

        // The issue is the subject's first version: the answers as sent.
        $latest = $this->versions->latest(self::TENANT, FormSubmissionRouteSubject::TYPE, (int) $submission['id']);
        self::assertNotNull($latest);
        self::assertSame(1, $latest['version']);

        // The clerk's inbox item names the form and points at the submission.
        $items = $this->recipients->listForProfile(self::TENANT, self::CLERK, true, 10, 0);
        self::assertCount(1, $items);
        self::assertNull($items[0]['document_id']);
        self::assertSame('Supplier request', $items[0]['document_title']);
        self::assertSame(FormSubmissionRouteSubject::TYPE, $items[0]['subject_type']);
        self::assertSame((int) $submission['id'], $items[0]['subject_id']);
        self::assertSame(1, $this->recipients->countForProfile(self::TENANT, self::CLERK, true));
    }

    public function testOnlyTheRouteASubmissionStartedIsRepointedAndItsVersionsFollowIt(): void
    {
        [$documentId, $submissionId, $routeId] = $this->legacySubmission();

        // Somebody later routed the same document from the library.
        $library = $this->router->issue(self::TENANT, self::CLERK, ['id' => $documentId], 'Second look', [
            ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::MEMBER_A]]],
        ])['route'];
        // A route on a document nobody submitted stays on its document.
        $plainDocument = $this->seedDocument();
        $this->router->issue(self::TENANT, self::APPLICANT, ['id' => $plainDocument], 'Letter', [
            ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::CLERK]]],
        ]);
        self::assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM route_subject_versions WHERE subject_type = 'document' AND subject_id = " . $documentId
        ));

        $db = $this->database();
        AddFormSubmissionRouteSubjects::up($db);
        AddFormSubmissionRouteSubjects::up($db); // idempotent

        $route = $this->routes->findById($routeId, self::TENANT);
        self::assertNotNull($route);
        self::assertSame(FormSubmissionRouteSubject::TYPE, $route['subject_type']);
        self::assertSame($submissionId, $route['subject_id']);
        self::assertSame($documentId, $route['document_id'], 'the document stays on the route');
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM documents WHERE id = ' . $documentId));

        $later = $this->routes->findById((int) $library['id'], self::TENANT);
        self::assertNotNull($later);
        self::assertSame(DocumentRouteSubject::TYPE, $later['subject_type'], 'a library route stays on the document');
        self::assertCount(1, $this->routes->listForSubject(DocumentRouteSubject::TYPE, $plainDocument, self::TENANT));

        // The document's versions moved with the re-pointed route.
        $versions = $this->versions->listForSubject(self::TENANT, FormSubmissionRouteSubject::TYPE, $submissionId);
        self::assertCount(1, $versions);
        self::assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM route_subject_versions WHERE subject_type = 'document' AND subject_id = " . $documentId
        ));

        // Both routes still answer through the document's routes.
        self::assertCount(2, $this->routes->listForDocument($documentId, self::TENANT));
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null);
        $found = $this->submissions->find(self::TENANT, $submissionId);
        self::assertNotNull($found);
        self::assertSame('in progress', $found['state'], 'the library route is still open on its document');
    }

    public function testARepointedRouteThatStillHasItsDocumentBehavesAsBefore(): void
    {
        [$documentId, $submissionId, $routeId] = $this->legacySubmission(decision: true);
        AddFormSubmissionRouteSubjects::up($this->database());
        $route = $this->routes->findById($routeId, self::TENANT);
        self::assertNotNull($route);
        self::assertSame(FormSubmissionRouteSubject::TYPE, $route['subject_type']);

        // Sent back to the document's author, as a document route always was,
        // and not editable as a submission: an issued document is not edited.
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::SENT_BACK, 'Please check.', null, RouteResume::SENDER);
        $item = $this->open($route, self::APPLICANT);
        self::assertNotNull($item);
        $found = $this->submissions->find(self::TENANT, $submissionId, self::APPLICANT);
        self::assertNotNull($found);
        self::assertFalse($this->progress->forSubmission(self::TENANT, $found, self::APPLICANT)['can_resubmit']);
        try {
            $this->issuer->resubmit(self::TENANT, self::APPLICANT, $found, $this->applicantFields(), ['supplier' => 'Changed'], null);
            self::fail('A submission whose route still has its document is not edited.');
        } catch (FormRejectedException $e) {
            self::assertSame('has_document', $e->reason);
        }
        self::assertSame('Example Mill', $this->submissions->find(self::TENANT, $submissionId)['data']['supplier'] ?? null);

        // The author resubmits it as it is, as on any document route.
        $this->router->act(self::TENANT, self::APPLICANT, $route, RouteAction::RESUBMITTED, null);

        // A rejection needs no reason here, is not marked for the author, and
        // does not end the route by itself.
        $rejected = $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::REJECTED);
        self::assertFalse((bool) $rejected['event']['to_author']);
        self::assertSame($documentId, $rejected['event']['document_id']);
    }

    /**
     * A submission sent before migration 233: it raised a document, and its
     * route travels on that document, started in the same transaction (so with
     * the document's own timestamp).
     *
     * @return array{0: int, 1: int, 2: int} document, submission, route
     */
    private function legacySubmission(bool $decision = false): array
    {
        $documentId = $this->seedDocument();
        $submissionId = $this->submissions->create(
            self::TENANT,
            $this->formId,
            1,
            self::APPLICANT,
            $documentId,
            ['supplier' => 'Example Mill'],
        );
        $issued = $this->router->issue(self::TENANT, self::APPLICANT, ['id' => $documentId], 'Supplier request', [
            ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::CLERK]], 'decision' => $decision],
        ])['route'];
        self::assertSame(DocumentRouteSubject::TYPE, $issued['subject_type']);
        $this->pdo->exec(
            'UPDATE document_routes SET created_at = (SELECT created_at FROM documents WHERE id = ' . $documentId
            . ') WHERE id = ' . (int) $issued['id']
        );

        return [$documentId, $submissionId, (int) $issued['id']];
    }

    public function testTheApplicantsInboxDoesNotNameTheReviewerWhoSentItBack(): void
    {
        $submission = $this->send(self::APPLICANT, ['supplier' => 'Example Mill', 'amount' => '100']);
        $route = $this->routeOf((int) $submission['id']);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::SENT_BACK, 'Please add the amount with tax.', null, RouteResume::SENDER);

        $inbox = new DocumentRoutingInboxSource($this->recipients, $this->settings);
        $items = $inbox->list(self::TENANT, self::APPLICANT, true, 10, 0);
        self::assertCount(1, $items);
        $wire = $items[0]->toArray();
        self::assertNull($wire['sender']);
        self::assertNull($wire['meta']['arrived_from_profile_id']);
        self::assertSame('author', $wire['meta']['role']);
        self::assertStringNotContainsString('Rana', (string) json_encode($wire));

        // With the workspace showing reviewer names: the name, never the e-mail.
        $this->settings->setTenant(self::TENANT, SettingsRegistry::FORMS_SHOW_REVIEWER_NAMES, 'true');
        $named = $inbox->list(self::TENANT, self::APPLICANT, true, 10, 0)[0]->toArray();
        self::assertSame('Clerk Rana', $named['sender']['name']);
        self::assertStringNotContainsString('@', (string) json_encode($named), 'never an e-mail address');
    }

    public function testSomebodyWhoOnlyReadsSubmissionsCannotWriteToTheSender(): void
    {
        $submission = $this->send(self::APPLICANT, ['supplier' => 'Example Mill', 'amount' => '100']);
        $routeId = (int) $this->routeOf((int) $submission['id'])['id'];
        $api = $this->routesApi(readsForms: true);

        $toSender = $api->act(
            $this->request('POST', '/x', self::OUTSIDER, ['action' => 'noted', 'note' => 'Hello', 'to_author' => true]),
            ['routeId' => (string) $routeId],
        );
        self::assertSame(422, $toSender->getStatusCode(), (string) $toSender->getBody());

        $internal = $api->act(
            $this->request('POST', '/x', self::OUTSIDER, ['action' => 'noted', 'note' => 'Checked the price list.']),
            ['routeId' => (string) $routeId],
        );
        self::assertSame(201, $internal->getStatusCode(), (string) $internal->getBody());

        // The clerk the route reached may write to the sender.
        $fromClerk = $api->act(
            $this->request('POST', '/x', self::CLERK, ['action' => 'noted', 'note' => 'We are on it.', 'to_author' => true]),
            ['routeId' => (string) $routeId],
        );
        self::assertSame(201, $fromClerk->getStatusCode(), (string) $fromClerk->getBody());
    }

    public function testTheAuthorEditsASentBackSubmissionAndReviewersSeeWhatChanged(): void
    {
        $submission = $this->send(self::APPLICANT, ['supplier' => 'Example Mill', 'amount' => '100']);
        $id = (int) $submission['id'];
        $route = $this->routeOf($id);

        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        // Not sent back yet: nothing to edit.
        try {
            $this->resubmit($id, ['supplier' => 'Example Mill', 'amount' => '150']);
            self::fail('A submission under review must not be edited.');
        } catch (FormRejectedException $e) {
            self::assertSame('not_sent_back', $e->reason);
        }

        $this->router->act(
            self::TENANT,
            self::MEMBER_A,
            $route,
            RouteAction::SENT_BACK,
            'Please give the amount with tax.',
            null,
            RouteResume::SENDER,
        );

        $sentBack = $this->submissions->find(self::TENANT, $id);
        self::assertNotNull($sentBack);
        self::assertSame('sent back', $sentBack['state']);

        $progress = $this->progress->forSubmission(self::TENANT, $sentBack, self::APPLICANT);
        self::assertTrue($progress['can_resubmit']);
        self::assertSame('sender', $progress['resume_from']);
        self::assertSame(['done', 'sent_back'], array_column($progress['stages'], 'status'));
        self::assertSame('sent_back', $progress['notes'][0]['kind']);
        self::assertSame('Please give the amount with tax.', $progress['notes'][0]['note']);
        self::assertFalse($this->progress->forSubmission(self::TENANT, $sentBack, self::OUTSIDER)['can_resubmit']);

        // Somebody else cannot resubmit it.
        try {
            $this->issuer->resubmit(self::TENANT, self::OUTSIDER, $sentBack, $this->applicantFields(), ['supplier' => 'X'], null);
            self::fail('Only the author resubmits.');
        } catch (FormRejectedException $e) {
            self::assertSame('not_sent_back', $e->reason);
        }

        // Answers are checked as a new submission's are.
        try {
            $this->resubmit($id, ['amount' => '150']);
            self::fail('A required answer is still required.');
        } catch (FormRejectedException) {
        }

        $result = $this->resubmit($id, ['supplier' => 'Example Mill', 'amount' => '150'], 'Amount now includes tax.');
        self::assertSame('150', $result['submission']['data']['amount']);
        self::assertSame('in progress', $result['submission']['state']);

        $latest = $this->versions->latest(self::TENANT, FormSubmissionRouteSubject::TYPE, $id);
        self::assertNotNull($latest);
        self::assertSame(2, $latest['version'], 'resubmitting records a new version');

        // Resumed at the committee, resolved again.
        self::assertNotNull($this->open($route, self::MEMBER_A));
        self::assertNotNull($this->open($route, self::MEMBER_B));
        self::assertNull($this->open($route, self::APPLICANT));

        $changes = new RouteSubjectChanges($this->versions, $this->events);
        $forA = $changes->since(self::TENANT, $route, self::MEMBER_A);
        self::assertSame(1, $forA['seen_version']);
        self::assertSame(2, $forA['current_version']);
        self::assertSame([['key' => 'amount', 'before' => '100', 'after' => '150']], $forA['changes']);

        // A reviewer who never acted has seen no earlier version.
        $forB = $changes->since(self::TENANT, $route, self::MEMBER_B);
        self::assertNull($forB['seen_version']);
        self::assertSame([], $forB['changes']);
    }

    public function testAnAnonymousSubmissionCannotBeSentBack(): void
    {
        $submission = $this->send(null, ['supplier' => 'Example Mill']);
        $route = $this->routeOf((int) $submission['id']);

        self::assertNull((new FormSubmissionRouteSubject($this->pdo))->author(self::TENANT, (int) $submission['id']));

        $this->expectException(RoutingRejectedException::class);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::SENT_BACK, 'Please fix.', null, RouteResume::START);
    }

    public function testARejectionEndsTheRouteAndTellsTheApplicantWhy(): void
    {
        $submission = $this->send(self::APPLICANT, ['supplier' => 'Example Mill', 'amount' => '100']);
        $id = (int) $submission['id'];
        $route = $this->routeOf($id);

        try {
            $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::REJECTED);
            self::fail('A rejection of a submission needs its reason.');
        } catch (RoutingRejectedException) {
        }

        $this->router->act(
            self::TENANT,
            self::CLERK,
            $route,
            RouteAction::ACKNOWLEDGED,
            'We do not buy from this supplier.',
            RouteVerdict::REJECTED,
        );

        self::assertFalse($this->recipients->hasOpenOnRoute(self::TENANT, (int) $route['id']));
        $found = $this->submissions->find(self::TENANT, $id);
        self::assertNotNull($found);
        self::assertSame('not approved', $found['state']);

        $progress = $this->progress->forSubmission(self::TENANT, $found, self::APPLICANT);
        self::assertSame(['not_approved', 'waiting'], array_column($progress['stages'], 'status'));
        self::assertSame('rejected', $progress['stages'][0]['outcome']);
        self::assertSame('not_approved', $progress['notes'][0]['kind']);
        self::assertSame('We do not buy from this supplier.', $progress['notes'][0]['note']);
        self::assertFalse($progress['can_resubmit']);
    }

    public function testTheApplicantSeesStagesAndNotesForThemButNotReviewersUnlessTheWorkspaceSaysSo(): void
    {
        $submission = $this->send(self::APPLICANT, ['supplier' => 'Example Mill', 'amount' => '100']);
        $route = $this->routeOf((int) $submission['id']);

        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::NOTED, 'Internal: check the price list.');
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::NOTED, 'We are checking your request.', null, null, true);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        $found = $this->submissions->find(self::TENANT, (int) $submission['id']);
        self::assertNotNull($found);
        $progress = $this->progress->forSubmission(self::TENANT, $found, self::APPLICANT);

        self::assertFalse($progress['shows_reviewers']);
        self::assertSame(['Clerk check', 'Committee'], array_column($progress['stages'], 'label'));
        self::assertSame(['done', 'current'], array_column($progress['stages'], 'status'));
        self::assertSame('approved', $progress['stages'][0]['outcome']);
        self::assertArrayNotHasKey('reviewers', $progress['stages'][0]);
        self::assertCount(1, $progress['notes'], 'internal notes never reach the applicant');
        self::assertSame('We are checking your request.', $progress['notes'][0]['note']);
        self::assertArrayNotHasKey('from', $progress['notes'][0]);
        self::assertStringNotContainsString('Rana', (string) json_encode($progress), 'no reviewer is named');

        $this->settings->setTenant(self::TENANT, SettingsRegistry::FORMS_SHOW_REVIEWER_NAMES, 'true');
        $named = $this->progress->forSubmission(self::TENANT, $found, self::APPLICANT);
        self::assertTrue($named['shows_reviewers']);
        self::assertSame(['Clerk Rana'], $named['stages'][0]['reviewers']);
        self::assertSame('Clerk Rana', $named['notes'][0]['from']);
    }

    public function testReviewersReadAndActThroughTheSubjectRoutesAndTheAuthorDoesNot(): void
    {
        $submission = $this->send(self::APPLICANT, ['supplier' => 'Example Mill', 'amount' => '100']);
        $id = (int) $submission['id'];
        $api = $this->routesApi();

        $read = $api->bySubject($this->request('GET', '/api/v1/routes?subject_type=form_submission&subject_id=' . $id, self::CLERK));
        self::assertSame(200, $read->getStatusCode(), (string) $read->getBody());
        $data = json_decode((string) $read->getBody(), true)['data'];
        self::assertSame('Supplier request', $data['subject']['title']);
        self::assertNull($data['subject']['document_id']);
        self::assertTrue($data['subject']['can_send_back']);
        self::assertSame(1, $data['subject']['version']);
        self::assertCount(1, $data['routes']);
        self::assertSame('issued', $data['routes'][0]['trail'][0]['action']);

        // The author reads their own through My submissions, not here.
        foreach ([self::APPLICANT, self::OUTSIDER] as $profileId) {
            $refused = $api->bySubject($this->request('GET', '/api/v1/routes?subject_type=form_submission&subject_id=' . $id, $profileId));
            self::assertSame(404, $refused->getStatusCode());
        }

        $routeId = (int) $data['routes'][0]['id'];
        $acted = $api->act(
            $this->request('POST', '/api/v1/routes/' . $routeId . '/actions', self::CLERK, ['action' => 'acknowledged', 'verdict' => 'approved']),
            ['routeId' => (string) $routeId],
        );
        self::assertSame(201, $acted->getStatusCode(), (string) $acted->getBody());
        self::assertSame('approved', json_decode((string) $acted->getBody(), true)['decided']);
    }

    // -- helpers --------------------------------------------------------------

    /**
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private function send(?int $profileId, array $answers): array
    {
        $form = $this->forms->find(self::TENANT, $this->formId);
        self::assertNotNull($form);

        return $this->issuer->submit(self::TENANT, $profileId, $form, $this->applicantFields(), $answers)['submission'];
    }

    /**
     * @param array<string, mixed> $answers
     * @return array{submission: array<string, mixed>, ignored: list<string>}
     */
    private function resubmit(int $id, array $answers, ?string $note = null): array
    {
        $submission = $this->submissions->find(self::TENANT, $id, self::APPLICANT);
        self::assertNotNull($submission);

        return $this->issuer->resubmit(self::TENANT, self::APPLICANT, $submission, $this->applicantFields(), $answers, $note);
    }

    /** @return list<array<string, mixed>> */
    private function applicantFields(): array
    {
        return FormFieldRepository::applicantFields($this->fields->listForForm(self::TENANT, $this->formId));
    }

    /** @return array<string, mixed> */
    private function routeOf(int $submissionId): array
    {
        $routes = $this->routes->listForSubject(FormSubmissionRouteSubject::TYPE, $submissionId, self::TENANT);
        self::assertCount(1, $routes);

        return $routes[0];
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>|null
     */
    private function open(array $route, int $profileId): ?array
    {
        return $this->recipients->findOpenForProfile(self::TENANT, (int) $route['id'], $profileId);
    }

    private function publishedForm(int $routeTemplateId): int
    {
        $id = $this->forms->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request'], null, $routeTemplateId, null);
        $this->fields->create(self::TENANT, $id, 'supplier', 'text', ['en' => 'Supplier'], null, true, [], [], null, null, null);
        $this->fields->create(self::TENANT, $id, 'amount', 'text', ['en' => 'Amount'], null, false, [], [], null, null, null);
        $this->forms->transition(self::TENANT, $id, 'draft', 'published');

        return $id;
    }

    /** Stage 1: one clerk approves. Stage 2: a two-member committee decides (all). */
    private function clerkThenCommittee(): int
    {
        $templates = new RouteTemplateRepository($this->pdo);
        $id = $templates->create(self::TENANT, 'Supplier approval', null, null);
        $step = static fn (int $position, string $label, array $people, ?string $quorum): array => [
            'position' => $position,
            'rule_kind' => 'explicit',
            'rule_config' => ['profile_ids' => $people],
            'label' => $label,
            'decision' => true,
            'decision_quorum' => $quorum,
            'satisfied_by' => 'act',
            'canvas_x' => 0,
            'canvas_y' => 0,
        ];
        $templates->replaceGraph($id, self::TENANT, [
            $step(1, 'Clerk check', [self::CLERK], null),
            $step(2, 'Committee', [self::MEMBER_A, self::MEMBER_B], 'all'),
        ], []);

        return $id;
    }

    private function routesApi(bool $readsForms = false): RoutesApiHandler
    {
        return new RoutesApiHandler(
            $this->pdo,
            $this->routes,
            new RouteStepRepository($this->pdo),
            $this->events,
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $this->router,
            $this->subjects,
            $this->versions,
            new DocumentRepository($this->pdo),
            // Only document subjects ask it, and these are submissions.
            (new \ReflectionClass(DocumentVisibilityPolicy::class))->newInstanceWithoutConstructor(),
            $this->noPermissions($readsForms),
        );
    }

    /** A role checker that grants nothing, so only being reached opens a subject. */
    private function noPermissions(bool $readsForms = false): RoleChecker
    {
        $mock = $this->createMock(RoleChecker::class);
        $mock->method('hasPermissionForProfile')->willReturn($readsForms);

        return $mock;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, int $profileId, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function database(): Database
    {
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function seedDocument(): int
    {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Supplier request') . ', ' . $this->pdo->quote('Supplier request')
             . ', 20, ' . self::APPLICANT . ', ' . $now . ')'
        );

        return (int) $this->pdo->lastInsertId();
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
        $people = [
            [self::APPLICANT, 'Applicant Huda'],
            [self::CLERK, 'Clerk Rana'],
            [self::MEMBER_A, 'Member Omar'],
            [self::MEMBER_B, 'Member Dana'],
            [self::OUTSIDER, 'Someone Else'],
        ];
        foreach ($people as [$id, $name]) {
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
