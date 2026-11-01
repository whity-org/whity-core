<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use Database\Migrations\SeedGuidelineDocumentStarters;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceStarterSeeder;
use Whity\Core\Document\Print\DocumentPublishCheck;
use Whity\Core\Document\Print\PrintLabels;
use Whity\Core\Document\Print\WhityDocumentPrinter;
use Whity\Database\Database;

/**
 * The guideline's 13 document templates as starters (WHIT-843;
 * docs/design/16-documents.md). Runs on SQLite by default and on PostgreSQL
 * when PHPUNIT_PG_DSN is set.
 */
final class GuidelineStartersRealEngineTest extends TestCase
{
    private const DOCUMENTS = [
        'editor.quote', 'editor.invoice', 'editor.credit-note', 'editor.receipt',
        'editor.statement', 'editor.payment-reminder', 'editor.delivery-note',
        'editor.payslip', 'editor.employment-letter', 'editor.leave-approval',
        'editor.report', 'editor.letter', 'editor.purchase-order',
    ];

    /** Company and Today fields; own fields are checked per starter. */
    private const SHARED_PATHS = ['company.name' => true, 'company.address' => true, 'company.tax_number' => true, 'today' => true];

    private PDO $pdo;
    private Database $db;
    private DocumentSourceStarterSeeder $seeder;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->db = Database::withFactory(fn (): PDO => $this->pdo);
        $this->db->setMaxLifetimeSeconds(86400);
        $this->db->setPingIntervalSeconds(86400);
        $this->db->forceConnect();
        $this->seeder = new DocumentSourceStarterSeeder(new DocumentSourceRepository($this->pdo));
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Clinic', 'clinic')");
    }

    public function testEveryDocumentIsAStarterInEnglishAndInArabic(): void
    {
        $starters = $this->seeder->starters();

        foreach (self::DOCUMENTS as $key) {
            self::assertArrayHasKey($key, $starters);
            self::assertArrayHasKey($key . '.ar', $starters);
            self::assertSame('en', $starters[$key]['lang'] ?? null);
            self::assertSame('ar', $starters[$key . '.ar']['lang'] ?? null);
            self::assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $starters[$key]['title']);
            self::assertMatchesRegularExpression('/\p{Arabic}/u', $starters[$key . '.ar']['title'], $key);
        }
        self::assertSame('Tax invoice', $starters['editor.invoice']['title']);
        self::assertSame('A5', $starters['editor.receipt']['page']['size'], 'the receipt is A5 (section 16)');
    }

    public function testEveryStarterWouldPublish(): void
    {
        foreach ($this->seeder->starters() as $key => $starter) {
            $paths = self::SHARED_PATHS;
            foreach ($starter['own_fields'] as $field) {
                $paths['template.' . $field['key']] = true;
            }
            self::assertSame([], DocumentPublishCheck::blockers($starter['blocks'], $paths), $key);
            self::assertLessThanOrEqual(50, count($starter['own_fields']), "{$key} fits the draft API's limit");
        }
    }

    public function testAnArabicStarterIsWrittenInArabic(): void
    {
        foreach ($this->seeder->starters() as $key => $starter) {
            if (!str_ends_with($key, '.ar')) {
                continue;
            }
            foreach ($starter['own_fields'] as $field) {
                self::assertMatchesRegularExpression('/\p{Arabic}/u', $field['label'], "{$key}: {$field['key']}");
            }
        }
    }

    /**
     * A published template mustn't print the example: Example Bakery's figures
     * are samples on fields, never typed into the document's text.
     */
    public function testExampleDataIsOnlyEverASample(): void
    {
        foreach (self::DOCUMENTS as $document) {
            foreach ([$document, $document . '.ar'] as $key) {
                $text = $this->plainText($this->seeder->starters()[$key]['blocks']);
                self::assertStringNotContainsString('Example', $text, $key);
                self::assertStringNotContainsString('INV-', $text, $key);
                self::assertDoesNotMatchRegularExpression('/\d+\.\d{3}/', $text, "{$key}: an amount is a field");
            }
        }
    }

    public function testNothingClaimsToBeJoFotara(): void
    {
        foreach ($this->seeder->starters() as $key => $starter) {
            self::assertStringNotContainsStringIgnoringCase('jofotara', (string) json_encode($starter, JSON_UNESCAPED_UNICODE), $key);
            self::assertStringNotContainsString('جوفوترا', (string) json_encode($starter, JSON_UNESCAPED_UNICODE), $key);
        }
    }

    public function testEveryStarterPrintsWithItsSamples(): void
    {
        foreach ($this->seeder->starters() as $key => $starter) {
            if ($starter['kind'] === 'label') {
                continue;
            }
            $values = ['today' => '27 Oct 2026', 'company.name' => 'Example Bakery', 'company.address' => '[Street], Amman', 'company.tax_number' => '[000000000]'];
            foreach ($starter['own_fields'] as $field) {
                $values['template.' . $field['key']] = $field['sample'] ?? '';
            }
            $printer = new WhityDocumentPrinter($values, [], static fn (): ?array => null, static fn (): ?array => null, static fn (): ?string => null, new PrintLabels());
            $payload = $printer->payload(
                ['title' => $starter['title'], 'lang' => $starter['lang'] ?? 'en', 'page' => $starter['page'], 'blocks' => $starter['blocks']],
                ['name' => 'Example Bakery', 'lines' => ['[Street], Amman']],
                null,
                '27 Oct 2026',
            );
            self::assertSame(($starter['lang'] ?? 'en') === 'ar' ? 'rtl' : 'ltr', $payload['direction'], $key);
            self::assertSame([], $printer->missingRequired($starter['blocks']), $key);
            self::assertGreaterThan(1, count($payload['content']), $key);
            if (($starter['page']['size'] ?? 'A4') === 'A5') {
                self::assertSame(148.0, $payload['page']['widthMm'], $key);
                self::assertSame(210.0, $payload['page']['heightMm'], $key);
            }
        }
    }

    public function testAmountsAreEndAlignedInPrint(): void
    {
        $starter = $this->seeder->starters()['editor.invoice'];
        $printer = new WhityDocumentPrinter([], [], static fn (): ?array => null, static fn (): ?array => null, static fn (): ?string => null, new PrintLabels());
        $payload = $printer->payload(['title' => $starter['title'], 'lang' => 'en', 'page' => $starter['page'], 'blocks' => $starter['blocks']], null, null, null);

        $tables = array_values(array_filter($payload['content'], static fn (array $b): bool => $b['type'] === 'table'));
        self::assertSame(['start', 'end', 'end', 'end'], $tables[1]['align']);
    }

    public function testANewWorkspaceGetsEveryStarter(): void
    {
        $this->seeder->provisionTenant(1, 'Example Bakery');

        self::assertSame(count($this->seeder->starters()), $this->starterCount(1));
        self::assertSame(0, $this->seeder->seedForTenant(1), 'seeding twice adds nothing');
    }

    public function testTheUpgradeAddsTheNewStartersAndRespectsTheWorkspacesChoices(): void
    {
        $this->seedAsBefore(1);
        // Workspace 1 changed its Letter, published its Invoice and deleted its Exam sheet.
        $this->pdo->exec("UPDATE document_sources SET title = 'Our letter', draft_version = 2 WHERE tenant_id = 1 AND starter_key = 'editor.letter'");
        $this->pdo->exec("UPDATE document_sources SET published_version = 1 WHERE tenant_id = 1 AND starter_key = 'editor.invoice'");
        $this->pdo->exec("DELETE FROM document_sources WHERE tenant_id = 1 AND starter_key = 'editor.exam'");
        // Workspace 2 never touched anything.
        $this->seedAsBefore(2);

        SeedGuidelineDocumentStarters::up($this->db);

        self::assertSame('Our letter', $this->title(1, 'editor.letter'), "a changed copy is the workspace's");
        self::assertSame('Old invoice', $this->title(1, 'editor.invoice'), 'a published copy is left alone');
        self::assertNull($this->title(1, 'editor.exam'), 'a deleted starter stays deleted');
        self::assertSame('Tax invoice', $this->title(1, 'editor.quote') === null ? null : $this->title(2, 'editor.invoice'), 'an untouched copy reaches the guideline');
        self::assertSame('Letter', $this->title(2, 'editor.letter'));
        self::assertSame('Tax invoice', $this->title(2, 'editor.invoice'));
        self::assertSame('فاتورة ضريبية', $this->title(1, 'editor.invoice.ar'));
        self::assertSame('سند قبض', $this->title(2, 'editor.receipt.ar'));
        self::assertSame(count($this->seeder->starters()), $this->starterCount(2));

        $before = $this->starterCount(1);
        SeedGuidelineDocumentStarters::up($this->db);
        self::assertSame($before, $this->starterCount(1), 'running again adds nothing');
    }

    public function testDownRemovesOnlyUntouchedNewStarters(): void
    {
        $this->seedAsBefore(1);
        SeedGuidelineDocumentStarters::up($this->db);
        $this->pdo->exec("UPDATE document_sources SET draft_version = 2 WHERE tenant_id = 1 AND starter_key = 'editor.quote'");

        SeedGuidelineDocumentStarters::down($this->db);

        self::assertNotNull($this->title(1, 'editor.quote'), 'a changed starter stays');
        self::assertNull($this->title(1, 'editor.receipt'));
        self::assertNotNull($this->title(1, 'editor.invoice'));
    }

    /** A workspace as it was before this migration: the six older starters only. */
    private function seedAsBefore(int $tenantId): void
    {
        $this->pdo->exec("DELETE FROM document_sources WHERE tenant_id = {$tenantId}");
        $this->seeder->seedForTenant($tenantId, true, ['editor.exam', 'editor.production-note', 'editor.shipping-labels', 'editor.certificate']);
        $stmt = $this->pdo->prepare(
            "INSERT INTO document_sources (tenant_id, kind, title, lang, default_view, blocks, draft_version, scope, starter_key, created_at, updated_at)
             VALUES (:tenant_id, :kind, :title, 'en', 'page', '[]', 1, 'system', :key, NOW(), NOW())"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':kind' => 'letter', ':title' => 'Old letter', ':key' => 'editor.letter']);
        $stmt->execute([':tenant_id' => $tenantId, ':kind' => 'template', ':title' => 'Old invoice', ':key' => 'editor.invoice']);
    }

    private function title(int $tenantId, string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT title FROM document_sources WHERE tenant_id = :t AND starter_key = :k');
        $stmt->execute([':t' => $tenantId, ':k' => $key]);
        $title = $stmt->fetchColumn();

        return $title === false ? null : (string) $title;
    }

    private function starterCount(int $tenantId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM document_sources WHERE tenant_id = :t AND starter_key IS NOT NULL');
        $stmt->execute([':t' => $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every typed text node in the blocks, never a field's sample.
     *
     * @param array<mixed> $node
     */
    private function plainText(array $node): string
    {
        $out = (($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) ? $node['text'] . ' ' : '';
        foreach ($node as $value) {
            if (is_array($value)) {
                $out .= $this->plainText($value);
            }
        }

        return $out;
    }
}
