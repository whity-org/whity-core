<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\EditorTemplateFixture;
use Tests\Support\FakeRenderServiceClient;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\IssueRequest;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateRejectedException;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\Jobs\FinaliseRouteJob;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteFinalDocumentFile;
use Whity\Core\Document\Routing\RouteFinalisationRepository;
use Whity\Core\Document\Routing\RouteFinaliser;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepKind;
use Whity\Core\Document\Routing\RouteStepRepository;
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
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Storage\LocalStorageDriver;

/**
 * WHIT-921 (#1725): a finalise step that produces the final PDF.
 *
 * Through the real engine, the real issuing service and a real storage driver
 * over a throwaway directory; only the render service is a double. Runs on
 * SQLite, and on PostgreSQL when PHPUNIT_PG_DSN is set.
 */
final class RouteFinaliseRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const APPLICANT = 10;
    private const CLERK = 11;

    private PDO $pdo;
    private SettingsService $settings;
    private FakeRenderServiceClient $render;
    private EditorTemplateFixture $editor;
    private DocumentRouter $router;
    private RouteRepository $routes;
    private RouteEventRepository $events;
    private RouteRecipientRepository $recipients;
    private RouteFinalisationRepository $finalisations;
    private RouteFinaliser $finaliser;
    private RouteTemplateRepository $routeTemplates;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormSubmissionRepository $submissions;
    private SubmissionIssuer $issuer;
    private SubmissionProgress $progress;
    private DocumentRepository $documents;
    private DocumentArtifactRepository $artifacts;
    private DocumentArtifactStore $store;
    private RoutingRuleRegistry $rules;
    private string $storageRoot;
    private int $letter;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->storageRoot = sys_get_temp_dir() . '/whity-finalise-' . bin2hex(random_bytes(6));

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');

        $this->render = new FakeRenderServiceClient();
        $this->documents = new DocumentRepository($this->pdo);
        $this->artifacts = new DocumentArtifactRepository($this->pdo);
        $this->store = new DocumentArtifactStore(new LocalStorageDriver($this->storageRoot));
        $this->editor = new EditorTemplateFixture(
            $this->pdo,
            $this->settings,
            new RoleChecker(Database::withFactory(fn (): PDO => $this->pdo), new PermissionRegistry()),
            $this->render,
            new DocumentIssuer($this->pdo, $this->documents, $this->artifacts, $this->store),
            $this->documents,
            new DocumentVisibilityPolicy(
                new RouteRecipientRepository($this->pdo),
                new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
            ),
            new DocumentQrService(
                $this->pdo,
                new DocumentQrTokenRepository($this->pdo),
                new DocumentQrScanRepository($this->pdo),
                'https://docs.example.test'
            ),
        );

        $this->routes = new RouteRepository($this->pdo);
        $this->events = new RouteEventRepository($this->pdo);
        $this->recipients = new RouteRecipientRepository($this->pdo);
        $this->finalisations = new RouteFinalisationRepository($this->pdo);
        $versions = new RouteSubjectVersionRepository($this->pdo);
        $steps = new RouteStepRepository($this->pdo);

        $this->rules = new RoutingRuleRegistry();
        $registry = $this->rules;
        $this->rules->registerCoreRoutingRules(
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
        $subjects->register(new FormSubmissionRouteSubject($this->pdo));

        $flow = new FlowDocumentRenderer($this->settings, $this->render);
        $this->finaliser = new RouteFinaliser(
            $this->pdo,
            $this->routes,
            $this->recipients,
            $this->events,
            $this->finalisations,
            $subjects,
            $versions,
            $this->editor->sourceRepo,
            $this->documents,
            $this->editor->issuing,
            static fn (int $tenantId): bool => $flow->isEnabled($tenantId),
            $this->editor->fields,
            new QueueService(new JobRepository($this->pdo)),
        );

        $this->router = new DocumentRouter(
            $this->pdo,
            $this->routes,
            $steps,
            $this->events,
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $this->rules,
            $this->settings,
            null,
            $subjects,
            $versions,
            $this->finaliser,
        );

        $this->routeTemplates = new RouteTemplateRepository($this->pdo);
        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->submissions = new FormSubmissionRepository($this->pdo);
        $this->issuer = new SubmissionIssuer(
            $this->pdo,
            $this->submissions,
            $this->routeTemplates,
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
            $this->finalisations,
        );

        // The letter a supplier request ends in: it prints the answer and the
        // number, and numbers on final approval.
        $this->letter = $this->editor->published(self::TENANT, self::APPLICANT, 'Supplier approval', [
            $this->editor->para('Supplier: ', $this->editor->field('submission.supplier')),
            $this->editor->para('Number: ', $this->editor->field('issued_document.number')),
        ]);
        $this->editor->sourceRepo->updateNumbering($this->letter, self::TENANT, ['pattern' => 'SUP-{SEQ:3}', 'assign' => 'on_final_approval']);

        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        if (is_dir($this->storageRoot)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storageRoot);
        }
    }

    public function testASubmissionRouteReachingFinaliseRaisesTheDocumentRendersItNumbersItAndAddsTheQr(): void
    {
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter);
        $submission = $this->send($formId, ['supplier' => 'Example Mill']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM documents'), 'nothing is raised at submit');

        $route = $this->routeOf((int) $submission['id']);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        $fresh = $this->submissions->find(self::TENANT, (int) $submission['id']);
        self::assertNotNull($fresh);
        self::assertSame('completed', $fresh['state'], 'the route completes');
        self::assertIsInt($fresh['document_id'], 'the finalise step raised the document');
        $document = $this->documents->findById($fresh['document_id'], self::TENANT);
        self::assertNotNull($document);
        self::assertSame('SUP-001', $document['number'], 'and handed out its number');
        self::assertNull($document['created_by'], 'made by the engine, not by a person');

        $finalisation = $this->finalisations->findForRoute(self::TENANT, (int) $route['id']);
        self::assertNotNull($finalisation);
        self::assertSame(RouteFinalisationRepository::DONE, $finalisation['status']);
        self::assertSame($fresh['document_id'], $finalisation['document_id']);
        self::assertSame($this->letter, $finalisation['template_id']);

        // One PDF, stored on that document, rendered from the approved version.
        $artifacts = $this->artifacts->listForDocument($fresh['document_id'], self::TENANT);
        self::assertCount(1, $artifacts);
        self::assertSame((int) $artifacts[0]['id'], $finalisation['artifact_id']);
        self::assertCount(1, $this->render->flowCalls);
        $texts = EditorTemplateFixture::texts($this->render->flowCalls[0]);
        self::assertContains('Supplier: Example Mill', $texts);
        self::assertContains('Number: SUP-001', $texts);
        $version = (new RouteSubjectVersionRepository($this->pdo))->latest(self::TENANT, FormSubmissionRouteSubject::TYPE, (int) $submission['id']);
        self::assertNotNull($version);
        self::assertSame($version['content_hash'], $finalisation['content_hash']);
        self::assertContains('Made from version 1 · SHA-256 ' . $version['content_hash'], $texts, 'the PDF prints the hash');
        self::assertCount(1, EditorTemplateFixture::qrBlocks($this->render->flowCalls[0]), 'and carries the QR');
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM document_qr_tokens WHERE document_id = ' . $fresh['document_id']));

        // Nobody acted at the finalise step: the trail has no entry for it and
        // nobody holds an item there.
        foreach ($this->events->listForRoute((int) $route['id'], self::TENANT) as $event) {
            self::assertNotSame($finalisation['step_id'], $event['step_id']);
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM document_route_recipients WHERE step_id = ' . $finalisation['step_id']));

        // The applicant sees it ready and may open it; the file is served.
        $progress = $this->progress->forSubmission(self::TENANT, $fresh, self::APPLICANT);
        self::assertSame(['status' => 'ready', 'can_open' => true], $progress['final_document']);
        $last = $progress['stages'][count($progress['stages']) - 1];
        self::assertSame(RouteStepKind::FINALISE, $last['kind']);
        self::assertSame('done', $last['status']);
        $file = (new RouteFinalDocumentFile($this->finalisations, $this->artifacts, $this->store))
            ->forRoute(self::TENANT, (int) $route['id'], 'Supplier request');
        self::assertSame(200, $file->getStatusCode());
        self::assertSame($this->render->pdfBytes, $file->getBody());

        // A form that keeps the final document in the office says so.
        $this->forms->update(self::TENANT, $formId, ['final_document_to_applicant' => false]);
        self::assertFalse($this->progress->forSubmission(self::TENANT, $fresh, self::APPLICANT)['final_document']['can_open'] ?? true);
    }

    public function testWithTheRenderServiceDownTheRouteCompletesTheStepWaitsAndTheJobMakesItLater(): void
    {
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter);
        $submission = $this->send($formId, ['supplier' => 'Example Mill']);
        $route = $this->routeOf((int) $submission['id']);

        $this->render->throwOnRender = true;
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        $fresh = $this->submissions->find(self::TENANT, (int) $submission['id']);
        self::assertNotNull($fresh);
        self::assertSame('completed', $fresh['state'], 'the approval stands');
        $finalisation = $this->finalisations->findForRoute(self::TENANT, (int) $route['id']);
        self::assertNotNull($finalisation);
        self::assertSame(RouteFinalisationRepository::WAITING, $finalisation['status']);
        self::assertSame(RouteFinaliser::REASON_RENDER_UNAVAILABLE, $finalisation['reason']);
        self::assertSame(['status' => 'waiting', 'can_open' => false], $this->progress->forSubmission(self::TENANT, $fresh, self::APPLICANT)['final_document']);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM jobs WHERE name = '" . FinaliseRouteJob::NAME . "'"), 'a retry is queued');

        $job = new FinaliseRouteJob(fn (): RouteFinaliser => $this->finaliser);

        // Still down: the job fails, so the queue tries again later.
        try {
            $job->handle(['route_id' => (int) $route['id']]);
            self::fail('a job that made nothing must fail so it is retried');
        } catch (\RuntimeException) {
        }

        // Back up: the job makes it, on the document the first attempt raised.
        $this->render->throwOnRender = false;
        self::assertSame(['outcome' => RouteFinaliser::DONE], $job->handle(['route_id' => (int) $route['id']]));
        $done = $this->finalisations->findForRoute(self::TENANT, (int) $route['id']);
        self::assertNotNull($done);
        self::assertSame(RouteFinalisationRepository::DONE, $done['status']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM documents'), 'one document, however many attempts');
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM document_artifacts'));
        $document = $this->documents->findById((int) $done['document_id'], self::TENANT);
        self::assertSame('SUP-001', $document['number'] ?? null, 'one number, however many attempts');

        // Done is done: running it again changes nothing.
        self::assertSame(['outcome' => RouteFinaliser::DONE], $job->handle(['route_id' => (int) $route['id']]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM document_artifacts'));
    }

    public function testFinaliseIsRefusedWhenNotLastTwiceOrWithoutARenderService(): void
    {
        $document = $this->plainDocument();
        $clerk = ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::CLERK]]];
        $finalise = ['kind' => RouteStepKind::FINALISE, 'final_template_id' => $this->letter];

        $this->assertRefused('must be the last step', fn () => $this->router->issue(self::TENANT, self::APPLICANT, ['id' => $document], 'Letter', [$finalise, $clerk]));
        $this->assertRefused('only one final document step', fn () => $this->router->issue(self::TENANT, self::APPLICANT, ['id' => $document], 'Letter', [$clerk, $finalise, $finalise]));

        $this->render->configured = false;
        $this->assertRefused('PDFs can\'t be made on this install', fn () => $this->router->issue(self::TENANT, self::APPLICANT, ['id' => $document], 'Letter', [$clerk, $finalise]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM document_routes'), 'nothing was written');

        // The designer refuses the same three.
        $graph = new RouteTemplateGraph($this->rules, fn (int $tenantId): bool => $this->finaliser->isAvailable($tenantId));
        $stage = static fn (int $position, array $more = []): array => $more + ['position' => $position, 'canvas_x' => 0, 'canvas_y' => 0];
        $clerkStage = static fn (int $position): array => $stage($position, ['rule_kind' => 'explicit', 'rule_config' => ['profile_ids' => [self::CLERK]]]);
        $finaliseStage = static fn (int $position): array => $stage($position, ['kind' => 'finalise']);

        $this->assertDesignRefused('PDFs can\'t be made on this install', fn () => $graph->validate([$clerkStage(1), $finaliseStage(2)], [], 10, self::TENANT));
        $this->render->configured = true;
        $this->assertDesignRefused('must be the last stage', fn () => $graph->validate([$finaliseStage(1), $clerkStage(2)], [], 10, self::TENANT));
        $this->assertDesignRefused('only one final document stage', fn () => $graph->validate([$clerkStage(1), $finaliseStage(2), $finaliseStage(3)], [], 10, self::TENANT));

        // And round-trips a good one, template and all.
        $valid = $graph->validate([$clerkStage(1), $stage(2, ['kind' => 'finalise', 'final_template_id' => $this->letter, 'label' => 'Approval letter'])], [], 10, self::TENANT);
        $templateId = $this->routeTemplates->create(self::TENANT, 'Letters', null, null);
        $this->routeTemplates->replaceGraph($templateId, self::TENANT, $valid['steps'], $valid['edges']);
        $stored = $this->routeTemplates->stepsFor($templateId, self::TENANT);
        self::assertSame(RouteStepKind::FINALISE, $stored[1]['kind']);
        self::assertSame($this->letter, $stored[1]['final_template_id']);
        self::assertNull($stored[0]['kind']);
    }

    public function testTheTemplateDefaultsToTheFormSettingAndIsRequiredSomewhere(): void
    {
        $other = $this->editor->published(self::TENANT, self::APPLICANT, 'Other letter', [
            $this->editor->para('Other: ', $this->editor->field('submission.supplier')),
        ]);

        // The stage names none: the form's setting is used.
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter);
        $route = $this->routeOf((int) $this->send($formId, ['supplier' => 'A'])['id']);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        self::assertSame($this->letter, $this->finalisations->findForRoute(self::TENANT, (int) $route['id'])['template_id'] ?? null);

        // The stage names one: it wins over the form's.
        $formId = $this->form($this->clerkThenFinalise($other), $this->letter, 'second');
        $route = $this->routeOf((int) $this->send($formId, ['supplier' => 'B'])['id']);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        self::assertSame($other, $this->finalisations->findForRoute(self::TENANT, (int) $route['id'])['template_id'] ?? null);
        self::assertContains('Other: B', EditorTemplateFixture::texts($this->render->flowCalls[1]));

        // Neither names one: the submission is refused, and nothing is written.
        $formId = $this->form($this->clerkThenFinalise(null), null, 'third');
        $before = $this->scalar('SELECT COUNT(*) FROM form_submissions');
        try {
            $this->send($formId, ['supplier' => 'C']);
            self::fail('a route that could not make its final document must not start');
        } catch (FormRejectedException $e) {
            self::assertStringContainsString('no template is chosen', $e->clientMessage);
        }
        self::assertSame($before, $this->scalar('SELECT COUNT(*) FROM form_submissions'));
    }

    public function testADocumentRouteWithAFinaliseStepUsesTheExistingDocument(): void
    {
        $source = $this->editor->sourceRepo->findById($this->letter, self::TENANT);
        self::assertNotNull($source);
        $version = $this->editor->issuing->published(self::TENANT, $source);
        self::assertNotNull($version);
        $issued = $this->editor->issuing->issue(
            new IssueRequest($source, $version, ['submission.supplier' => 'Example Oven'], 'Oven letter', render: false),
            new ActingAs(self::TENANT, self::APPLICANT, static fn (string $p): bool => true),
        );
        $documentId = (int) $issued['document']['id'];

        $route = $this->router->issue(self::TENANT, self::APPLICANT, ['id' => $documentId], 'Oven letter', [
            ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::CLERK]], 'decision' => true],
            ['kind' => RouteStepKind::FINALISE],
        ])['route'];
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM documents'), 'no second document');
        $finalisation = $this->finalisations->findForRoute(self::TENANT, (int) $route['id']);
        self::assertNotNull($finalisation);
        self::assertSame(RouteFinalisationRepository::DONE, $finalisation['status']);
        self::assertSame($documentId, $finalisation['document_id']);
        self::assertSame($this->letter, $finalisation['template_id'], 'the document\'s own template');
        self::assertCount(1, $this->artifacts->listForDocument($documentId, self::TENANT));
        $texts = EditorTemplateFixture::texts($this->render->flowCalls[0]);
        self::assertContains('Supplier: Example Oven', $texts, 'printed with the values it was issued with');
        self::assertContains('Number: SUP-001', $texts);
    }

    public function testARejectedSubmissionMakesNoFinalDocument(): void
    {
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter);
        $route = $this->routeOf((int) $this->send($formId, ['supplier' => 'Example Mill'])['id']);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, 'Not a supplier we use.', RouteVerdict::REJECTED);

        self::assertNull($this->finalisations->findForRoute(self::TENANT, (int) $route['id']));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM documents'));
        self::assertSame([], $this->render->flowCalls);
    }

    // -- helpers --------------------------------------------------------------

    private function assertRefused(string $needle, \Closure $call): void
    {
        try {
            $call();
            self::fail("expected a refusal containing '{$needle}'");
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString($needle, $e->clientMessage);
        }
    }

    private function assertDesignRefused(string $needle, \Closure $call): void
    {
        try {
            $call();
            self::fail("expected a design refusal containing '{$needle}'");
        } catch (RouteTemplateRejectedException $e) {
            self::assertStringContainsString($needle, $e->clientMessage);
        }
    }

    /** Stage 1: the clerk decides. Stage 2: the final document, with this template or none. */
    private function clerkThenFinalise(?int $templateId): int
    {
        $id = $this->routeTemplates->create(self::TENANT, 'Supplier approval ' . bin2hex(random_bytes(3)), null, null);
        $this->routeTemplates->replaceGraph($id, self::TENANT, [
            [
                'position' => 1,
                'rule_kind' => 'explicit',
                'rule_config' => ['profile_ids' => [self::CLERK]],
                'label' => 'Clerk check',
                'decision' => true,
                'decision_quorum' => null,
                'satisfied_by' => 'act',
                'canvas_x' => 0,
                'canvas_y' => 0,
            ],
            [
                'position' => 2,
                'rule_kind' => RouteStepKind::FINALISE_RULE,
                'rule_config' => [],
                'label' => 'Approval letter',
                'decision' => false,
                'decision_quorum' => null,
                'satisfied_by' => 'act',
                'allow_send_back' => false,
                'kind' => RouteStepKind::FINALISE,
                'final_template_id' => $templateId,
                'canvas_x' => 0,
                'canvas_y' => 0,
            ],
        ], []);

        return $id;
    }

    private function form(int $routeTemplateId, ?int $finalTemplateId, string $key = 'supplier_request'): int
    {
        $id = $this->forms->create(self::TENANT, $key, ['en' => 'Supplier request'], null, $routeTemplateId, null);
        $this->fields->create(self::TENANT, $id, 'supplier', 'text', ['en' => 'Supplier'], null, true, [], [], null, null, null);
        $this->forms->update(self::TENANT, $id, ['final_template_id' => $finalTemplateId]);
        $this->forms->transition(self::TENANT, $id, 'draft', 'published');

        return $id;
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private function send(int $formId, array $answers): array
    {
        $form = $this->forms->find(self::TENANT, $formId);
        self::assertNotNull($form);
        $fields = FormFieldRepository::applicantFields($this->fields->listForForm(self::TENANT, $formId));

        return $this->issuer->submit(self::TENANT, self::APPLICANT, $form, $fields, $answers)['submission'];
    }

    /** @return array<string, mixed> */
    private function routeOf(int $submissionId): array
    {
        $routes = $this->routes->listForSubject(FormSubmissionRouteSubject::TYPE, $submissionId, self::TENANT);
        self::assertCount(1, $routes);

        return $routes[0];
    }

    private function plainDocument(): int
    {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Letter') . ', ' . $this->pdo->quote('Letter') . ', 20, ' . self::APPLICANT . ', ' . $now . ')'
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
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
        foreach ([[self::APPLICANT, 'Applicant Huda'], [self::CLERK, 'Clerk Rana']] as [$id, $name]) {
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
