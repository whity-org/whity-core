<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormSubmissionWithdrawalApiHandler;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentWithdrawal;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormSubmissionReviewRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Only your own, only until somebody has decided, and the document goes with it.
 */
final class FormSubmissionWithdrawalApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const SENDER = 11;
    private const SOMEBODY_ELSE = 12;

    private PDO $pdo;
    private FormSubmissionRepository $submissions;
    private DocumentRepository $documents;
    private FormSubmissionWithdrawalApiHandler $api;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (11, 'Sami', 'x', false, 0, 0, NOW(), NOW()), (12, 'Lina', 'x', false, 0, 0, NOW(), NOW())
        ");

        $this->formId = (new FormRepository($this->pdo))
            ->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request'], null, null, null);
        $this->submissions = new FormSubmissionRepository($this->pdo);
        $this->documents = new DocumentRepository($this->pdo);
        $this->api = new FormSubmissionWithdrawalApiHandler(
            $this->pdo,
            $this->submissions,
            $this->documents,
            new DocumentWithdrawal(
                $this->pdo,
                $this->documents,
                new DocumentQrTokenRepository($this->pdo),
                new RouteRecipientRepository($this->pdo),
                new RouteEventRepository($this->pdo),
                new AuditLogger($this->pdo),
            ),
            new AuditLogger($this->pdo),
        );
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function send(?int $documentId = null, int $profileId = self::SENDER): int
    {
        return $this->submissions->create(self::TENANT, $this->formId, 1, $profileId, $documentId, ['supplier' => 'a']);
    }

    private function withdraw(int $id, int $profileId = self::SENDER): Response
    {
        $request = new Request('POST', '/x');
        $request->user = (object) ['profile_id' => $profileId];

        return $this->api->withdraw($request, ['id' => (string) $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testYourOwnIsWithdrawnAndKept(): void
    {
        $id = $this->send();

        $response = $this->withdraw($id);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->body($response)['data'];
        self::assertNotNull($data['withdrawn_at']);
        self::assertSame('withdrawn', $data['state']);
        self::assertSame(['supplier' => 'a'], $data['data'], 'the answers stay');
    }

    public function testItIsAudited(): void
    {
        $id = $this->send();

        $this->withdraw($id);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'forms.submission_withdrawn'");
        $stmt->execute();
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testSomebodyElsesIsNotFoundAndUnchanged(): void
    {
        $id = $this->send();

        self::assertSame(404, $this->withdraw($id, self::SOMEBODY_ELSE)->getStatusCode());
        self::assertNull($this->submissions->find(self::TENANT, $id)['withdrawn_at'] ?? null);
    }

    public function testAnotherWorkspacesIsNotFound(): void
    {
        $id = $this->send();
        TenantContext::reset();
        TenantContext::setTenantId(2);

        self::assertSame(404, $this->withdraw($id)->getStatusCode());
    }

    public function testTwiceIsRefused(): void
    {
        $id = $this->send();
        $this->withdraw($id);

        $response = $this->withdraw($id);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('already_withdrawn', $this->body($response)['details']['reason']);
    }

    public function testARecordedReviewBlocksIt(): void
    {
        $id = $this->send();
        (new FormSubmissionReviewRepository($this->pdo))->record(self::TENANT, $id, ['decision' => 'yes'], self::SOMEBODY_ELSE);

        $response = $this->withdraw($id);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('already_decided', $this->body($response)['details']['reason']);
        self::assertNull($this->submissions->find(self::TENANT, $id)['withdrawn_at'] ?? null);
    }

    public function testTheDocumentItProducedIsWithdrawnWithIt(): void
    {
        $documentId = $this->documents->create(self::TENANT, [
            'template_name' => 'Letter',
            'title' => 'Supplier letter',
            'created_by' => self::SENDER,
        ]);
        $id = $this->send($documentId);

        self::assertSame(200, $this->withdraw($id)->getStatusCode());

        $document = $this->documents->findById($documentId, self::TENANT);
        self::assertNotNull($document['withdrawn_at'] ?? null);
        self::assertSame(self::SENDER, (int) ($document['withdrawn_by'] ?? 0));
    }

    public function testAFinishedRouteBlocksIt(): void
    {
        $documentId = $this->documents->create(self::TENANT, [
            'template_name' => 'Letter',
            'title' => 'Supplier letter',
            'created_by' => self::SENDER,
        ]);
        $this->finishedRoute($documentId);
        $id = $this->send($documentId);

        $response = $this->withdraw($id);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('already_decided', $this->body($response)['details']['reason']);
        self::assertNull($this->documents->findById($documentId, self::TENANT)['withdrawn_at'] ?? null);
    }

    /**
     * A route with nobody left to act: what `completed` means to the state query.
     */
    private function finishedRoute(int $documentId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO document_routes (tenant_id, document_id, title, created_by, created_at)
             VALUES (:tenant_id, :document_id, 'Approval', :created_by, NOW())"
        );
        $stmt->execute([':tenant_id' => self::TENANT, ':document_id' => $documentId, ':created_by' => self::SENDER]);
    }
}
