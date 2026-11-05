<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormSubmissionsApiHandler;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormUploadRepository;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Storage\LocalStorageDriver;

/**
 * Somebody reading what they sent holds no `forms:read`, so the route decides whose.
 */
final class MyFormSubmissionApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const SENDER = 11;
    private const OTHER = 12;

    private PDO $pdo;
    private FormSubmissionsApiHandler $api;
    private FormSubmissionRepository $submissions;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (11, 'Sami', 'x', false, 0, 0, NOW(), NOW()), (12, 'Lina', 'x', false, 0, 0, NOW(), NOW())
        ");

        $forms = new FormRepository($this->pdo);
        $fields = new FormFieldRepository($this->pdo);
        $this->formId = $forms->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request'], null, null, null);
        $fields->create(self::TENANT, $this->formId, 'supplier', 'text', ['en' => 'Supplier'], null, false, [], [], null, null, null);
        $fields->create(self::TENANT, $this->formId, 'decision', 'text', ['en' => 'Decision'], null, false, [], [], null, null, null, null, FormFieldRepository::AUDIENCE_STAFF);

        $this->submissions = new FormSubmissionRepository($this->pdo);
        $this->api = new FormSubmissionsApiHandler(
            $forms,
            $fields,
            $this->submissions,
            (new \ReflectionClass(SubmissionIssuer::class))->newInstanceWithoutConstructor(),
            new FormUploadRepository($this->pdo),
            new LocalStorageDriver(sys_get_temp_dir()),
        );
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function show(int $id, int $profileId = self::SENDER): Response
    {
        $request = new Request('GET', '/x');
        $request->user = (object) ['profile_id' => $profileId];

        return $this->api->showMine($request, ['id' => (string) $id]);
    }

    public function testYourOwnComesWithWhatYouWereAsked(): void
    {
        $id = $this->submissions->create(self::TENANT, $this->formId, 1, self::SENDER, null, ['supplier' => 'Example Mill']);

        $response = $this->show($id);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['supplier' => 'Example Mill'], $body['data']['data']);
        self::assertSame(['supplier'], array_column($body['data']['fields'], 'field_key'));
    }

    public function testAStaffAnswerIsNotShownToTheSender(): void
    {
        $id = $this->submissions->create(self::TENANT, $this->formId, 1, self::SENDER, null, ['supplier' => 'a', 'decision' => 'refused']);

        $response = $this->show($id);

        self::assertStringNotContainsString('refused', (string) $response->getBody());
    }

    public function testAFileAnswerDoesNotGiveAwayWhereItIsStored(): void
    {
        (new FormFieldRepository($this->pdo))->create(self::TENANT, $this->formId, 'licence', 'file', ['en' => 'Licence'], null, false, [], [], null, null, null);
        $id = $this->submissions->create(self::TENANT, $this->formId, 1, self::SENDER, null, ['licence' => 'tenants/1/form-uploads/1/secret.pdf']);

        $response = $this->show($id);

        self::assertStringNotContainsString('form-uploads', (string) $response->getBody());
        self::assertTrue(json_decode((string) $response->getBody(), true)['data']['data']['licence']);
    }

    public function testSomebodyElsesIsTheSameAsNoneAtAll(): void
    {
        $id = $this->submissions->create(self::TENANT, $this->formId, 1, self::SENDER, null, ['supplier' => 'a']);

        self::assertSame(404, $this->show($id, self::OTHER)->getStatusCode());
        self::assertSame(404, $this->show($id + 1000)->getStatusCode());
    }

    public function testAnotherWorkspacesIsNotFound(): void
    {
        $id = $this->submissions->create(self::TENANT, $this->formId, 1, self::SENDER, null, ['supplier' => 'a']);

        TenantContext::reset();
        TenantContext::setTenantId(2);

        self::assertSame(404, $this->show($id)->getStatusCode());
    }
}
