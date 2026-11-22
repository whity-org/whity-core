<?php

declare(strict_types=1);

namespace Tests\Integration\Sales;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Issuing\TemplateIssuing;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Sales\Print\QuoteDocuments;
use Whity\Core\Sales\Print\QuoteFieldProvider;
use Whity\Core\Sales\Print\QuoteStarter;
use Whity\Core\Sales\QuoteConversions;
use Whity\Core\Sales\Quotes;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\SequenceCounters;

/**
 * Printing a quote against the real schema (design section 34 §3.5,
 * WHIT-1341): what a quote prints, read with the reader's permission and only
 * in its own workspace; its link only in the copy issued while it is known;
 * the starter only names fields the provider fills; and the template is
 * seeded once per workspace and language, published.
 *
 * Example Bakery (tenant 1) quotes Example Café two birthday cakes
 * (كعكة عيد ميلاد) at $45.00 with 10% off, and an hour and a half of
 * decorating, at 16% sales tax.
 */
final class QuotePrintRealEngineTest extends TestCase
{
    private const AMRO = 11;

    private PDO $pdo;
    private Quotes $quotes;
    private int $quoteId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec("INSERT INTO contacts (id, tenant_id, name, is_customer, is_supplier, tax_number, email) VALUES (41, 1, 'Example Café', true, false, '87654321', 'orders@example.com')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $hooks = new HookManager();
        $items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $hooks);
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->quotes = new Quotes(
            $db, $workspace, $items, $units, new SequenceCounters($this->pdo), new QuoteConversions(),
            static fn (int $t): array => $settings->effective($t), static fn (int $t): string => 'USD', $hooks, null,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-21'),
        );
        $cake = $items->create(1, [
            'name' => ['en' => 'Birthday cake', 'ar' => 'كعكة عيد ميلاد'], 'unit' => 'pc', 'sku' => 'CAKE-B', 'sale_price_minor' => 4500,
        ], self::AMRO)['variants'][0]['id'];

        $this->quoteId = $this->quotes->create(1, self::AMRO, [
            'contact_id' => 41,
            'terms' => 'Half on order, half on delivery.',
            'lines' => [
                ['variant_id' => $cake, 'quantity' => '2', 'tax_bp' => 1600, 'discount_bp' => 1000, 'description' => ''],
                ['description' => 'Decorating, per hour', 'quantity' => '1.5', 'price_minor' => 2000, 'tax_bp' => 1600],
            ],
        ])['quote']['id'];
    }

    public function testADraftPrintsItsCustomerLinesAndTotalsWithoutANumberOrLink(): void
    {
        $quote = (new QuoteFieldProvider($this->pdo))->values(1, self::AMRO, self::allowed(), (string) $this->quoteId);

        self::assertNotNull($quote);
        $v = $quote['values'];
        self::assertSame('Draft quote · Example Café', $quote['label']);
        self::assertNull($v['quote.number'], 'no number until it is sent');
        self::assertSame('1', $v['quote.revision']);
        self::assertSame('Example Café', $v['quote.customer']);
        self::assertSame('87654321', $v['quote.customer_tax_number']);
        self::assertSame('orders@example.com', $v['quote.customer_email']);
        self::assertSame('21 December 2026', $v['quote.valid_until']);
        self::assertSame('21 كانون الأول 2026', $v['quote.valid_until_ar']);
        self::assertSame('Half on order, half on delivery.', $v['quote.terms']);
        self::assertSame('Amro Saleh', $v['quote.prepared_by']);
        self::assertNull($v['quote.link'], 'a copy made outside sending has no link');
        self::assertNotNull($v['quote.total_words_ar']);

        self::assertSame('2', $v['quote.lines.count']);
        self::assertSame('Birthday cake', $v['quote.lines.1.item'], 'an item line with no words of its own prints the item');
        self::assertSame('كعكة عيد ميلاد', $v['quote.lines.1.item_ar']);
        self::assertSame('CAKE-B', $v['quote.lines.1.sku']);
        self::assertSame('2 pc', $v['quote.lines.1.quantity'], 'quantity before unit');
        self::assertSame('$45.00', $v['quote.lines.1.unit_price']);
        self::assertSame('10%', $v['quote.lines.1.discount']);
        self::assertSame('Decorating, per hour', $v['quote.lines.2.item']);
        self::assertSame('Decorating, per hour', $v['quote.lines.2.item_ar'], 'a free line prints its own words in both');
        self::assertNull($v['quote.lines.2.discount']);
    }

    public function testTheLinkPrintsOnlyInTheCopyIssuedWhileItIsKnown(): void
    {
        $this->quotes->send(1, $this->quoteId, self::AMRO);
        $provider = new QuoteFieldProvider($this->pdo);
        $link = 'https://app.example.com/quote/' . str_repeat('ab', 32);

        $inside = QuoteFieldProvider::withLink(1, $this->quoteId, $link, fn (): ?array => $provider->values(1, self::AMRO, self::allowed(), (string) $this->quoteId));
        self::assertNotNull($inside);
        self::assertSame($link, $inside['values']['quote.link']);
        self::assertSame('QUO-0001', $inside['values']['quote.number']);
        self::assertSame('21 November 2026', $inside['values']['quote.issued_on']);

        $after = $provider->values(1, self::AMRO, self::allowed(), (string) $this->quoteId);
        self::assertNotNull($after);
        self::assertNull($after['values']['quote.link'], 'forgotten once the copy is made');

        try {
            QuoteFieldProvider::withLink(1, $this->quoteId, $link, static fn () => throw new \RuntimeException('issuing failed'));
        } catch (\RuntimeException) {
        }
        self::assertNull($provider->values(1, self::AMRO, self::allowed(), (string) $this->quoteId)['values']['quote.link'] ?? null, 'forgotten when issuing fails too');

        $other = QuoteFieldProvider::withLink(2, $this->quoteId, $link, fn (): ?array => $provider->values(1, self::AMRO, self::allowed(), (string) $this->quoteId));
        self::assertNull($other['values']['quote.link'] ?? null, "another workspace's link never prints here");
    }

    public function testNothingIsReadWithoutThePermissionOrFromAnotherWorkspace(): void
    {
        $provider = new QuoteFieldProvider($this->pdo);
        $none = static fn (string $permission): bool => false;

        self::assertNull($provider->values(1, self::AMRO, $none, (string) $this->quoteId));
        self::assertSame([], $provider->records(1, self::AMRO, $none, '', 10));
        self::assertNull($provider->values(2, self::AMRO, self::allowed(), (string) $this->quoteId));
        self::assertNull($provider->values(1, self::AMRO, self::allowed(), 'abc'));
        self::assertSame([['id' => (string) $this->quoteId, 'label' => 'Draft · Example Café']], $provider->records(1, self::AMRO, self::allowed(), 'café', 10));
        self::assertSame([], $provider->records(2, self::AMRO, self::allowed(), '', 10));
    }

    public function testTheStarterOnlyNamesFieldsTheQuoteFills(): void
    {
        $paths = [];
        foreach ((new QuoteFieldProvider($this->pdo))->groups(1) as $group) {
            foreach ($group['items'] as $item) {
                $paths[$item['path']] = true;
            }
        }
        foreach (['en', 'ar'] as $lang) {
            $starter = (new QuoteStarter())->build($lang);
            self::assertSame($lang, $starter['lang']);
            self::assertSame(QuoteFieldProvider::RECORD_TYPE, $starter['record_type']);
            $used = self::fieldPaths($starter['blocks']);
            self::assertContains('quote.link', $used, 'the QR opens the public page');
            foreach ($used as $path) {
                self::assertArrayHasKey($path, $paths, "the quote ({$lang}) prints {$path}");
            }
        }
    }

    public function testTheTemplateIsSeededOncePerLanguageAndPublished(): void
    {
        $documents = new QuoteDocuments(
            $this->pdo,
            new DocumentSourceRepository($this->pdo),
            new DocumentSourceVersionRepository($this->pdo),
            static fn (): TemplateIssuing => throw new \LogicException('not issued here'),
        );

        $templates = $documents->templates(1);
        $stmt = $this->pdo->prepare('SELECT record_type, lang, published_version, scope, starter_key FROM document_sources WHERE id = :id');
        $stmt->execute([':id' => $templates['ar']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $row['published_version'] = (string) $row['published_version'];
        self::assertSame(['record_type' => 'sales.quote', 'lang' => 'ar', 'published_version' => '1', 'scope' => 'system', 'starter_key' => 'sales.quote.ar'], $row);

        self::assertSame($templates, $documents->templates(1), 'seeded once; the second call finds them');
        self::assertNotSame($templates, $documents->templates(2), 'each workspace has its own');
    }

    /** @return callable(string): bool */
    private static function allowed(): callable
    {
        return static fn (string $permission): bool => true;
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private static function fieldPaths(array $node): array
    {
        $paths = [];
        if (($node['type'] ?? null) === 'field' && is_string($node['path'] ?? null)) {
            $paths[] = $node['path'];
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $paths = [...$paths, ...self::fieldPaths($child)];
            }
        }

        return array_values(array_unique($paths));
    }
}
