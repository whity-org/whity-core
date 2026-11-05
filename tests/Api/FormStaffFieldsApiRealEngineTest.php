<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormFieldsApiHandler;
use Whity\Api\FormSubmissionReviewsApiHandler;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Form\FieldCondition;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormStatus;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormSubmissionReviewRepository;
use Whity\Core\Form\FormUploadRepository;
use Whity\Core\Form\PrefillResolver;
use Whity\Core\Form\PublicFormView;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;
use Whity\Storage\LocalStorageDriver;

/**
 * A staff field is never asked of the applicant, and the office's answers stay
 * out of the submission. Both surfaces that serve an applicant are checked.
 */
final class FormStaffFieldsApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const APPLICANT = 10;
    private const OFFICER = 11;

    private PDO $pdo;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormSubmissionRepository $submissions;
    private FormSubmissionReviewsApiHandler $reviews;
    private FormFieldsApiHandler $fieldsApi;
    private SubmissionIssuer $issuer;
    private FormRenderer $renderer;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Sami Example', 'x', false, 0, 0, NOW(), NOW()),
                   (11, 'Rana Example', 'x', false, 0, 0, NOW(), NOW())
        ");

        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->submissions = new FormSubmissionRepository($this->pdo);
        $this->renderer = new FormRenderer($this->fields, new PrefillResolver($this->pdo));
        $this->fieldsApi = new FormFieldsApiHandler($this->forms, $this->fields);

        $artifacts = new DocumentArtifactRepository($this->pdo);
        $this->issuer = new SubmissionIssuer(
            $this->pdo,
            $this->submissions,
            new DocumentIssuer(
                $this->pdo,
                new DocumentRepository($this->pdo),
                $artifacts,
                new DocumentArtifactStore(new LocalStorageDriver(sys_get_temp_dir())),
            ),
            new RouteTemplateRepository($this->pdo),
            new DocumentRouter(
                $this->pdo,
                new RouteRepository($this->pdo),
                new RouteStepRepository($this->pdo),
                new RouteEventRepository($this->pdo),
                new RouteRecipientRepository($this->pdo),
                new RouteEdgeRepository($this->pdo),
                new RoutingRuleRegistry(),
                new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)),
                null
            ),
            new FormUploadRepository($this->pdo),
            $artifacts,
        );
        $this->reviews = new FormSubmissionReviewsApiHandler(
            $this->fields,
            $this->submissions,
            new FormSubmissionReviewRepository($this->pdo),
            $this->issuer,
        );

        $this->formId = $this->forms->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request'], null, null, null);
        $this->addField('supplier', 'applicant', 'text', ['is_required' => true]);
        $this->addField('decision', 'staff', 'select', [
            'is_required' => true,
            'options' => [['value' => 'approved', 'label' => ['en' => 'Approved']], ['value' => 'declined', 'label' => ['en' => 'Declined']]],
        ]);
        $this->addField('notes', 'staff', 'textarea');
        $this->forms->transition(self::TENANT, $this->formId, FormStatus::DRAFT, FormStatus::PUBLISHED);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function addField(string $key, string $audience, string $type, array $extra = []): void
    {
        $this->fields->create(
            self::TENANT,
            $this->formId,
            $key,
            $type,
            ['en' => ucfirst($key)],
            null,
            ($extra['is_required'] ?? false) === true,
            /** @var list<array{value: string, label: array<string, string>}> */ $extra['options'] ?? [],
            [],
            null,
            null,
            null,
            null,
            $audience,
        );
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function submit(array $answers): int
    {
        $form = $this->forms->find(self::TENANT, $this->formId) ?? [];
        $result = $this->issuer->submit(
            self::TENANT,
            self::APPLICANT,
            $form,
            FormFieldRepository::applicantFields($this->fields->listForForm(self::TENANT, $this->formId)),
            $answers,
        );

        return (int) $result['submission']['id'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(int $submissionId, array $data): Response
    {
        $request = new Request('POST', '/x', [], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
        $request->user = (object) ['profile_id' => self::OFFICER];

        return $this->reviews->record($request, ['id' => (string) $submissionId]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(Response $response): array
    {
        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded['data'];
    }

    public function testAWithdrawnSubmissionTakesNoReview(): void
    {
        $id = $this->submit(['supplier' => 'a']);
        (new FormSubmissionRepository($this->pdo))->markWithdrawn(self::TENANT, $id, self::APPLICANT);

        $response = $this->record($id, ['decision' => 'yes']);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], (new FormSubmissionReviewRepository($this->pdo))->listForSubmission(self::TENANT, $id));
    }

    public function testTheSignedInRenderDoesNotShowAStaffField(): void
    {
        $rendered = $this->renderer->render(self::TENANT, self::APPLICANT, $this->forms->find(self::TENANT, $this->formId) ?? []);

        self::assertSame(['supplier'], array_column($rendered['fields'], 'field_key'));
        self::assertSame(['supplier'], $rendered['sections'][0]['field_keys']);
    }

    public function testThePublicSurfaceDoesNotShowOrAcceptAStaffField(): void
    {
        $fields = $this->fields->listForForm(self::TENANT, $this->formId);

        self::assertSame(['supplier'], array_column(PublicFormView::answerableFields($fields), 'field_key'));
    }

    public function testAStaffPersonPickerDoesNotBlockOpeningAPublicLink(): void
    {
        $this->addField('assigned_to', 'staff', 'profile_ref');

        self::assertSame([], PublicFormView::unanswerableFieldKeys($this->fields->listForForm(self::TENANT, $this->formId)));
    }

    public function testAnApplicantCannotAnswerAStaffQuestionAndARequiredOneDoesNotBlockThem(): void
    {
        $id = $this->submit(['supplier' => 'Example Flour Mill', 'decision' => 'approved']);

        $row = $this->submissions->find(self::TENANT, $id) ?? [];
        self::assertEquals(['supplier' => 'Example Flour Mill'], $row['data']);
    }

    public function testAReviewIsCheckedAgainstTheStaffFieldsAndStaysOutOfTheSubmission(): void
    {
        $id = $this->submit(['supplier' => 'Example Flour Mill']);

        self::assertSame(422, $this->record($id, ['notes' => 'No decision yet'])->getStatusCode());
        self::assertSame(422, $this->record($id, ['decision' => 'maybe'])->getStatusCode());

        $saved = $this->record($id, ['decision' => 'approved', 'notes' => 'Complete', 'supplier' => 'Changed']);
        self::assertSame(201, $saved->getStatusCode());
        self::assertEqualsCanonicalizing(['decision' => 'approved', 'notes' => 'Complete'], self::data($saved)['data']);
        self::assertSame(self::OFFICER, self::data($saved)['recorded_by_profile_id']);

        $row = $this->submissions->find(self::TENANT, $id) ?? [];
        self::assertEquals(['supplier' => 'Example Flour Mill'], $row['data']);
    }

    public function testChangingADecisionKeepsTheOldOne(): void
    {
        $id = $this->submit(['supplier' => 'Example Flour Mill']);
        $this->record($id, ['decision' => 'declined']);
        $this->record($id, ['decision' => 'approved']);

        $shown = self::data($this->reviews->show(new Request('GET', '/x'), ['id' => (string) $id]));

        self::assertSame(['decision' => 'approved'], $shown['current']['data']);
        self::assertSame(
            [['decision' => 'approved'], ['decision' => 'declined']],
            array_column($shown['history'], 'data'),
        );
        self::assertSame(['decision', 'notes'], array_column($shown['fields'], 'field_key'));
    }

    public function testASubmissionWithNoReviewShowsNone(): void
    {
        $id = $this->submit(['supplier' => 'Example Flour Mill']);

        $shown = self::data($this->reviews->show(new Request('GET', '/x'), ['id' => (string) $id]));

        self::assertNull($shown['current']);
        self::assertSame([], $shown['history']);
    }

    public function testASubmissionInAnotherTenantIsNotFound(): void
    {
        $id = $this->submit(['supplier' => 'Example Flour Mill']);

        TenantContext::reset();
        TenantContext::setTenantId(self::OTHER_TENANT);

        self::assertSame(404, $this->record($id, ['decision' => 'approved'])->getStatusCode());
        self::assertSame(404, $this->reviews->show(new Request('GET', '/x'), ['id' => (string) $id])->getStatusCode());
    }

    public function testAFormWithNoStaffFieldsHasNothingToRecord(): void
    {
        $formId = $this->forms->create(self::TENANT, 'plain', ['en' => 'Plain'], null, null, null);
        $this->fields->create(self::TENANT, $formId, 'supplier', 'text', ['en' => 'Supplier'], null, false, [], [], null, null, null);
        $this->forms->transition(self::TENANT, $formId, FormStatus::DRAFT, FormStatus::PUBLISHED);
        $form = $this->forms->find(self::TENANT, $formId) ?? [];
        $result = $this->issuer->submit(self::TENANT, self::APPLICANT, $form, $this->fields->listForForm(self::TENANT, $formId), ['supplier' => 'x']);

        self::assertSame(422, $this->record((int) $result['submission']['id'], ['a' => 'b'])->getStatusCode());
    }

    public function testAudienceIsWrittenAndRefusedThroughTheApi(): void
    {
        $formId = $this->forms->create(self::TENANT, 'authoring', ['en' => 'Authoring'], null, null, null);
        $post = fn (array $body): Response => $this->fieldsApi->create(
            new Request('POST', '/x', [], json_encode($body + ['label' => ['en' => 'x']], JSON_THROW_ON_ERROR)),
            ['id' => (string) $formId],
        );

        $created = $post(['field_key' => 'decision', 'field_type' => 'text', 'audience' => 'staff']);
        self::assertSame('staff', self::data($created)['audience']);
        self::assertSame('applicant', self::data($post(['field_key' => 'name', 'field_type' => 'text']))['audience']);

        self::assertSame(422, $post(['field_key' => 'a', 'field_type' => 'text', 'audience' => 'everyone'])->getStatusCode());
        // Nothing can upload against a review yet, so it would never have an answer.
        self::assertSame(422, $post(['field_key' => 'b', 'field_type' => 'file', 'audience' => 'staff'])->getStatusCode());
    }

    public function testConditionsCannotCrossBetweenApplicantAndStaff(): void
    {
        $this->expectException(FormRejectedException::class);
        $this->expectExceptionMessage('another audience');

        FieldCondition::assertAuthorable([
            ['field_key' => 'decision', 'field_type' => 'text', 'audience' => 'staff', 'visible_when' => null],
            ['field_key' => 'appeal', 'field_type' => 'text', 'audience' => 'applicant', 'visible_when' => ['field' => 'decision', 'equals' => 'x']],
        ]);
    }

    public function testTheMigrationGrantsReviewToWhoeverManagesFormsAndNoOneElse(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.name AS role, p.name AS permission
               FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name IN ('forms:manage', 'forms:review')"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $holders = static fn (string $permission): array => array_values(array_map(
            static fn (array $row): string => (string) $row['role'],
            array_filter($rows, static fn (array $row): bool => $row['permission'] === $permission),
        ));

        self::assertNotSame([], $holders('forms:manage'));
        self::assertEqualsCanonicalizing($holders('forms:manage'), $holders('forms:review'));
    }
}
