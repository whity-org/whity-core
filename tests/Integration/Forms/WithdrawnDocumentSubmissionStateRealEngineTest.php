<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormSubmissionWithdrawalApiHandler;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentWithdrawal;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\Print\FormPrintoutService;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * #2204: withdrawing the document a form produced (`POST
 * /api/v1/documents/{id}/withdraw`) withdraws the submission with it. Its open
 * items close with a verdict-less row, which the state query used to read as a
 * finished route: the submission said `completed` and printed as Approved.
 */
final class WithdrawnDocumentSubmissionStateRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const SENDER = 11;
    private const REVIEWER = 12;

    private PDO $pdo;
    private FormSubmissionRepository $submissions;
    private DocumentRepository $documents;
    private DocumentWithdrawal $withdrawal;
    private int $submissionId;
    private int $documentId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (11, 'Sami', 'x', false, 0, 0, NOW(), NOW()), (12, 'Lina', 'x', false, 0, 0, NOW(), NOW())
        ");
        TenantContext::setTenantId(self::TENANT);

        $formId = (new FormRepository($this->pdo))
            ->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request'], null, null, null);
        $this->submissions = new FormSubmissionRepository($this->pdo);
        $this->documents = new DocumentRepository($this->pdo);
        $this->withdrawal = new DocumentWithdrawal(
            $this->pdo,
            $this->documents,
            new DocumentQrTokenRepository($this->pdo),
            new RouteRecipientRepository($this->pdo),
            new RouteEventRepository($this->pdo),
        );

        $this->documentId = $this->documents->create(self::TENANT, [
            'template_name' => 'Letter',
            'title' => 'Supplier letter',
            'created_by' => self::SENDER,
        ]);
        $this->submissionId = $this->submissions->create(self::TENANT, $formId, 1, self::SENDER, $this->documentId, ['supplier' => 'a']);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAnApprovedSubmissionReadsAsWithdrawnNotCompleted(): void
    {
        $this->route(open: false);
        self::assertSame('completed', $this->state());

        $this->withdrawDocument();

        self::assertSame('withdrawn', $this->state());
    }

    public function testOneStillInReviewReadsAsWithdrawn(): void
    {
        $this->route(open: true);
        self::assertSame('in progress', $this->state());

        $this->withdrawDocument();

        self::assertSame('withdrawn', $this->state());
    }

    public function testItsAuthorSeesItAsWithdrawn(): void
    {
        $this->route(open: false);
        $this->withdrawDocument();

        $row = $this->submissions->find(self::TENANT, $this->submissionId, self::SENDER);
        self::assertNotNull($row);
        self::assertSame('withdrawn', $row['state']);
    }

    public function testItPrintsAsWithdrawnNotApproved(): void
    {
        $this->route(open: false);
        self::assertSame(['Approved', 'Approved'], $this->decisionWords(), 'before: an approved paper prints as approved');

        $this->withdrawDocument();

        // The decision line says so; no decision block, as nothing stands decided.
        self::assertSame(['Withdrawn'], $this->decisionWords());
    }

    /** @return list<string> */
    private function decisionWords(): array
    {
        $payload = $this->printouts()->payload(self::TENANT, $this->submissionId, ['lang' => 'en']);
        self::assertNotNull($payload);
        self::assertIsArray($payload['content'] ?? null);

        $words = [];
        foreach ($payload['content'] as $block) {
            if (is_array($block) && in_array($block['type'] ?? null, ['decisionLine', 'decisionBlock'], true)) {
                $words[] = (string) ($block['word'] ?? '');
            }
        }

        return $words;
    }

    public function testItsAuthorIsToldItIsAlreadyWithdrawn(): void
    {
        $this->route(open: false);
        $this->withdrawDocument();

        $api = new FormSubmissionWithdrawalApiHandler($this->pdo, $this->submissions, $this->documents, $this->withdrawal);
        $request = new Request('POST', '/x');
        $request->user = (object) ['profile_id' => self::SENDER];
        $response = $api->withdraw($request, ['id' => (string) $this->submissionId]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('already_withdrawn', $body['details']['reason']);
    }

    private function withdrawDocument(): void
    {
        $document = $this->documents->findById($this->documentId, self::TENANT);
        self::assertNotNull($document);
        self::assertNotNull($this->withdrawal->withdraw(self::TENANT, self::REVIEWER, $document));
    }

    private function state(): string
    {
        return (string) ($this->submissions->find(self::TENANT, $this->submissionId)['state'] ?? '');
    }

    private function printouts(): FormPrintoutService
    {
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));

        return new FormPrintoutService(
            $this->pdo,
            new SourceDocumentPrinter(new DocumentFieldRegistry(), new DocumentEmbedRegistry(), $settings),
            null,
            $settings,
        );
    }

    /**
     * The submission's route (WHIT-920), naming the document it produced. Open:
     * the reviewer still has it. Finished: the reviewer approved it.
     */
    private function route(bool $open): void
    {
        $doc = $this->documentId;
        $sub = $this->submissionId;
        $this->pdo->exec("INSERT INTO document_routes (tenant_id, document_id, subject_type, subject_id, title, created_by, created_at) VALUES (1, {$doc}, 'form_submission', {$sub}, 'Approval', " . self::SENDER . ', CURRENT_TIMESTAMP)');
        $route = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO document_route_steps (tenant_id, route_id, position, rule_kind, rule_config) VALUES (1, {$route}, 1, 'explicit', '{}')");
        $step = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO document_route_events (tenant_id, document_id, route_id, step_id, actor_profile_id, action, occurred_at) VALUES (1, {$doc}, {$route}, {$step}, " . self::SENDER . ", 'issued', CURRENT_TIMESTAMP)");
        $issued = (int) $this->pdo->lastInsertId();
        $closedBy = 'NULL';
        if (!$open) {
            $this->pdo->exec("INSERT INTO document_route_events (tenant_id, document_id, route_id, step_id, actor_profile_id, action, verdict, occurred_at) VALUES (1, {$doc}, {$route}, {$step}, " . self::REVIEWER . ", 'acknowledged', 'approved', CURRENT_TIMESTAMP)");
            $closedBy = (string) (int) $this->pdo->lastInsertId();
        }
        $this->pdo->exec("INSERT INTO document_route_recipients (tenant_id, document_id, route_id, step_id, profile_id, created_by_event_id, closed_by_event_id) VALUES (1, {$doc}, {$route}, {$step}, " . self::REVIEWER . ", {$issued}, {$closedBy})");
    }
}
