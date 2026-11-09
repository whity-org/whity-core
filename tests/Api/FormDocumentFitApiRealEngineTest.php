<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentPublishingApiHandler;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Api\FormDocumentFitApiHandler;
use Whity\Api\FormsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionFieldProvider;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\PrefillResolver;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * A form against its final document template, on the migrated schema. Example
 * Bakery's supplier form.
 */
final class FormDocumentFitApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const OWNER = 10;  // reads, writes and publishes documents
    private const AUTHOR = 11; // reads documents, can't change them
    private const WRITER = 12; // writes documents, can't publish them
    private const FOREIGN = 20;

    private PDO $pdo;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private DocumentSourceRepository $sources;
    private DocumentSourceVersionRepository $versions;
    private DocumentPublishingApiHandler $publishing;
    private FormDocumentFitApiHandler $handler;
    private FormsApiHandler $formsApi;
    private int $formId;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $roles = new RoleChecker(Database::withFactory(fn (): PDO => $this->pdo), new PermissionRegistry());
        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->sources = new DocumentSourceRepository($this->pdo);
        $this->versions = new DocumentSourceVersionRepository($this->pdo);

        $registry = new DocumentFieldRegistry();
        $registry->addTenantSource(fn (int $tenantId): array => FormSubmissionFieldProvider::forTenant($this->pdo, $tenantId));
        $sourcesApi = new DocumentSourcesApiHandler(
            $this->sources,
            new DocumentAccessPolicy(),
            $roles,
            new OuReachResolver($this->pdo, new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())),
        );
        $this->publishing = new DocumentPublishingApiHandler(
            $sourcesApi,
            $this->versions,
            $registry,
            new DocumentEmbedRegistry(),
            $roles,
            new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)),
        );
        $this->handler = new FormDocumentFitApiHandler(
            $this->forms,
            $this->fields,
            $this->sources,
            $this->versions,
            $sourcesApi,
            $this->publishing,
            $registry,
            $roles,
            new RouteTemplateRepository($this->pdo),
        );
        $this->formsApi = new FormsApiHandler(
            $this->forms,
            $this->fields,
            new FormRenderer($this->fields, new PrefillResolver($this->pdo)),
            new FormSubmissionRepository($this->pdo),
            new PublicFormLink('https://records.example.test'),
        );

        $this->formId = $this->forms->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request', 'ar' => 'طلب مورد'], null, null, null);
        $this->question('supplier', ['en' => 'Supplier', 'ar' => 'المورد']);
        $this->question('notes', ['en' => 'Notes', 'ar' => 'ملاحظات']);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    public function testItWarnsInBothDirections(): void
    {
        $this->link($this->template(['submission.supplier' => 'Supplier', 'template.delivery_date' => 'Delivery date']));

        $fit = $this->fit(self::AUTHOR);

        self::assertFalse($fit['hidden']);
        self::assertSame(1, $fit['filled']);
        self::assertSame('delivery_date', $fit['needs'][0]['field_key']);
        self::assertSame(['notes'], array_column($fit['unused'], 'field_key'));
        self::assertFalse($fit['can_edit_template']);
    }

    public function testAFormWithNoTemplateHasNoReport(): void
    {
        $response = $this->call('show', self::AUTHOR);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->data($response));
    }

    public function testATemplateTheCallerCannotSeeDiscloseNothing(): void
    {
        $this->link($this->template(['submission.secret' => 'Secret field'], scope: 'personal', by: self::WRITER));

        self::assertSame(['hidden' => true], $this->fit(self::AUTHOR));
    }

    public function testAddingToATemplateChangesItsDraftAndNotWhatPrints(): void
    {
        $id = $this->template(['submission.supplier' => 'Supplier']);
        $this->link($id);
        $before = $this->versions->find($id, self::TENANT, 1);

        $response = $this->call('addToTemplate', self::OWNER, ['field_keys' => ['notes']]);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame($before, $this->versions->find($id, self::TENANT, 1), 'the published version is as it was');
        self::assertSame(1, $this->source($id)['published_version']);
        $fit = $this->data($response);
        self::assertTrue($fit['unused'][0]['in_draft'], 'it is on its way, not done');
    }

    public function testAQuestionAddedToAnUnrelatedTemplateCanStillBePublishedAndCounts(): void
    {
        $id = $this->template(['template.supplier' => 'Supplier'], own: [['key' => 'supplier', 'label' => 'Supplier']]);
        $this->link($id);

        $this->call('addToTemplate', self::OWNER, ['field_keys' => ['notes']]);

        $draft = $this->source($id);
        self::assertSame(['supplier', 'notes'], array_column($draft['own_fields'], 'key'), 'an own field, because its record type offers no answers');
        self::assertSame(2, $this->publishing->publishChecked(self::TENANT, self::OWNER, $draft), 'nothing blocks publishing it');
        self::assertSame([], array_column($this->fit(self::OWNER)['unused'], 'field_key'));
    }

    public function testATemplateWrittenForTheFormNamesTheAnswer(): void
    {
        $id = $this->template(['submission.supplier' => 'Supplier'], recordType: 'form.supplier_request');
        $this->link($id);

        $this->call('addToTemplate', self::OWNER, ['field_keys' => ['notes']]);

        $draft = $this->source($id);
        self::assertSame([], $draft['own_fields'] ?? []);
        self::assertSame('submission.notes', $draft['blocks'][1]['content'][1]['path']);
    }

    public function testChangingATemplateNeedsTheDocumentPermission(): void
    {
        $id = $this->template(['submission.supplier' => 'Supplier']);
        $this->link($id);

        self::assertSame(403, $this->call('addToTemplate', self::AUTHOR, ['field_keys' => ['notes']])->getStatusCode());
        self::assertSame(1, $this->source($id)['draft_version']);
    }

    public function testOnlyTheFormsOwnQuestionsCanBeAdded(): void
    {
        $this->link($this->template(['submission.supplier' => 'Supplier']));

        self::assertSame(422, $this->call('addToTemplate', self::OWNER, ['field_keys' => ['nope']])->getStatusCode());
        self::assertSame(422, $this->call('addToTemplate', self::OWNER, ['field_keys' => []])->getStatusCode());
    }

    public function testATemplateMadeFromTheFormIsPublishedLinkedAndHasNoGaps(): void
    {
        $response = $this->call('createTemplate', self::OWNER, ['lang' => 'ar', 'title' => 'طلب مورد']);

        self::assertSame(201, $response->getStatusCode(), $response->getBody());
        $id = $this->data($response)['id'];
        $source = $this->source($id);
        self::assertSame(1, $source['published_version']);
        self::assertSame('form.supplier_request', $source['record_type']);
        self::assertSame('tenant', $source['scope'], 'everyone who works on the form can see it');
        self::assertSame($id, $this->form()['final_template_id']);
        $encoded = json_encode($source['blocks'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('ملاحظات', (string) $encoded, 'the labels follow the template language');

        $fit = $this->fit(self::OWNER);
        self::assertSame([], $fit['needs']);
        self::assertSame([], $fit['unused']);
    }

    public function testATemplateMadeFromTheFormNeedsThePublishPermission(): void
    {
        self::assertSame(403, $this->call('createTemplate', self::WRITER, ['lang' => 'en'])->getStatusCode());
        self::assertNull($this->form()['final_template_id']);
    }

    public function testAFormWithNoQuestionsCannotMakeATemplate(): void
    {
        $empty = $this->forms->create(self::TENANT, 'empty_form', ['en' => 'Empty'], null, null, null);

        self::assertSame(422, $this->call('createTemplate', self::OWNER, ['lang' => 'en'], $empty)->getStatusCode());
    }

    public function testLeavingAQuestionOutLastsUntilTheTemplateChanges(): void
    {
        $first = $this->template(['submission.supplier' => 'Supplier']);
        $second = $this->template(['submission.supplier' => 'Supplier']);
        $this->patchForm(['final_template_id' => $first]);

        self::assertSame(200, $this->patchForm(['document_left_out' => ['notes']])->getStatusCode());
        self::assertTrue($this->fit(self::OWNER)['unused'][0]['left_out']);

        $this->patchForm(['final_template_id' => $first]);
        self::assertTrue($this->fit(self::OWNER)['unused'][0]['left_out'], 'saving the same template changes nothing');

        $this->patchForm(['final_template_id' => $second]);
        self::assertFalse($this->fit(self::OWNER)['unused'][0]['left_out']);
    }

    public function testLeftOutMustBeAListOfKeys(): void
    {
        self::assertSame(422, $this->patchForm(['document_left_out' => 'notes'])->getStatusCode());
        self::assertSame(422, $this->patchForm(['document_left_out' => [1]])->getStatusCode());
    }

    public function testAnotherWorkspaceCannotReachTheForm(): void
    {
        self::assertSame(404, $this->call('show', self::FOREIGN, [], null, self::OTHER_TENANT)->getStatusCode());
        self::assertSame(404, $this->call('createTemplate', self::FOREIGN, ['lang' => 'en'], null, self::OTHER_TENANT)->getStatusCode());
    }

    /** @param array<string, string> $label */
    private function question(string $key, array $label): void
    {
        $this->fields->create(self::TENANT, $this->formId, $key, 'text', $label, null, false, [], [], null, null, null);
    }

    /**
     * A published template printing these fields.
     *
     * @param array<string, string> $printed path => label
     * @param list<array<string, string>>|null $own
     */
    private function template(array $printed, string $scope = 'tenant', int $by = self::OWNER, ?string $recordType = null, ?array $own = null): int
    {
        $runs = [];
        foreach ($printed as $path => $label) {
            $runs[] = ['type' => 'field', 'path' => $path, 'label' => $label];
        }
        $id = $this->sources->create(self::TENANT, [
            'kind' => 'template',
            'title' => 'Supplier letter',
            'lang' => 'en',
            'default_view' => 'page',
            'record_type' => $recordType,
            'own_fields' => $own,
            'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'content' => $runs]],
            'scope' => $scope,
            'created_by' => $by,
        ]);
        self::assertSame(1, $this->versions->publish($id, self::TENANT, 1, $by));

        return $id;
    }

    /** @return array<string, mixed> */
    private function source(int $id): array
    {
        $source = $this->sources->findById($id, self::TENANT);
        self::assertNotNull($source);

        return $source;
    }

    /** @return array<string, mixed> */
    private function form(): array
    {
        $form = $this->forms->find(self::TENANT, $this->formId);
        self::assertNotNull($form);

        return $form;
    }

    private function link(int $templateId): void
    {
        $this->forms->update(self::TENANT, $this->formId, ['final_template_id' => $templateId]);
    }

    /** @return array<string, mixed> */
    private function fit(int $caller): array
    {
        $data = $this->data($this->call('show', $caller));
        self::assertIsArray($data);

        return $data;
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, int $caller, array $body = [], ?int $formId = null, int $tenant = self::TENANT): Response
    {
        return $this->handler->{$method}($this->request($caller, $body, $tenant), ['id' => (string) ($formId ?? $this->formId)]);
    }

    /** @param array<string, mixed> $body */
    private function patchForm(array $body): Response
    {
        return $this->formsApi->update($this->request(self::OWNER, $body, self::TENANT), ['id' => (string) $this->formId]);
    }

    /** @param array<string, mixed> $body */
    private function request(int $caller, array $body, int $tenant): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenant);
        $request = new Request('POST', '/api/forms/' . $this->formId, [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->user = (object) ['profile_id' => $caller, 'active_tenant_id' => $tenant];

        return $request;
    }

    private function data(Response $response): mixed
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());

        return $decoded['data'];
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'owner', '', 1, datetime('now')),
            (102, 'author', '', 1, datetime('now')),
            (103, 'writer', '', 1, datetime('now')),
            (201, 'foreign', '', 2, datetime('now'))");
        foreach ([
            101 => ['documents:read', 'documents:write', 'documents:publish'],
            102 => ['documents:read'],
            103 => ['documents:read', 'documents:write'],
            201 => ['documents:read', 'documents:write', 'documents:publish'],
        ] as $role => $permissions) {
            foreach ($permissions as $permission) {
                $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')->execute([$permission, '']);
                $find = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
                $find->execute([$permission]);
                $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
                    ->execute([$role, (int) $find->fetchColumn()]);
            }
        }
        $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
            (10, 'owner', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (11, 'author', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (12, 'writer', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (20, 'foreign', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
            (1000, 10, 1, 101, 'active', datetime('now')),
            (1001, 11, 1, 102, 'active', datetime('now')),
            (1002, 12, 1, 103, 'active', datetime('now')),
            (2000, 20, 2, 201, 'active', datetime('now'))");

        return $pdo;
    }
}
