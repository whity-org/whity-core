<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormsApiHandler;
use Whity\Api\FormUploadsApiHandler;
use Whity\Api\MemberFormsApiHandler;
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
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormUploadRepository;
use Whity\Core\Form\FormUploadStore;
use Whity\Core\Form\PrefillResolver;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;
use Whity\Storage\LocalStorageDriver;

/**
 * Who gains what from the switch. The fixture is Example Bakery: a member, a person
 * with no membership here, and a second workspace. All invented.
 */
final class MemberFormsApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const AUTHOR = 10;
    private const MEMBER = 11;
    private const FORMER_MEMBER = 12;
    private const OUTSIDER = 13;

    private PDO $pdo;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormsApiHandler $manage;
    private MemberFormsApiHandler $members;
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Rana Example', 'x', false, 0, 0, NOW(), NOW()),
                   (11, 'Sami Example', 'x', false, 0, 0, NOW(), NOW()),
                   (12, 'Lina Example', 'x', false, 0, 0, NOW(), NOW()),
                   (13, 'Omar Example', 'x', false, 0, 0, NOW(), NOW())
        ");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (100, 'baker', '', 1, NOW())");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (200, 'baker', '', 2, NOW())");
        $this->pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
            VALUES (10, 1, 100, NULL, true, 'active', NOW()),
                   (11, 1, 100, NULL, true, 'active', NOW()),
                   (12, 1, 100, NULL, true, 'inactive', NOW()),
                   (13, 2, 200, NULL, true, 'active', NOW())
        ");

        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $submissions = new FormSubmissionRepository($this->pdo);
        $renderer = new FormRenderer($this->fields, new PrefillResolver($this->pdo));

        $this->storageRoot = sys_get_temp_dir() . '/memberforms-' . bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0o777, true);
        $storage = new LocalStorageDriver($this->storageRoot);
        $artifacts = new DocumentArtifactRepository($this->pdo);
        $uploadRows = new FormUploadRepository($this->pdo);

        $issuer = new SubmissionIssuer(
            $this->pdo,
            $submissions,
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
            $uploadRows,
            new RouteRepository($this->pdo),
            new RouteRecipientRepository($this->pdo),
        );

        $this->manage = new FormsApiHandler($this->forms, $this->fields, $renderer, $submissions, new PublicFormLink('https://records.example.test'));
        $this->members = new MemberFormsApiHandler(
            $this->pdo,
            $this->forms,
            $this->fields,
            $renderer,
            $issuer,
            new FormUploadsApiHandler($this->forms, $this->fields, new FormUploadStore($storage, $uploadRows), new DatabaseSharedStore($this->pdo)),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function asCaller(?int $profileId, int $tenantId = self::TENANT, string $body = ''): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        $request = new Request('POST', '/x', [], $body);
        $request->user = $profileId === null ? null : (object) ['profile_id' => $profileId];

        return $request;
    }

    private function publishedForm(string $key = 'supplier_request'): int
    {
        $id = $this->forms->create(self::TENANT, $key, ['en' => 'Supplier request'], null, null, self::AUTHOR);
        $this->fields->create(self::TENANT, $id, 'supplier', 'text', ['en' => 'Supplier'], null, true, [], [], null, null, 1);
        $this->forms->transition(self::TENANT, $id, 'draft', 'published');

        return $id;
    }

    private function open(int $formId): Response
    {
        return $this->manage->openToMembers($this->asCaller(self::AUTHOR), ['id' => (string) $formId]);
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

    private function submitAs(int $profileId, int $formId, int $tenantId = self::TENANT): Response
    {
        $body = json_encode(['data' => ['supplier' => 'Example Mill']], JSON_THROW_ON_ERROR);

        return $this->members->submit($this->asCaller($profileId, $tenantId, $body), ['id' => (string) $formId]);
    }

    public function testNothingIsOpenUntilSomebodyOpensIt(): void
    {
        $formId = $this->publishedForm();

        self::assertFalse(($this->forms->find(self::TENANT, $formId) ?? [])['open_to_members']);
        self::assertSame([], self::data($this->members->list($this->asCaller(self::MEMBER))));
        self::assertSame(404, $this->members->render($this->asCaller(self::MEMBER), ['id' => (string) $formId])->getStatusCode());
        self::assertSame(404, $this->submitAs(self::MEMBER, $formId)->getStatusCode());
    }

    public function testTheMigrationChangesNoRoleOrPermission(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/198_add_form_member_access.php');
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        self::assertStringNotContainsString('role_permissions', $code);
        self::assertStringNotContainsString('INSERT', $code);
    }

    public function testOpeningRecordsWhoAndWhen(): void
    {
        $formId = $this->publishedForm();

        $response = $this->open($formId);

        self::assertSame(200, $response->getStatusCode());
        $form = self::data($response);
        self::assertTrue($form['open_to_members']);
        self::assertSame(self::AUTHOR, $form['members_opened_by_profile_id']);
        self::assertNotNull($form['members_opened_at']);
    }

    public function testAMemberCanListOpenAndSubmitOnlyTheFormThatWasOpened(): void
    {
        $opened = $this->publishedForm('opened');
        $closed = $this->publishedForm('closed');
        $this->open($opened);

        $listed = self::data($this->members->list($this->asCaller(self::MEMBER)));
        self::assertSame([$opened], array_column($listed, 'id'));

        $rendered = self::data($this->members->render($this->asCaller(self::MEMBER), ['id' => (string) $opened]));
        self::assertSame(['supplier'], array_column($rendered['fields'], 'field_key'));
        self::assertTrue($rendered['accepts_submissions']);

        $submitted = $this->submitAs(self::MEMBER, $opened);
        self::assertSame(201, $submitted->getStatusCode());
        self::assertSame(self::MEMBER, self::data($submitted)['submitted_by_profile_id']);

        self::assertSame(404, $this->submitAs(self::MEMBER, $closed)->getStatusCode());
    }

    public function testSendingAFormDeletesThatPersonsDraftOnly(): void
    {
        $formId = $this->publishedForm();
        $this->open($formId);
        $drafts = new \Whity\Core\Form\FormDraftRepository($this->pdo);
        $drafts->save(self::TENANT, self::MEMBER, $formId, ['supplier' => 'draft'], null);
        $drafts->save(self::TENANT, self::AUTHOR, $formId, ['supplier' => 'theirs'], null);

        self::assertSame(201, $this->submitAs(self::MEMBER, $formId)->getStatusCode());

        self::assertNull($drafts->find(self::TENANT, self::MEMBER, $formId));
        self::assertNotNull($drafts->find(self::TENANT, self::AUTHOR, $formId));
    }

    public function testARefusedSubmissionKeepsTheDraft(): void
    {
        $formId = $this->publishedForm();
        $this->open($formId);
        $drafts = new \Whity\Core\Form\FormDraftRepository($this->pdo);
        $drafts->save(self::TENANT, self::MEMBER, $formId, ['supplier' => 'draft'], null);

        $refused = $this->members->submit(
            $this->asCaller(self::MEMBER, self::TENANT, json_encode(['data' => []], JSON_THROW_ON_ERROR)),
            ['id' => (string) $formId],
        );

        self::assertSame(422, $refused->getStatusCode());
        self::assertNotNull($drafts->find(self::TENANT, self::MEMBER, $formId));
    }

    public function testAMemberIsNotShownWhoAuthoredItOrWhereItGoes(): void
    {
        $formId = $this->publishedForm();
        $this->open($formId);

        $listed = self::data($this->members->list($this->asCaller(self::MEMBER)));

        self::assertSame(
            ['id', 'name', 'description', 'description_i18n', 'submit_label', 'title', 'banner', 'status'],
            array_keys((array) array_values($listed)[0]),
        );
    }

    public function testOnlyAnActiveMemberOfThisWorkspaceGetsIn(): void
    {
        $formId = $this->publishedForm();
        $this->open($formId);

        self::assertSame(404, $this->submitAs(self::FORMER_MEMBER, $formId)->getStatusCode());
        self::assertSame(404, $this->submitAs(self::OUTSIDER, $formId)->getStatusCode());
        self::assertSame([], self::data($this->members->list($this->asCaller(self::FORMER_MEMBER))));

        $noProfile = $this->members->render($this->asCaller(null), ['id' => (string) $formId]);
        self::assertSame(404, $noProfile->getStatusCode());
    }

    public function testAMemberOfAnotherWorkspaceCannotReachItFromTheirOwn(): void
    {
        $formId = $this->publishedForm();
        $this->open($formId);

        self::assertSame(404, $this->submitAs(self::OUTSIDER, $formId, self::OTHER_TENANT)->getStatusCode());
        self::assertSame([], self::data($this->members->list($this->asCaller(self::OUTSIDER, self::OTHER_TENANT))));
    }

    public function testEveryRefusalReadsTheSame(): void
    {
        $closed = $this->publishedForm('closed');
        $missing = 99999;

        $bodies = [
            (string) $this->members->render($this->asCaller(self::MEMBER), ['id' => (string) $closed])->getBody(),
            (string) $this->members->render($this->asCaller(self::MEMBER), ['id' => (string) $missing])->getBody(),
            (string) $this->members->upload($this->asCaller(self::MEMBER), ['id' => (string) $closed])->getBody(),
        ];

        self::assertCount(1, array_unique($bodies));
    }

    public function testClosingShutsTheDoorAndIsIdempotent(): void
    {
        $formId = $this->publishedForm();
        $this->open($formId);

        $close = fn (): Response => $this->manage->closeToMembers($this->asCaller(self::AUTHOR), ['id' => (string) $formId]);

        self::assertFalse(self::data($close())['open_to_members']);
        self::assertSame(200, $close()->getStatusCode());
        self::assertSame(404, $this->submitAs(self::MEMBER, $formId)->getStatusCode());
    }

    public function testArchivingAnOpenFormClosesItToMembers(): void
    {
        $formId = $this->publishedForm();
        $this->open($formId);
        $this->forms->transition(self::TENANT, $formId, 'published', 'archived');

        self::assertSame(404, $this->submitAs(self::MEMBER, $formId)->getStatusCode());
        self::assertSame([], self::data($this->members->list($this->asCaller(self::MEMBER))));
    }

    public function testOpeningIsRefusedOnADraftAndOnAPersonQuestion(): void
    {
        $draft = $this->forms->create(self::TENANT, 'draft', ['en' => 'Draft'], null, null, self::AUTHOR);
        self::assertSame(422, $this->open($draft)->getStatusCode());

        $formId = $this->publishedForm('with_person');
        $this->fields->create(self::TENANT, $formId, 'manager', 'profile_ref', ['en' => 'Manager'], null, true, [], [], null, null, 2);

        $refused = $this->open($formId);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('manager', (string) $refused->getBody());
        self::assertFalse(($this->forms->find(self::TENANT, $formId) ?? [])['open_to_members']);
    }

    public function testAPersonQuestionAddedLaterIsNeitherShownNorChecked(): void
    {
        $formId = $this->publishedForm();
        $this->open($formId);
        $this->fields->create(self::TENANT, $formId, 'manager', 'profile_ref', ['en' => 'Manager'], null, true, [], [], null, null, 2);

        $rendered = self::data($this->members->render($this->asCaller(self::MEMBER), ['id' => (string) $formId]));
        self::assertSame(['supplier'], array_column($rendered['fields'], 'field_key'));

        // Required, and not asked of a member, so it can't block them. Sent anyway,
        // it never reaches the existence check that would answer "is 41 one of yours".
        $body = json_encode(['data' => ['supplier' => 'Example Mill', 'manager' => 41]], JSON_THROW_ON_ERROR);
        $response = $this->members->submit($this->asCaller(self::MEMBER, self::TENANT, $body), ['id' => (string) $formId]);
        self::assertSame(201, $response->getStatusCode());
    }

    public function testAMemberIsNotAskedAStaffQuestion(): void
    {
        $formId = $this->publishedForm();
        $this->open($formId);
        $this->fields->create(self::TENANT, $formId, 'decision', 'text', ['en' => 'Decision'], null, true, [], [], null, null, 2, null, 'staff');

        $rendered = self::data($this->members->render($this->asCaller(self::MEMBER), ['id' => (string) $formId]));

        self::assertSame(['supplier'], array_column($rendered['fields'], 'field_key'));
        self::assertSame(201, $this->submitAs(self::MEMBER, $formId)->getStatusCode());
    }

    public function testAMemberCannotUploadToAFormThatIsNotOpen(): void
    {
        $formId = $this->publishedForm();

        $response = $this->members->upload($this->asCaller(self::MEMBER), ['id' => (string) $formId]);

        self::assertSame(404, $response->getStatusCode());
    }
}
