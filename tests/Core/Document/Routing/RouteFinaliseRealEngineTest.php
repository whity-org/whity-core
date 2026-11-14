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
use Whity\Core\Document\Fields\RouteValueFieldProvider;
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
use Whity\Core\Form\FormReferenceAssign;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionFieldProvider;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormSubmissionRouteSubject;
use Whity\Core\Form\FormTemplateDraft;
use Whity\Core\Form\FormUploadRepository;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Form\SubmissionProgress;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Api\RoutesApiHandler;
use Whity\Core\Request;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
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
    /** WHIT-1018: two more committee members; the second supervises the project. */
    private const MEMBER = 12;
    private const SUPERVISOR = 13;

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
    private HookManager $hooks;
    /** @var list<array<string, mixed>> */
    private array $numbered = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $fired = [];

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
        $this->hooks = new HookManager();
        $this->hooks->listen(Events::DOCUMENT_NUMBERED, function (array $event): array {
            $this->numbered[] = $event;

            return $event;
        });
        $this->recordOn($this->hooks, Events::DOCUMENT_FINALISED, Events::ROUTE_COMPLETED);
        $this->recordOn($this->editor->hooks, Events::DOCUMENT_ISSUING, Events::DOCUMENT_ISSUED);
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
            RouteFinaliser::pdfsCanBeMade($flow, $this->settings),
            $this->editor->fields,
            new QueueService(new JobRepository($this->pdo)),
            $this->hooks,
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
            $this->hooks,
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

    public function testATemplatesOwnFieldPrintsTheAnswerOfTheQuestionThatFitsIt(): void
    {
        $id = $this->editor->sourceRepo->create(self::TENANT, [
            'kind' => 'template',
            'title' => 'Starter letter',
            'lang' => 'en',
            'default_view' => 'page',
            'blocks' => [$this->editor->para('Vendor: ', $this->editor->field('template.vendor'))],
            'own_fields' => [['key' => 'vendor', 'label' => 'Supplier']],
            'scope' => 'tenant',
            'created_by' => self::APPLICANT,
        ]);
        $this->editor->publish(self::TENANT, $id, self::APPLICANT);

        $this->approve($this->form($this->clerkThenFinalise(null), $id), ['supplier' => 'Example Mill']);

        self::assertContains('Vendor: Example Mill', EditorTemplateFixture::texts($this->render->flowCalls[0]));
    }

    public function testAValueEnteredAtAStepPrintsOnTheFinalDocument(): void
    {
        // WHIT-1016: the clerk enters the committee meeting number while
        // approving, and the approval letter prints it.
        $id = $this->editor->sourceRepo->create(self::TENANT, [
            'kind' => 'template',
            'title' => 'Approval letter',
            'lang' => 'en',
            'default_view' => 'page',
            'blocks' => [$this->editor->para('Meeting: ', $this->editor->field(RouteValueFieldProvider::path('meeting_no')))],
            'scope' => 'tenant',
            'created_by' => self::APPLICANT,
        ]);
        $this->editor->publish(self::TENANT, $id, self::APPLICANT);

        $design = $this->clerkThenFinalise(null, [['key' => 'meeting_no', 'label' => 'Committee meeting number']]);
        $provider = new RouteValueFieldProvider($this->pdo);
        self::assertSame(
            [['group' => 'Entered during review', 'items' => [[
                'path' => 'route.value.meeting_no',
                'label' => 'Committee meeting number',
                'sample' => 'Committee meeting number',
            ]]]],
            $provider->groups(self::TENANT),
            'the template picker offers what the designs ask for',
        );
        self::assertSame([], $provider->groups(2), 'and only in their own workspace');

        $submission = $this->send($this->form($design, $id), ['supplier' => 'Example Mill']);
        $route = $this->routeOf((int) $submission['id']);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED, null, false, ['meeting_no' => '14']);

        self::assertContains('Meeting: 14', EditorTemplateFixture::texts($this->render->flowCalls[0]));
    }

    public function testAReferenceGivenOnApprovalPrintsTheAnswerAndTheMeetingAndIsOnTheFinalDocument(): void
    {
        // WHIT-1017: the AAU IRB shape. The prefix follows an answer, the
        // middle part is the meeting number the clerk enters while approving,
        // and the year is the academic year starting in September.
        $id = $this->editor->sourceRepo->create(self::TENANT, [
            'kind' => 'template',
            'title' => 'Approval certificate',
            'lang' => 'en',
            'default_view' => 'page',
            'blocks' => [$this->editor->para('Approval No.: ', $this->editor->field(FormSubmissionFieldProvider::REFERENCE))],
            'scope' => 'tenant',
            'created_by' => self::APPLICANT,
        ]);
        $this->editor->publish(self::TENANT, $id, self::APPLICANT);

        $formId = $this->form($this->clerkThenFinalise(null, [['key' => 'meeting_no', 'label' => 'Committee meeting number']]), $id);
        $this->forms->update(self::TENANT, $formId, [
            'reference_format' => '{ANSWER:supplier|Example Mill=MILL|*=OTHER} {SEQ}/{STEP:meeting_no}/{AY:9}',
            'reference_assign' => FormReferenceAssign::APPROVAL,
        ]);
        self::assertSame(FormReferenceAssign::APPROVAL, $this->forms->find(self::TENANT, $formId)['reference_assign'] ?? null);

        $first = $this->send($formId, ['supplier' => 'Example Mill']);
        self::assertNull($first['reference'], 'nothing is given at submit');
        $route = $this->routeOf((int) $first['id']);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED, null, false, ['meeting_no' => '14']);

        $year = (int) gmdate('Y') - ((int) gmdate('n') < 9 ? 1 : 0);
        $expected = 'MILL 1/14/' . $year . '-' . ($year + 1);
        self::assertSame($expected, $this->submissions->find(self::TENANT, (int) $first['id'])['reference'] ?? null);
        self::assertContains('Approval No.: ' . $expected, EditorTemplateFixture::texts($this->render->flowCalls[0]));

        // Another answer counts on its own; the same answer carries on.
        $other = $this->send($formId, ['supplier' => 'Example Oven']);
        $this->router->act(self::TENANT, self::CLERK, $this->routeOf((int) $other['id']), RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED, null, false, ['meeting_no' => '15']);
        self::assertStringStartsWith('OTHER 1/15/', (string) ($this->submissions->find(self::TENANT, (int) $other['id'])['reference'] ?? ''));
        $again = $this->send($formId, ['supplier' => 'Example Mill']);
        $this->router->act(self::TENANT, self::CLERK, $this->routeOf((int) $again['id']), RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED, null, false, ['meeting_no' => '15']);
        self::assertStringStartsWith('MILL 2/15/', (string) ($this->submissions->find(self::TENANT, (int) $again['id'])['reference'] ?? ''));

        // A submission that is not approved takes no number.
        $refused = $this->send($formId, ['supplier' => 'Example Mill']);
        $this->router->act(self::TENANT, self::CLERK, $this->routeOf((int) $refused['id']), RouteAction::ACKNOWLEDGED, 'No.', RouteVerdict::REJECTED);
        self::assertNull($this->submissions->find(self::TENANT, (int) $refused['id'])['reference'] ?? null);
    }

    public function testTheSupervisorIsLeftOutOfTheCommitteeAndTheApprovalsPrintARowEach(): void
    {
        // WHIT-1018: the committee is three people; the applicant names one of
        // them as supervisor, so that one is never asked, never counted and
        // never printed.
        $cell = static fn (array ...$runs): array => $runs;
        $text = static fn (string $t): array => ['type' => 'text', 'text' => $t];
        $id = $this->editor->sourceRepo->create(self::TENANT, [
            'kind' => 'template',
            'title' => 'Approval certificate',
            'lang' => 'en',
            'default_view' => 'page',
            'blocks' => [[
                'id' => 't1',
                'type' => 'table',
                'headerRow' => true,
                'rows' => [
                    [$cell($text('Member')), $cell($text('Approved'))],
                    [$cell($this->editor->field('route.approvals.*.name')), $cell($this->editor->field('route.approvals.*.mark'))],
                    [$cell($text('Total')), $cell($this->editor->field('route.approvals.count'))],
                ],
            ]],
            'scope' => 'tenant',
            'created_by' => self::APPLICANT,
        ]);
        $this->editor->publish(self::TENANT, $id, self::APPLICANT);

        $design = $this->routeTemplates->create(self::TENANT, 'Ethics review', null, null);
        $this->routeTemplates->replaceGraph($design, self::TENANT, [
            [
                'position' => 1,
                'rule_kind' => 'explicit',
                'rule_config' => ['profile_ids' => [self::CLERK, self::MEMBER, self::SUPERVISOR]],
                'label' => 'Ethics committee',
                'decision' => true,
                'decision_quorum' => 'all',
                'satisfied_by' => 'act',
                'exclude_named_in' => ['supervisor', 'co_supervisor'],
                'canvas_x' => 0,
                'canvas_y' => 0,
            ],
            [
                'position' => 2,
                'rule_kind' => RouteStepKind::FINALISE_RULE,
                'rule_config' => [],
                'label' => 'Certificate',
                'decision' => false,
                'decision_quorum' => null,
                'satisfied_by' => 'act',
                'allow_send_back' => false,
                'kind' => RouteStepKind::FINALISE,
                'final_template_id' => null,
                'canvas_x' => 0,
                'canvas_y' => 0,
            ],
        ], []);
        self::assertSame(['supervisor', 'co_supervisor'], $this->routeTemplates->stepsFor($design, self::TENANT)[0]['exclude_named_in']);

        $formId = $this->form($design, $id);
        $this->fields->create(self::TENANT, $formId, 'supervisor', 'profile_ref', ['en' => 'Supervisor'], null, false, [], [], null, null, null);
        $submission = $this->send($formId, ['supplier' => 'Example Mill', 'supervisor' => self::SUPERVISOR]);
        $route = $this->routeOf((int) $submission['id']);

        self::assertNull($this->open((int) $route['id'], self::SUPERVISOR), 'the supervisor is not asked');
        self::assertNotNull($this->open((int) $route['id'], self::MEMBER));
        self::assertSame(['supervisor', 'co_supervisor'], (new RouteStepRepository($this->pdo))->listForRoute((int) $route['id'], self::TENANT)[0]['exclude_named_in']);

        $first = $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        self::assertNull($first['decided'], 'still waiting on the other member');
        $second = $this->router->act(self::TENANT, self::MEMBER, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        self::assertSame('approved', $second['decided'], 'all who were asked approved; the supervisor does not count');

        $table = null;
        foreach ($this->render->flowCalls[0]['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'table') {
                $table = $block;
            }
        }
        self::assertNotNull($table);
        $today = gmdate('Y-m-d');
        self::assertSame(['Member', 'Approved'], $table['columns']);
        self::assertSame([
            ['Clerk Rana', 'Approved ' . $today],
            ['Awss Haddad', 'Approved ' . $today],
            ['Total', '2'],
        ], $table['rows']);
    }

    public function testATemplateMadeFromTheFormIssuesACorrectDocumentFromARealSubmission(): void
    {
        $formId = $this->form($this->clerkThenFinalise(null), null);
        $form = $this->forms->find(self::TENANT, $formId);
        self::assertNotNull($form);
        $id = $this->editor->sourceRepo->create(self::TENANT, [
            'kind' => 'template',
            'title' => 'Supplier request',
            'lang' => 'en',
            'default_view' => 'page',
            'record_type' => 'form.supplier_request',
            'blocks' => FormTemplateDraft::blocks($form, $this->fields->listForForm(self::TENANT, $formId), 'en'),
            'scope' => 'tenant',
            'created_by' => self::APPLICANT,
        ]);
        $this->editor->publish(self::TENANT, $id, self::APPLICANT);
        $this->forms->update(self::TENANT, $formId, ['final_template_id' => $id]);

        $submissionId = $this->approve($formId, ['supplier' => 'Example Mill']);

        $fresh = $this->submissions->find(self::TENANT, $submissionId);
        self::assertNotNull($fresh);
        self::assertIsInt($fresh['document_id']);
        $printed = (string) json_encode($this->render->flowCalls[0], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Supplier request', $printed);
        self::assertStringContainsString('Example Mill', $printed, 'the answer is on the page');
        self::assertStringContainsString(substr((string) $fresh['submitted_at'], 0, 10), $printed, 'and so is when it was sent');
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
        self::assertCount(1, $this->numbered, 'the number is announced as soon as it is handed out, PDF or not');
        self::assertSame('SUP-001', $this->numbered[0]['number']);

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

    public function testAFormSubmissionsFinalDocumentFiresTheIssueEvents(): void
    {
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter);
        $submission = $this->send($formId, ['supplier' => 'Example Mill']);
        self::assertSame([], $this->firedOf(Events::DOCUMENT_ISSUED), 'nothing is issued at submit');

        $route = $this->routeOf((int) $submission['id']);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        $fresh = $this->submissions->find(self::TENANT, (int) $submission['id']);
        self::assertNotNull($fresh);
        $documentId = $fresh['document_id'];
        self::assertIsInt($documentId);

        self::assertCount(1, $this->firedOf(Events::DOCUMENT_ISSUING), 'a listener can refuse it');
        self::assertSame('route', $this->firedOf(Events::DOCUMENT_ISSUING)[0]['origin']);
        $issued = $this->firedOf(Events::DOCUMENT_ISSUED);
        self::assertCount(1, $issued);
        self::assertSame($documentId, $issued[0]['id']);
        self::assertSame('route', $issued[0]['origin']);

        $finalised = $this->firedOf(Events::DOCUMENT_FINALISED);
        self::assertCount(1, $finalised);
        self::assertSame($documentId, $finalised[0]['id']);
        self::assertSame((int) $route['id'], $finalised[0]['route_id']);
        self::assertSame('form_submission', $finalised[0]['subject_type']);
        self::assertSame((int) $submission['id'], $finalised[0]['subject_id']);

        $completed = $this->firedOf(Events::ROUTE_COMPLETED);
        self::assertCount(1, $completed);
        self::assertSame(RouteVerdict::APPROVED, $completed[0]['outcome']);
    }

    public function testADocumentThatAlreadyExistsIsNotIssuedAgainByItsFinaliseStep(): void
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
        self::assertCount(1, $this->firedOf(Events::DOCUMENT_ISSUED));

        $route = $this->router->issue(self::TENANT, self::APPLICANT, ['id' => $documentId], 'Oven letter', [
            ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::CLERK]], 'decision' => true],
            ['kind' => RouteStepKind::FINALISE],
        ])['route'];
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        self::assertCount(1, $this->firedOf(Events::DOCUMENT_ISSUED), 'still only its own issue');
        self::assertCount(1, $this->firedOf(Events::DOCUMENT_FINALISED));
        self::assertSame($documentId, $this->firedOf(Events::DOCUMENT_FINALISED)[0]['id']);
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


    public function testAFinaliseReachedInsideACallersTransactionThatRollsBackLeavesNothing(): void
    {
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter);
        $route = $this->routeOf((int) $this->send($formId, ['supplier' => 'Example Mill'])['id']);
        $jobsBefore = $this->scalar('SELECT COUNT(*) FROM jobs');

        $this->pdo->beginTransaction();
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        self::assertSame([], $this->render->flowCalls, 'nothing is rendered before the caller commits');
        self::assertSame([], $this->numbered, 'nothing is announced before the caller commits');
        $this->pdo->rollBack();

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM documents'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM document_artifacts'));
        self::assertNull($this->finalisations->findForRoute(self::TENANT, (int) $route['id']));
        self::assertSame($jobsBefore, $this->scalar('SELECT COUNT(*) FROM jobs'), 'the deferred request rolled back too');

        // Committed, the same act queues the request inside the transaction, and
        // the caller makes it after its commit.
        $this->pdo->beginTransaction();
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        $this->pdo->commit();
        self::assertSame($jobsBefore + 1, $this->scalar('SELECT COUNT(*) FROM jobs'));
        $this->router->finalisePending(self::TENANT, (int) $route['id']);
        self::assertSame(RouteFinalisationRepository::DONE, $this->finalisations->findForRoute(self::TENANT, (int) $route['id'])['status'] ?? null);
    }

    public function testWhatARetryCannotMendFailsAtOnceAndAPersonCanTryAgain(): void
    {
        // PDFs turned off for the workspace after the route started.
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter);
        $route = $this->routeOf((int) $this->send($formId, ['supplier' => 'A'])['id']);
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'false');
        self::assertFalse($this->finaliser->isAvailable(self::TENANT), 'no PDFs stored, no final documents');
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        $row = $this->finalisations->findForRoute(self::TENANT, (int) $route['id']);
        self::assertSame(RouteFinalisationRepository::FAILED, $row['status'] ?? null);
        self::assertSame(RouteFinaliser::REASON_PDFS_OFF, $row['reason'] ?? null);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM documents'), 'no document without its PDF');

        // Turned back on, somebody tries again: it goes to the queue, which makes it.
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'true');
        self::assertTrue($this->finaliser->retry(self::TENANT, (int) $route['id']));
        self::assertSame(RouteFinalisationRepository::WAITING, $this->finalisations->findForRoute(self::TENANT, (int) $route['id'])['status'] ?? null);
        $job = new FinaliseRouteJob(fn (): RouteFinaliser => $this->finaliser);
        self::assertSame(['outcome' => RouteFinaliser::DONE], $job->handle(['route_id' => (int) $route['id']]));
        self::assertFalse($this->finaliser->retry(self::TENANT, (int) $route['id']), 'nothing to try again once made');

        // A plugin refusing the document is not something a retry changes.
        $this->editor->hooks->listen(Events::DOCUMENT_ISSUING, static function (array $event): array {
            throw new HookVetoException('Not today.');
        });
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter, 'second');
        $route = $this->routeOf((int) $this->send($formId, ['supplier' => 'B'])['id']);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        $row = $this->finalisations->findForRoute(self::TENANT, (int) $route['id']);
        self::assertSame(RouteFinalisationRepository::FAILED, $row['status'] ?? null);
        self::assertSame(RouteFinaliser::REASON_REFUSED_BY_PLUGIN, $row['reason'] ?? null);
    }

    public function testWaitingEndsAfterTheLastAttemptAndEachRetryGetsItsOwnJob(): void
    {
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter);
        $route = $this->routeOf((int) $this->send($formId, ['supplier' => 'A'])['id']);
        $this->render->throwOnRender = true;
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        for ($i = 2; $i < RouteFinaliser::MAX_ATTEMPTS; $i++) {
            self::assertSame(RouteFinaliser::WAITING, $this->finaliser->finalise(self::TENANT, (int) $route['id']));
        }
        self::assertSame(RouteFinaliser::MAX_ATTEMPTS - 1, $this->scalar("SELECT COUNT(*) FROM jobs WHERE name = '" . FinaliseRouteJob::NAME . "'"), 'a dead job never swallows the next retry');

        self::assertSame(RouteFinaliser::FAILED, $this->finaliser->finalise(self::TENANT, (int) $route['id']));
        $row = $this->finalisations->findForRoute(self::TENANT, (int) $route['id']);
        self::assertSame(RouteFinaliser::REASON_GAVE_UP, $row['reason'] ?? null);
    }

    public function testReachingTwiceAtOnceAndAnOvertakenAttemptAreHarmless(): void
    {
        $formId = $this->form($this->clerkThenFinalise(null), $this->letter);
        $route = $this->routeOf((int) $this->send($formId, ['supplier' => 'A'])['id']);
        $routeId = (int) $route['id'];
        $stepId = $this->scalar("SELECT id FROM document_route_steps WHERE route_id = {$routeId} AND kind = 'finalise'");

        $this->finalisations->reach(self::TENANT, $routeId, $stepId, FormSubmissionRouteSubject::TYPE, 1, null, 1, 'a');
        $this->finalisations->reach(self::TENANT, $routeId, $stepId, FormSubmissionRouteSubject::TYPE, 1, null, 2, 'b');
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM route_finalisations WHERE route_id = {$routeId}"));
        $row = $this->finalisations->findForRoute(self::TENANT, $routeId);
        self::assertNotNull($row);
        self::assertSame('b', $row['content_hash']);

        $first = $this->finalisations->claim(self::TENANT, $row['id']);
        self::assertNotNull($first);
        $meanwhile = $this->finalisations->claim(self::TENANT, $row['id']);
        self::assertNull($meanwhile, 'one attempt at a time');
        // The claim goes stale and a second attempt takes over.
        $this->pdo->exec("UPDATE route_finalisations SET attempt_started_at = '2000-01-01 00:00:00' WHERE id = " . $row['id']);
        $second = $this->finalisations->claim(self::TENANT, $row['id']);
        self::assertNotNull($second);
        $artifact = $this->seedArtifact();
        self::assertFalse($this->finalisations->complete(self::TENANT, $row['id'], $first, $artifact[0], $artifact[1]), 'the overtaken attempt finishes nothing');
        $this->finalisations->release(self::TENANT, $row['id'], $first, 'error', null);
        self::assertSame(RouteFinalisationRepository::WAITING, $this->finalisations->findForRoute(self::TENANT, $routeId)['status'] ?? null);
        self::assertTrue($this->finalisations->complete(self::TENANT, $row['id'], $second, $artifact[0], $artifact[1]));
    }

    public function testADocumentRoutePrintsTheApprovedTemplateVersionAndStillPrintsItsNumberWhenFinalisingFails(): void
    {
        $issue = function (string $supplier): int {
            $source = $this->editor->sourceRepo->findById($this->letter, self::TENANT);
            self::assertNotNull($source);
            $version = $this->editor->issuing->published(self::TENANT, $source);
            self::assertNotNull($version);

            return (int) $this->editor->issuing->issue(
                new IssueRequest($source, $version, ['submission.supplier' => $supplier], 'Oven letter', render: false),
                new ActingAs(self::TENANT, self::APPLICANT, static fn (string $p): bool => true),
            )['document']['id'];
        };
        $documentId = $issue('Example Oven');

        // The template moves on after the document was issued.
        $this->editor->redraft(self::TENANT, $this->letter, ['blocks' => [
            $this->editor->para('Changed wording: ', $this->editor->field('submission.supplier')),
            $this->editor->para('Number: ', $this->editor->field('issued_document.number')),
        ]], self::APPLICANT);
        $this->editor->publish(self::TENANT, $this->letter, self::APPLICANT);

        $route = $this->router->issue(self::TENANT, self::APPLICANT, ['id' => $documentId], 'Oven letter', [
            ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::CLERK]], 'decision' => true],
            ['kind' => RouteStepKind::FINALISE],
        ])['route'];
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        $texts = EditorTemplateFixture::texts($this->render->flowCalls[0]);
        self::assertContains('Supplier: Example Oven', $texts, 'the version it was approved with');

        // A second document whose final stage names a template deleted since:
        // the stage fails, and the number is still printed on the document's own.
        $documentId = $issue('Example Mill');
        $other = $this->editor->published(self::TENANT, self::APPLICANT, 'Other letter');
        $route = $this->router->issue(self::TENANT, self::APPLICANT, ['id' => $documentId], 'Mill letter', [
            ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::CLERK]], 'decision' => true],
            ['kind' => RouteStepKind::FINALISE, 'final_template_id' => $other],
        ])['route'];
        $this->editor->sourceRepo->delete($other, self::TENANT);
        $calls = count($this->render->flowCalls);
        $this->router->act(self::TENANT, self::CLERK, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        $row = $this->finalisations->findForRoute(self::TENANT, (int) $route['id']);
        self::assertSame(RouteFinaliser::REASON_TEMPLATE_MISSING, $row['reason'] ?? null);
        self::assertCount($calls + 1, $this->render->flowCalls, 'the reprint the finalise step owed');
        self::assertContains('Number: SUP-002', EditorTemplateFixture::texts($this->render->flowCalls[$calls]));
        self::assertCount(1, $this->artifacts->listForDocument($documentId, self::TENANT));
    }

    public function testTheSenderReadsTheFinalDocumentOnlyWhenTheFormAllowsItEvenAsAReviewer(): void
    {
        // The applicant is also the stage's reviewer.
        $templateId = $this->routeTemplates->create(self::TENANT, 'Self check', null, null);
        $this->routeTemplates->replaceGraph($templateId, self::TENANT, [
            ['position' => 1, 'rule_kind' => 'explicit', 'rule_config' => ['profile_ids' => [self::APPLICANT]], 'label' => null,
             'decision' => true, 'decision_quorum' => null, 'satisfied_by' => 'act', 'canvas_x' => 0, 'canvas_y' => 0],
            ['position' => 2, 'rule_kind' => RouteStepKind::FINALISE_RULE, 'rule_config' => [], 'label' => null,
             'decision' => false, 'decision_quorum' => null, 'satisfied_by' => 'act', 'allow_send_back' => false,
             'kind' => RouteStepKind::FINALISE, 'final_template_id' => null, 'canvas_x' => 0, 'canvas_y' => 0],
        ], []);
        $formId = $this->form($templateId, $this->letter);
        $route = $this->routeOf((int) $this->send($formId, ['supplier' => 'A'])['id']);
        $this->router->act(self::TENANT, self::APPLICANT, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        $api = $this->routesApi();
        $request = new Request('GET', '/api/v1/routes/' . $route['id'] . '/final-document', [], '');
        $request->user = (object) ['profile_id' => self::APPLICANT];
        self::assertSame(200, $api->finalDocument($request, ['routeId' => (string) $route['id']])->getStatusCode());

        $this->forms->update(self::TENANT, $formId, ['final_document_to_applicant' => false]);
        self::assertSame(404, $api->finalDocument($request, ['routeId' => (string) $route['id']])->getStatusCode());
    }

    // -- helpers --------------------------------------------------------------

    private function routesApi(): RoutesApiHandler
    {
        $subjects = new RouteSubjectRegistry();
        $subjects->register(new DocumentRouteSubject($this->pdo));
        $subjects->register(new FormSubmissionRouteSubject($this->pdo));
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(false);

        return new RoutesApiHandler(
            $this->pdo,
            $this->routes,
            new RouteStepRepository($this->pdo),
            $this->events,
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $this->router,
            $subjects,
            new RouteSubjectVersionRepository($this->pdo),
            $this->documents,
            (new \ReflectionClass(DocumentVisibilityPolicy::class))->newInstanceWithoutConstructor(),
            $roles,
            null,
            $this->finalisations,
            new RouteFinalDocumentFile($this->finalisations, $this->artifacts, $this->store),
            $this->finaliser,
        );
    }

    /** @return array{0: int, 1: int} A document and an artifact on it. */
    private function seedArtifact(): array
    {
        $documentId = $this->plainDocument();
        $artifact = $this->artifacts->create(self::TENANT, [
            'document_id' => $documentId,
            'storage_key' => 'tenants/1/documents/x.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 1,
            'checksum_sha256' => str_repeat('a', 64),
            'rendered_by' => null,
        ]);

        return [$documentId, $artifact];
    }

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
    /** @param list<array<string, mixed>> $clerkInputs */
    private function clerkThenFinalise(?int $templateId, array $clerkInputs = []): int
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
                'inputs' => $clerkInputs,
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

    /**
     * Send the form and have the clerk approve it, which makes the final document.
     *
     * @param array<string, mixed> $answers
     */
    private function approve(int $formId, array $answers): int
    {
        $submission = $this->send($formId, $answers);
        $this->router->act(self::TENANT, self::CLERK, $this->routeOf((int) $submission['id']), RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        return (int) $submission['id'];
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
        foreach ([[self::APPLICANT, 'Applicant Huda'], [self::CLERK, 'Clerk Rana'], [self::MEMBER, 'Awss Haddad'], [self::SUPERVISOR, 'Ahmad Khalil']] as [$id, $name]) {
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

    private function recordOn(HookManager $hooks, string ...$events): void
    {
        foreach ($events as $event) {
            $hooks->listen($event, function (array $payload) use ($event): array {
                $this->fired[] = [$event, $payload];

                return $payload;
            });
        }
    }

    /**
     * @phpstan-impure
     * @return list<array<string, mixed>>
     */
    private function firedOf(string $event): array
    {
        return array_values(array_map(
            static fn (array $f): array => $f[1],
            array_filter($this->fired, static fn (array $f): bool => $f[0] === $event)
        ));
    }

    /** @return array<string, mixed>|null */
    private function open(int $routeId, int $profileId): ?array
    {
        return $this->recipients->findOpenForProfile(self::TENANT, $routeId, $profileId);
    }
}
