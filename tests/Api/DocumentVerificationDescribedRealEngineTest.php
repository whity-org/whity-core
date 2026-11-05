<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentVerificationApiHandler;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Qr\QrRevocationReason;
use Whity\Core\Document\Qr\VerificationPresenter;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;

/**
 * The `described` level of the public check (#1627): enough for a stranger to
 * compare the page with the paper in their hand ("Handover certificate · revision
 * A · 2 pages"), and still never a price, a name, the subject or the number.
 *
 * The fixture document is full of things that must not leak: a title and a
 * template snapshot naming a customer and a price, values with a price and a
 * person, and a number. Each is asserted absent from the raw body, so a field
 * added one level deeper would still be caught.
 */
final class DocumentVerificationDescribedRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const AUTHOR = 10;

    private const LEAKS = [
        'Example Bakery',
        'Layla Haddad',
        '49.000',
        'JOD',
        'HND-2026-',
        'Handover for',
        'Fatima Al-Amin',
        'key cabinet',
    ];

    private PDO $pdo;
    private int $numbered = 0;
    private SettingsService $settings;
    private DocumentQrService $qr;
    private DocumentQrTokenRepository $tokens;
    private DocumentVerificationApiHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->tokens = new DocumentQrTokenRepository($this->pdo);
        $this->qr = new DocumentQrService(
            $this->pdo,
            $this->tokens,
            new DocumentQrScanRepository($this->pdo),
            'https://records.example.test'
        );
        $this->handler = new DocumentVerificationApiHandler(
            $this->qr,
            new DocumentRepository($this->pdo),
            new RouteEventRepository($this->pdo),
            $this->settings,
            new DatabaseSharedStore($this->pdo),
            new DocumentSourceRepository($this->pdo),
            new DocumentArtifactRepository($this->pdo),
        );

        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** Off by default: a workspace that chose nothing still answers at `minimal`. */
    public function testTheDescribedLevelIsOffByDefault(): void
    {
        $token = $this->mintFor($this->raiseHandover($this->source(self::TENANT, 'Handover certificate'), pages: 2));

        $data = self::data($this->verify($token));

        self::assertSame('minimal', SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL));
        self::assertSame(['verified', 'reference', 'issuer', 'issued_on'], array_keys($data));
    }

    /**
     * The key set is asserted exactly: every field had to earn its place against a
     * stranger who may have found the paper in a bin.
     */
    public function testTheDescribedLevelSaysTheKindRevisionAndPages(): void
    {
        $this->describe();
        $token = $this->mintFor($this->raiseHandover($this->source(self::TENANT, 'Handover certificate'), pages: 2));

        $data = self::data($this->verify($token));

        self::assertSame(
            ['verified', 'reference', 'issuer', 'issued_on', 'stage', 'stage_on', 'kind', 'revision', 'pages'],
            array_keys($data),
        );
        self::assertTrue($data['verified']);
        self::assertSame('Handover certificate', $data['kind']);
        self::assertSame('A', $data['revision']);
        self::assertSame(2, $data['pages']);
        self::assertSame('issued', $data['stage']);
    }

    /** Prices, names, the subject and the number never appear, at any level. */
    public function testNoLevelShowsAPriceANameTheSubjectOrTheNumber(): void
    {
        $token = $this->mintFor($this->raiseHandover($this->source(self::TENANT, 'Handover certificate'), pages: 2));

        foreach (['undated', 'minimal', 'stage', 'described'] as $level) {
            $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, $level);
            $body = $this->verify($token)->getBody();

            self::assertStringContainsString('"verified":true', $body, "fixture: it verifies at $level");
            foreach (self::LEAKS as $leak) {
                self::assertStringNotContainsString($leak, $body, "$level must not show $leak");
            }
            self::assertStringNotContainsString('"number"', $body, "$level must not carry a number");
            self::assertStringNotContainsString('"title"', $body, "$level must not carry a title");
        }
    }

    /** No public name, no kind: never the title or the template snapshot in its place. */
    public function testATemplateWithNoPublicNameShowsNoKindAndUnknownPagesShowNone(): void
    {
        $this->describe();
        $token = $this->mintFor($this->raiseHandover($this->source(self::TENANT, null), pages: null));

        $data = self::data($this->verify($token));

        self::assertArrayNotHasKey('kind', $data);
        self::assertArrayNotHasKey('pages', $data);
        self::assertSame('A', $data['revision']);
    }

    /**
     * Tenant isolation: a document whose source id names another workspace's
     * template gets no kind from it, because the name is read in the document's
     * own tenant only.
     */
    public function testTheKindIsNeverReadFromAnotherWorkspace(): void
    {
        $this->describe();
        $foreign = $this->source(self::OTHER_TENANT, 'Secret tender award');
        $token = $this->mintFor($this->raiseHandover($foreign, pages: 2));

        $body = $this->verify($token)->getBody();

        self::assertStringNotContainsString('Secret tender award', $body);
        self::assertArrayNotHasKey('kind', self::data($this->verify($token)));
    }

    /** Pages come from this document's own artifacts, in its own tenant. */
    public function testThePageCountIsTheNewestArtifactsOfThisDocument(): void
    {
        $this->describe();
        $documentId = $this->raiseHandover($this->source(self::TENANT, 'Handover certificate'), pages: 2);
        $this->artifact($documentId, 5);
        $other = $this->raiseHandover($this->source(self::TENANT, 'Handover certificate'), pages: 9);

        self::assertSame(5, self::data($this->verify($this->mintFor($documentId)))['pages']);
        self::assertSame(9, self::data($this->verify($this->mintFor($other)))['pages']);
    }

    /**
     * A revised document's code says which revision replaced it and when, and
     * nothing that would let somebody reach the new one: no id, number or code.
     */
    public function testARevisedDocumentsCodeSaysWhichRevisionReplacedIt(): void
    {
        $this->describe();
        $source = $this->source(self::TENANT, 'Handover certificate');
        $original = $this->raiseHandover($source, pages: 2);
        $token = $this->mintFor($original);
        $revision = $this->raiseHandover($source, pages: 2, supersedes: $original, revision: 2);
        $this->tokens->revokeAllLive(self::TENANT, $original, self::AUTHOR, QrRevocationReason::SUPERSEDED);
        $newToken = $this->mintFor($revision);

        $data = self::data($this->verify($token));

        self::assertFalse($data['verified']);
        self::assertSame(QrRevocationReason::SUPERSEDED, $data['reason']);
        self::assertSame(['verified', 'reason', 'revoked_on', 'replaced_by'], array_keys($data));
        self::assertSame(['revision', 'issued_on'], array_keys($data['replaced_by']));
        self::assertSame('B', $data['replaced_by']['revision']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $data['replaced_by']['issued_on']);

        $body = $this->verify($token)->getBody();
        self::assertStringNotContainsString($newToken, $body);
        self::assertStringNotContainsString(strtoupper(substr($newToken, 0, 4)), $body, 'not even the new reference');

        self::assertSame('B', self::data($this->verify($newToken))['revision']);
    }

    /** `stage` keeps exactly what it said before: no kind, no revision, no replaced_by. */
    public function testTheStageLevelIsUnchanged(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, 'stage');
        $source = $this->source(self::TENANT, 'Handover certificate');
        $original = $this->raiseHandover($source, pages: 2);
        $token = $this->mintFor($original);
        $live = $this->mintFor($this->raiseHandover($source, pages: 2));
        $this->raiseHandover($source, pages: 2, supersedes: $original, revision: 2);
        $this->tokens->revokeAllLive(self::TENANT, $original, self::AUTHOR, QrRevocationReason::SUPERSEDED);

        self::assertSame(['verified', 'reference', 'issuer', 'issued_on', 'stage', 'stage_on'], array_keys(self::data($this->verify($live))));
        self::assertSame(['verified', 'reason', 'revoked_on'], array_keys(self::data($this->verify($token))));
    }

    /** At `minimal` a revised document's code is still indistinguishable from an unknown one. */
    public function testARevisedCodeIsStillUnrecognisedAtTheDefault(): void
    {
        $source = $this->source(self::TENANT, 'Handover certificate');
        $original = $this->raiseHandover($source, pages: 2);
        $token = $this->mintFor($original);
        $this->raiseHandover($source, pages: 2, supersedes: $original, revision: 2);
        $this->tokens->revokeAllLive(self::TENANT, $original, self::AUTHOR, QrRevocationReason::SUPERSEDED);

        self::assertSame(
            self::data($this->verify(str_repeat('0', 64))),
            self::data($this->verify($token)),
        );
    }

    /** A withdrawal wins: the organisation stands behind neither revision. */
    public function testAWithdrawnDocumentSaysWithdrawnAndNotReplaced(): void
    {
        $this->describe();
        $source = $this->source(self::TENANT, 'Handover certificate');
        $original = $this->raiseHandover($source, pages: 2);
        $token = $this->mintFor($original);
        $this->raiseHandover($source, pages: 2, supersedes: $original, revision: 2);
        $this->tokens->revokeAllLive(self::TENANT, $original, self::AUTHOR, QrRevocationReason::SUPERSEDED);
        $this->pdo->prepare('UPDATE documents SET withdrawn_at = CURRENT_TIMESTAMP WHERE id = :id AND tenant_id = :tenant')
            ->execute([':id' => $original, ':tenant' => self::TENANT]);

        $data = self::data($this->verify($token));

        self::assertSame(VerificationPresenter::REASON_DOCUMENT_WITHDRAWN, $data['reason']);
        self::assertArrayNotHasKey('replaced_by', $data);
    }

    /**
     * Where a QR points is never something a workspace types: the address comes
     * from the instance alone. No setting a workspace can change names a verify
     * page, so a workspace can't send its scans to a look-alike site.
     */
    public function testNoWorkspaceSettingChangesWhereAQrPoints(): void
    {
        foreach (SettingsRegistry::keys() as $key) {
            self::assertDoesNotMatchRegularExpression('/verify[._]?(url|link|page|base)|qr[._]?(url|link|page|base)/i', $key, "$key looks like a QR address");
        }
        self::assertStringStartsWith('https://records.example.test/', $this->qr->verificationUrl('abc'));
    }

    private function describe(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, VerificationPresenter::DETAIL_DESCRIBED);
    }

    private function source(int $tenantId, ?string $publicName): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO document_sources (tenant_id, kind, title, blocks, public_name)
             VALUES (:tenant_id, 'template', :title, :blocks, :public_name)"
        );
        $statement->execute([
            ':tenant_id' => $tenantId,
            ':title' => 'Handover for Example Bakery',
            ':blocks' => '[]',
            ':public_name' => $publicName,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function raiseHandover(int $sourceId, ?int $pages, ?int $supersedes = null, int $revision = 1): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO documents (tenant_id, template_name, title, created_by, created_at, document_source_id,
                                    variable_data, number, supersedes_id, revision)
             VALUES (:tenant_id, :template_name, :title, :created_by, CURRENT_TIMESTAMP, :source,
                     :values, :number, :supersedes, :revision)'
        );
        $statement->execute([
            ':tenant_id' => self::TENANT,
            ':template_name' => 'Handover for Example Bakery',
            ':title' => 'Handover for Example Bakery — key cabinet, JOD 49.000',
            ':created_by' => self::AUTHOR,
            ':source' => $sourceId,
            ':values' => (string) json_encode(['customer' => 'Layla Haddad', 'price' => 'JOD 49.000']),
            // A revision keeps the number of the document it replaces.
            ':number' => $supersedes !== null ? $this->numberOf($supersedes) : sprintf('HND-2026-%04d', ++$this->numbered),
            ':supersedes' => $supersedes,
            ':revision' => $revision,
        ]);
        $documentId = (int) $this->pdo->lastInsertId();
        if ($pages !== null) {
            $this->artifact($documentId, $pages);
        }

        return $documentId;
    }

    private function numberOf(int $documentId): string
    {
        $statement = $this->pdo->prepare('SELECT number FROM documents WHERE id = :id AND tenant_id = :tenant');
        $statement->execute([':id' => $documentId, ':tenant' => self::TENANT]);

        return (string) $statement->fetchColumn();
    }

    private function artifact(int $documentId, int $pages): void
    {
        (new DocumentArtifactRepository($this->pdo))->create(self::TENANT, [
            'document_id' => $documentId,
            'storage_key' => 'documents/' . $documentId . '/' . bin2hex(random_bytes(4)) . '.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 10,
            'checksum_sha256' => str_repeat('a', 64),
            'pages' => $pages,
        ]);
    }

    private function mintFor(int $documentId): string
    {
        $token = $this->qr->mint(self::TENANT, $documentId, self::AUTHOR);
        self::assertIsArray($token, 'fixture: minting must succeed');

        return (string) $token['token'];
    }

    private function verify(string $token): Response
    {
        TenantContext::reset();
        $request = new Request('GET', '/api/v1/document-verifications/' . $token, ['REMOTE_ADDR' => '198.51.100.4'], '');

        return $this->handler->verify($request, ['token' => $token]);
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Courts', 'courts'), (2, 'Other', 'other')");
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Fatima Al-Amin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        return $pdo;
    }

    /** @return array<string, mixed> */
    private static function data(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
