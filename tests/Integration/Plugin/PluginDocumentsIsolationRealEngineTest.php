<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Plugin\HostPluginDocuments;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Documents\Documents;
use Whity\Sdk\Documents\IssueRequest;
use Whity\Sdk\Documents\IssueResult;
use Whity\Sdk\Documents\PluginDocumentStore;
use Whity\Sdk\Documents\TemplateRef;
use Whity\Sdk\PluginInterface;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * WHAT WAS PROVEN (WHIT-886, SDK 1.63): a plugin's QR tracking and scan history
 * answer only for documents that plugin issued. Plugin B cannot read plugin A's
 * tracking; a person's document reads as absent to a plugin; tenant 2 cannot read
 * tenant 1's; and origin keys are namespaced per plugin, so a replay can only
 * return the plugin's own document.
 *
 * The documents service is a fake that writes real `documents` rows, so the
 * ownership link has a real document to point at. The QR service, its token and
 * scan repositories, and the settings are the real ones.
 */
final class PluginDocumentsIsolationRealEngineTest extends TestCase
{
    private const PUBLIC_BASE = 'https://records.example.test';

    private PDO $pdo;
    private FakeDocumentIssuer $issuer;
    private DocumentQrService $qr;
    private SettingsService $settings;
    private int $tenant = 1;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'One', 'one')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Two', 'two')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Fatima Al-Amin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');

        $this->qr = new DocumentQrService(
            $this->pdo,
            new DocumentQrTokenRepository($this->pdo),
            new DocumentQrScanRepository($this->pdo),
            self::PUBLIC_BASE,
        );
        $this->issuer = new FakeDocumentIssuer($this->pdo);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAPluginsIssuedDocumentIsRecordedAsItsOwnAndItsOriginKeyIsNamespaced(): void
    {
        $reports = $this->store('Acme\\Reports');

        $issued = $reports->issue($this->request('order:1'));

        $this->assertSame('plugin:reports:order:1', $this->issuer->lastOriginKey);
        $this->assertSame(1, $issued->documentId);
        $this->assertNotNull($reports->tracking($issued->documentId));
    }

    public function testAnotherPluginCannotReadTheTrackingOfADocumentItDidNotIssue(): void
    {
        $reportsDoc = $this->store('Acme\\Reports')->issue($this->request('order:1'))->documentId;
        $ledger = $this->store('Acme\\Ledger');

        $this->assertNull($ledger->tracking($reportsDoc));
        $this->assertNull($ledger->qrVerificationUrl($reportsDoc));
    }

    public function testAPlainDocumentIssuedByAPersonReadsAsAbsentToAPlugin(): void
    {
        $personDoc = $this->issuer->insertPersonDocument($this->tenant);

        $this->assertNull($this->store('Acme\\Reports')->tracking($personDoc));
        $this->assertNull($this->store('Acme\\Reports')->qrVerificationUrl($personDoc));
    }

    public function testTheSameOriginKeyFromTwoPluginsIsTwoDocuments(): void
    {
        $a = $this->store('Acme\\Reports')->issue($this->request('order:1'))->documentId;
        $b = $this->store('Acme\\Ledger')->issue($this->request('order:1'))->documentId;

        $this->assertNotSame($a, $b);
    }

    public function testReissuingWithTheSameOriginKeyReturnsTheSameDocument(): void
    {
        $reports = $this->store('Acme\\Reports');

        $first = $reports->issue($this->request('order:1'));
        $again = $reports->issue($this->request('order:1'));

        $this->assertSame($first->documentId, $again->documentId);
        $this->assertTrue($again->replayed);
    }

    public function testTheVerificationUrlIsTheSameOnEveryCallAndNeverRetiresACode(): void
    {
        $reports = $this->store('Acme\\Reports');
        $doc = $reports->issue($this->request('order:1'))->documentId;

        $first = $reports->qrVerificationUrl($doc);
        $second = $reports->qrVerificationUrl($doc);

        $this->assertIsString($first);
        $this->assertStringStartsWith(self::PUBLIC_BASE, (string) $first);
        $this->assertSame($first, $second);
    }

    public function testTrackingReportsTheCodeAndItsScansForTheOwningPluginOnly(): void
    {
        $reports = $this->store('Acme\\Reports');
        $doc = $reports->issue($this->request('order:1'))->documentId;
        $reports->qrVerificationUrl($doc);
        $token = $this->qr->active($this->tenant, $doc);
        $this->assertNotNull($token);
        $this->assertTrue($this->qr->recordScan($token, null));

        $tracking = $reports->tracking($doc);

        $this->assertNotNull($tracking);
        $this->assertTrue($tracking->codeActive);
        $this->assertSame(1, $tracking->scanCount);
        $this->assertCount(1, $tracking->scans);
        $this->assertArrayHasKey('scanned_at', $tracking->scans[0]);
        $this->assertArrayHasKey('outcome', $tracking->scans[0]);
        $this->assertArrayNotHasKey('scanner_profile_id', $tracking->scans[0]);
    }

    public function testAnotherTenantCannotReachAPluginsDocument(): void
    {
        $doc = $this->store('Acme\\Reports')->issue($this->request('order:1'))->documentId;

        $this->tenant = 2;
        TenantContext::reset();
        TenantContext::setTenantId(2);

        $this->assertNull($this->store('Acme\\Reports')->tracking($doc));
        $this->assertNull($this->store('Acme\\Reports')->qrVerificationUrl($doc));
    }

    public function testWithQrSwitchedOffTheVerificationUrlIsNullButIssuingStillWorks(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'false');
        $reports = $this->store('Acme\\Reports');
        $doc = $reports->issue($this->request('order:1'))->documentId;

        $this->assertNull($reports->qrVerificationUrl($doc));
        $this->assertGreaterThan(0, $doc);
    }

    private function store(string $pluginName): PluginDocumentStore
    {
        TenantContext::reset();
        TenantContext::setTenantId($this->tenant);

        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn($pluginName);

        $service = new HostPluginDocuments(
            $this->pdo,
            fn (): Documents => $this->issuer,
            $this->qr,
            new DocumentQrScanRepository($this->pdo),
            $this->settings,
        );

        return $service->for($plugin);
    }

    private function request(string $originKey): IssueRequest
    {
        return new IssueRequest(TemplateRef::source(7), null, [], null, $originKey, null);
    }
}

/**
 * Stands in for the workspace's documents service. It writes a real `documents`
 * row and answers an origin key it has seen before with that same document,
 * as the real service does.
 */
final class FakeDocumentIssuer implements Documents
{
    public ?string $lastOriginKey = null;

    /** @var array<string, int> */
    private array $byOriginKey = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function canIssue(): bool
    {
        return true;
    }

    public function preflight(IssueRequest $request): \Whity\Sdk\Documents\PreflightResult
    {
        $this->lastOriginKey = $request->originKey;

        return new \Whity\Sdk\Documents\PreflightResult(true, []);
    }

    public function issue(IssueRequest $request): IssueResult
    {
        $this->lastOriginKey = $request->originKey;
        $tenantId = (int) TenantContext::getTenantId();

        if ($request->originKey !== null && isset($this->byOriginKey[$request->originKey])) {
            return new IssueResult($this->byOriginKey[$request->originKey], 'Invoice', true, null);
        }

        $id = $this->insert($tenantId, 'Invoice');
        if ($request->originKey !== null) {
            $this->byOriginKey[$request->originKey] = $id;
        }

        return new IssueResult($id, 'Invoice', false, null);
    }

    public function insertPersonDocument(int $tenantId): int
    {
        return $this->insert($tenantId, 'Person-issued');
    }

    private function insert(int $tenantId, string $title): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO documents (tenant_id, template_name, title, created_by, created_at)
             VALUES (:tenant_id, :template_name, :title, :created_by, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            ':tenant_id' => $tenantId,
            ':template_name' => 'Template',
            ':title' => $title,
            ':created_by' => 10,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
