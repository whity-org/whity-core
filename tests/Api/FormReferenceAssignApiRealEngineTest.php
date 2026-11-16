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
use Whity\Storage\LocalStorageDriver;

/**
 * WHIT-1017: an author choosing when a form's reference is given, and a
 * format that prints a value entered during review, on the migrated schema.
 */
final class FormReferenceAssignApiRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private const IRB = '{ANSWER:subject|human=IRB-AAU|animal=AUB-AAU} {SEQ}/{STEP:meeting_no}/{AY:9}';

    private FormRepository $forms;
    private FormsApiHandler $formsApi;
    private int $formId;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");

        $this->forms = new FormRepository($pdo);
        $fields = new FormFieldRepository($pdo);
        $this->formsApi = new FormsApiHandler(
            $this->forms,
            $fields,
            new FormRenderer($fields, new PrefillResolver($pdo)),
            new FormSubmissionRepository($pdo),
            new PublicFormLink('https://records.example.test'),
            new LocalStorageDriver(sys_get_temp_dir()),
        );
        $this->formId = $this->forms->create(self::TENANT, 'ethics_application', ['en' => 'Ethics application'], null, null, null);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAFormGivesItsReferenceOnSubmitUntilAnAuthorChoosesApproval(): void
    {
        self::assertSame('submit', $this->stored()['reference_assign']);

        $response = $this->patch(['reference_format' => self::IRB, 'reference_assign' => 'approval']);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'] ?? [];
        self::assertSame('approval', $data['reference_assign'] ?? null);
        self::assertSame(self::IRB, $this->stored()['reference_format']);
    }

    public function testAValueEnteredDuringReviewNeedsTheReferenceGivenOnApproval(): void
    {
        self::assertSame(422, $this->patch(['reference_format' => self::IRB])->getStatusCode());
        self::assertNull($this->stored()['reference_format'], 'nothing is written');

        self::assertSame(200, $this->patch(['reference_format' => self::IRB, 'reference_assign' => 'approval'])->getStatusCode());
        // Going back to submit while the format still prints one is refused too.
        self::assertSame(422, $this->patch(['reference_assign' => 'submit'])->getStatusCode());
        self::assertSame('approval', $this->stored()['reference_assign']);
        self::assertSame(200, $this->patch(['reference_format' => 'IRB-{SEQ}', 'reference_assign' => 'submit'])->getStatusCode());
    }

    public function testAnUnknownMomentIsRefused(): void
    {
        self::assertSame(422, $this->patch(['reference_assign' => 'later'])->getStatusCode());
        self::assertSame(422, $this->patch(['reference_assign' => true])->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $form = $this->forms->find(self::TENANT, $this->formId);
        self::assertNotNull($form);

        return $form;
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body): Response
    {
        return $this->formsApi->update(
            new Request('PATCH', '/api/forms/' . $this->formId, [], json_encode($body, JSON_THROW_ON_ERROR)),
            ['id' => (string) $this->formId],
        );
    }
}
