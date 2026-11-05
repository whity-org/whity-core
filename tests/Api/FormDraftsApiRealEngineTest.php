<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormDraftsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Form\FormDraftRepository;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;

/**
 * A draft belongs to one person and nobody else can read it. Example Bakery, invented.
 */
final class FormDraftsApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const SUBMITTER = 10;
    private const MEMBER = 11;
    private const OUTSIDER = 13;

    private PDO $pdo;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormDraftRepository $drafts;
    private FormDraftsApiHandler $api;
    private int $formId;

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
                   (13, 'Omar Example', 'x', false, 0, 0, NOW(), NOW())
        ");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (100, 'baker', '', 1, NOW())");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (200, 'baker', '', 2, NOW())");
        $this->pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
            VALUES (10, 1, 100, NULL, true, 'active', NOW()),
                   (11, 1, 100, NULL, true, 'active', NOW()),
                   (13, 2, 200, NULL, true, 'active', NOW())
        ");

        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->drafts = new FormDraftRepository($this->pdo);

        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            static fn (int $profile, string $permission): bool => $profile === self::SUBMITTER
                && $permission === CorePermissions::FORMS_SUBMIT
        );
        $this->api = new FormDraftsApiHandler($this->pdo, $this->forms, $this->fields, $this->drafts, $roles);

        $this->formId = $this->publishedForm('supplier_request');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function publishedForm(string $key): int
    {
        $id = $this->forms->create(self::TENANT, $key, ['en' => 'Supplier request'], null, null, null);
        $this->fields->create(self::TENANT, $id, 'supplier', 'text', ['en' => 'Supplier'], null, true, [], [], null, null, 1);
        $this->fields->create(self::TENANT, $id, 'paper', 'file', ['en' => 'Paper'], null, false, [], [], null, null, 2);
        $this->fields->create(self::TENANT, $id, 'decision', 'text', ['en' => 'Decision'], null, false, [], [], null, null, 3, null, 'staff');
        $this->forms->transition(self::TENANT, $id, 'draft', 'published');

        return $id;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function asCaller(int $profileId, int $tenantId = self::TENANT, ?array $body = null): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $request = new Request('PUT', '/x', [], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function save(int $profileId, array $body, ?int $formId = null): Response
    {
        return $this->api->save($this->asCaller($profileId, self::TENANT, $body), ['id' => (string) ($formId ?? $this->formId)]);
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

    public function testSavingAndReadingBack(): void
    {
        $saved = $this->save(self::SUBMITTER, ['data' => ['supplier' => 'Example Mill'], 'step' => 2]);

        self::assertSame(200, $saved->getStatusCode());
        $read = self::data($this->api->show($this->asCaller(self::SUBMITTER), ['id' => (string) $this->formId]));
        self::assertSame(['supplier' => 'Example Mill'], $read['data']);
        self::assertSame(2, $read['step']);
    }

    public function testSavingAgainReplacesRatherThanAddingARow(): void
    {
        $this->save(self::SUBMITTER, ['data' => ['supplier' => 'First']]);
        $this->save(self::SUBMITTER, ['data' => ['supplier' => 'Second']]);

        $rows = $this->drafts->listForProfile(self::TENANT, self::SUBMITTER);
        self::assertCount(1, $rows);
        self::assertSame(['supplier' => 'Second'], ($this->drafts->find(self::TENANT, self::SUBMITTER, $this->formId) ?? [])['data'] ?? null);
    }

    public function testAHalfFinishedAnswerIsAllowed(): void
    {
        // Not validated: `supplier` is required, and a draft is allowed to lack it.
        self::assertSame(200, $this->save(self::SUBMITTER, ['data' => []])->getStatusCode());
    }

    public function testFileAndStaffAnswersAndUnknownKeysAreNotKept(): void
    {
        $this->save(self::SUBMITTER, ['data' => [
            'supplier' => 'Example Mill',
            'paper' => 'tenants/1/form-uploads/7/x.pdf',
            'decision' => 'approved',
            'invented' => 'x',
        ]]);

        self::assertSame(
            ['supplier' => 'Example Mill'],
            ($this->drafts->find(self::TENANT, self::SUBMITTER, $this->formId) ?? [])['data'] ?? null,
        );
    }

    public function testATooLargeDraftIsRefused(): void
    {
        $response = $this->save(self::SUBMITTER, ['data' => ['supplier' => str_repeat('x', 300 * 1024)]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->drafts->find(self::TENANT, self::SUBMITTER, $this->formId));
    }

    public function testNobodyElseCanReadOrChangeADraft(): void
    {
        $this->forms->update(self::TENANT, $this->formId, []);
        $this->forms->setOpenToMembers(self::TENANT, $this->formId, true, self::SUBMITTER);
        $this->save(self::SUBMITTER, ['data' => ['supplier' => 'Mine']]);

        // Another person who may fill the same form in gets their own, empty one.
        $theirs = $this->api->show($this->asCaller(self::MEMBER), ['id' => (string) $this->formId]);
        self::assertSame(200, $theirs->getStatusCode());
        self::assertStringContainsString('"data":null', (string) $theirs->getBody());

        $this->save(self::MEMBER, ['data' => ['supplier' => 'Theirs']]);
        self::assertSame(['supplier' => 'Mine'], ($this->drafts->find(self::TENANT, self::SUBMITTER, $this->formId) ?? [])['data'] ?? null);

        $this->api->delete($this->asCaller(self::MEMBER), ['id' => (string) $this->formId]);
        self::assertNotNull($this->drafts->find(self::TENANT, self::SUBMITTER, $this->formId));
    }

    public function testAMemberMayOnlyDraftAFormOpenedToThem(): void
    {
        self::assertSame(404, $this->save(self::MEMBER, ['data' => ['supplier' => 'x']])->getStatusCode());

        $this->forms->setOpenToMembers(self::TENANT, $this->formId, true, self::SUBMITTER);

        self::assertSame(200, $this->save(self::MEMBER, ['data' => ['supplier' => 'x']])->getStatusCode());
    }

    public function testAnotherWorkspaceCannotReachIt(): void
    {
        $this->forms->setOpenToMembers(self::TENANT, $this->formId, true, self::SUBMITTER);

        $request = $this->asCaller(self::OUTSIDER, self::OTHER_TENANT, ['data' => ['supplier' => 'x']]);

        self::assertSame(404, $this->api->save($request, ['id' => (string) $this->formId])->getStatusCode());
    }

    public function testADraftOnAFormThatClosedIsNotOffered(): void
    {
        $this->save(self::SUBMITTER, ['data' => ['supplier' => 'x']]);
        $this->forms->transition(self::TENANT, $this->formId, 'published', 'archived');

        self::assertSame([], self::data($this->api->list($this->asCaller(self::SUBMITTER))));
        self::assertSame(404, $this->api->show($this->asCaller(self::SUBMITTER), ['id' => (string) $this->formId])->getStatusCode());

        // The person can still remove what they typed.
        self::assertSame(200, $this->api->delete($this->asCaller(self::SUBMITTER), ['id' => (string) $this->formId])->getStatusCode());
        self::assertNull($this->drafts->find(self::TENANT, self::SUBMITTER, $this->formId));
    }

    public function testTheListNamesTheFormAndHidesWhatIsNoLongerOpenToAMember(): void
    {
        $this->forms->setOpenToMembers(self::TENANT, $this->formId, true, self::SUBMITTER);
        $this->save(self::MEMBER, ['data' => ['supplier' => 'x'], 'step' => 1]);

        $listed = self::data($this->api->list($this->asCaller(self::MEMBER)));
        self::assertSame(
            [['form_id' => $this->formId, 'form_name' => ['en' => 'Supplier request'], 'step' => 1]],
            array_map(static fn (array $row): array => array_diff_key($row, ['updated_at' => true]), $listed),
        );

        $this->forms->setOpenToMembers(self::TENANT, $this->formId, false, null);

        self::assertSame([], self::data($this->api->list($this->asCaller(self::MEMBER))));
    }
}
