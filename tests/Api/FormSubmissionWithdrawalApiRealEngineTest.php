<?php

declare(strict_types=1);

namespace Tests\Api;

use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Hooks\Events;
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

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $fired = [];

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
        $hooks = new HookManager();
        $this->recordOn($hooks, Events::SUBMISSION_WITHDRAWN);
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
            hooks: $hooks,
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

    public function testItIsAnnouncedWithIdsOnly(): void
    {
        $id = $this->send();

        $this->withdraw($id);

        $fired = $this->firedOf(Events::SUBMISSION_WITHDRAWN);
        self::assertCount(1, $fired);
        self::assertSame($id, $fired[0]['id']);
        self::assertSame($this->formId, $fired[0]['form_id']);
        self::assertSame(self::SENDER, $fired[0]['actor_profile_id']);
        self::assertArrayNotHasKey('data', $fired[0]);
    }

    public function testSomebodyElsesWithdrawalAnnouncesNothing(): void
    {
        $this->withdraw($this->send(), self::SOMEBODY_ELSE);

        self::assertSame([], $this->firedOf(Events::SUBMISSION_WITHDRAWN));
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
}
