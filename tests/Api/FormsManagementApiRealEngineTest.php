<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormsApiHandler;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\PrefillResolver;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;

/**
 * Names and counts on responses, deleting a form nobody answered, and an author's
 * preview. Example Bakery, with invented people.
 */
final class FormsManagementApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const AUTHOR = 10;

    private PDO $pdo;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormSubmissionRepository $submissions;
    private FormsApiHandler $api;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Rana Example', 'x', false, 0, 0, NOW(), NOW())
        ");

        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->submissions = new FormSubmissionRepository($this->pdo);
        $this->api = new FormsApiHandler(
            $this->forms,
            $this->fields,
            new FormRenderer($this->fields, new PrefillResolver($this->pdo)),
            $this->submissions,
            new PublicFormLink('https://records.example.test'),
        );

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function form(string $key = 'supplier_request', int $tenantId = self::TENANT): int
    {
        $id = $this->forms->create($tenantId, $key, ['en' => 'Supplier request'], null, null, null);
        $this->fields->create($tenantId, $id, 'supplier', 'text', ['en' => 'Supplier'], null, true, [], [], null, null, 1);

        return $id;
    }

    /**
     * @param array<string, string> $params
     */
    private function delete(array $params): Response
    {
        return $this->api->delete(new Request('DELETE', '/x'), $params);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded;
    }

    public function testASubmissionCarriesThePersonsNameAndAPublicOneCarriesNone(): void
    {
        $formId = $this->form();
        $named = $this->submissions->create(self::TENANT, $formId, 1, self::AUTHOR, null, ['supplier' => 'Example Mill']);
        $anonymous = $this->submissions->create(self::TENANT, $formId, 1, null, null, ['supplier' => 'Example Dairy']);

        self::assertSame('Rana Example', ($this->submissions->find(self::TENANT, $named) ?? [])['submitted_by_name']);
        self::assertNull(($this->submissions->find(self::TENANT, $anonymous) ?? [])['submitted_by_name']);

        $listed = $this->submissions->listForTenant(self::TENANT, $formId);
        self::assertEqualsCanonicalizing(['Rana Example', null], array_column($listed, 'submitted_by_name'));
    }

    public function testTheListCarriesEachFormsResponseCount(): void
    {
        $answered = $this->form('answered');
        $unanswered = $this->form('unanswered');
        $this->submissions->create(self::TENANT, $answered, 1, null, null, ['supplier' => 'a']);
        $this->submissions->create(self::TENANT, $answered, 1, null, null, ['supplier' => 'b']);

        $data = self::body($this->api->list(new Request('GET', '/x')))['data'];
        self::assertIsArray($data);
        $counts = array_column($data, 'submission_count', 'id');

        self::assertSame(2, $counts[$answered]);
        self::assertSame(0, $counts[$unanswered]);
    }

    public function testCountsDoNotCrossWorkspaces(): void
    {
        $other = $this->form('theirs', self::OTHER_TENANT);
        $this->submissions->create(self::OTHER_TENANT, $other, 1, null, null, ['supplier' => 'a']);

        self::assertSame([], $this->submissions->countForForms(self::TENANT, [$other]));
    }

    public function testADraftNobodyAnsweredCanBeDeleted(): void
    {
        $id = $this->form();

        $response = $this->delete(['id' => (string) $id]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->forms->find(self::TENANT, $id));
    }

    public function testAnArchivedFormNobodyAnsweredCanBeDeleted(): void
    {
        $id = $this->form();
        $this->forms->transition(self::TENANT, $id, 'draft', 'published');
        $this->forms->transition(self::TENANT, $id, 'published', 'archived');

        self::assertSame(200, $this->delete(['id' => (string) $id])->getStatusCode());
    }

    public function testAPublishedFormIsRefused(): void
    {
        $id = $this->form();
        $this->forms->transition(self::TENANT, $id, 'draft', 'published');

        $response = $this->delete(['id' => (string) $id]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('form_is_published', self::body($response)['details']['reason'] ?? null);
        self::assertNotNull($this->forms->find(self::TENANT, $id));
    }

    public function testAFormWithAnAnswerIsRefusedAndTheAnswerSurvives(): void
    {
        $id = $this->form();
        $this->forms->transition(self::TENANT, $id, 'draft', 'published');
        $submission = $this->submissions->create(self::TENANT, $id, 1, null, null, ['supplier' => 'a']);
        $this->forms->transition(self::TENANT, $id, 'published', 'archived');

        $response = $this->delete(['id' => (string) $id]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('form_has_submissions', self::body($response)['details']['reason'] ?? null);
        self::assertNotNull($this->submissions->find(self::TENANT, $submission));
    }

    public function testAnotherWorkspacesFormIsNotFound(): void
    {
        $theirs = $this->form('theirs', self::OTHER_TENANT);

        self::assertSame(404, $this->delete(['id' => (string) $theirs])->getStatusCode());
        self::assertNotNull($this->forms->find(self::OTHER_TENANT, $theirs));
    }

    public function testAnAuthorCanPreviewADraftAndItSaysSo(): void
    {
        $id = $this->form();
        $this->fields->create(self::TENANT, $id, 'decision', 'text', ['en' => 'Decision'], null, false, [], [], null, null, 2, null, 'staff');

        $data = self::body($this->api->preview(new Request('GET', '/x'), ['id' => (string) $id]))['data'];

        self::assertIsArray($data);
        self::assertTrue($data['preview']);
        self::assertFalse($data['accepts_submissions']);
        // As a person filling it in would see it, so not the staff question.
        self::assertSame(['supplier'], array_column($data['fields'], 'field_key'));
    }

    public function testPreviewingAnotherWorkspacesFormIsNotFound(): void
    {
        $theirs = $this->form('theirs', self::OTHER_TENANT);

        $response = $this->api->preview(new Request('GET', '/x'), ['id' => (string) $theirs]);

        self::assertSame(404, $response->getStatusCode());
    }
}
