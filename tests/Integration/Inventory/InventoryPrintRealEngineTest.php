<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

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
use Whity\Core\Inventory\LocationService;
use Whity\Core\Inventory\Print\InventoryDocuments;
use Whity\Core\Inventory\Print\InventoryPrintFields;
use Whity\Core\Inventory\Print\InventoryStarters;
use Whity\Core\Inventory\Print\PurchaseOrderFieldProvider;
use Whity\Core\Inventory\Print\ReceiptFieldProvider;
use Whity\Core\Inventory\Print\VariantLabelFieldProvider;
use Whity\Core\Inventory\PurchaseOrders;
use Whity\Core\Inventory\ReasonCatalog;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\StockOperations;
use Whity\Core\Inventory\SupplierItems;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\SequenceCounters;

/**
 * Inventory's papers against the real schema (IV.20, WHIT-1234): what a
 * purchase order, a goods received note and an item label print, read with
 * the reader's permissions and only in their own workspace; the starters only
 * name fields the providers fill; and each template is seeded once, published.
 *
 * Example Bakery (tenant 1) orders 24 bags of flour (طحين) from Jordan Flour
 * Mills at $16.00 a bag, into the Central kitchen, and 10 bags arrive.
 */
final class InventoryPrintRealEngineTest extends TestCase
{
    private const AMRO = 11;

    private PDO $pdo;
    private PurchaseOrders $orders;
    private int $flour;
    private int $orderId;
    private int $receiptId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec("INSERT INTO contacts (id, tenant_id, name, is_customer, is_supplier, tax_number) VALUES (31, 1, 'Jordan Flour Mills', false, true, '12345678')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $hooks = new HookManager();
        $items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $hooks);
        $reasons = new ReasonCatalog($db, $workspace);
        $ledger = new StockLedger($db, $workspace, $items, $units, $hooks, $reasons);
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $effective = static fn (int $t): array => $settings->effective($t);
        $operations = new StockOperations($db, $workspace, $items, $units, $ledger, $reasons, $effective, $hooks);
        $suppliers = new SupplierItems($db, $items, $units);
        $this->orders = new PurchaseOrders($db, $workspace, $items, $units, $operations, $suppliers, new SequenceCounters($this->pdo), $effective, static fn (int $t): string => 'USD', $hooks);

        $this->flour = $items->create(1, [
            'name' => ['en' => 'Flour', 'ar' => 'طحين'], 'unit' => 'kg', 'sku' => 'FLR', 'sale_price_minor' => 2500,
            'units' => [['unit' => 'bag', 'per' => '25', 'purpose' => 'purchase']],
        ], self::AMRO)['variants'][0]['id'];
        $kitchen = (new LocationService($db, $workspace))->create(1, ['name' => ['en' => 'Central kitchen', 'ar' => 'المطبخ المركزي']])['id'];
        $suppliers->set(1, ['variant_id' => $this->flour, 'contact_id' => 31, 'unit' => 'bag', 'price_minor' => 1600, 'is_preferred' => true], static fn (int $t): string => 'USD');

        $draft = $this->orders->create(1, self::AMRO, [
            'contact_id' => 31, 'location_id' => $kitchen, 'expected_on' => '2026-11-26',
            'lines' => [['variant_id' => $this->flour, 'quantity' => '24', 'unit' => 'bag', 'tax_bp' => 500]],
        ])['order'];
        $order = $this->orders->submit(1, $draft['id'], self::AMRO);
        $this->orderId = $order['id'];
        $received = $this->orders->receive(1, $order['id'], self::AMRO, [['line_id' => $order['lines'][0]['id'], 'quantity' => '10']], false, 'Two bags torn');
        $this->receiptId = $received['receipt']['id'];
    }

    public function testAPurchaseOrderPrintsItsSupplierLinesAndTotalsInBothLanguages(): void
    {
        $po = (new PurchaseOrderFieldProvider($this->pdo))->values(1, self::AMRO, self::allowed(), (string) $this->orderId);

        self::assertNotNull($po);
        $v = $po['values'];
        self::assertSame('PO-0001 · Jordan Flour Mills', $po['label']);
        self::assertSame('PO-0001', $v['po.number']);
        self::assertSame('Jordan Flour Mills', $v['po.supplier']);
        self::assertSame('12345678', $v['po.supplier_tax_number']);
        self::assertSame('Central kitchen', $v['po.deliver_to']);
        self::assertSame('المطبخ المركزي', $v['po.deliver_to_ar']);
        self::assertSame('26 November 2026', $v['po.deliver_by']);
        self::assertSame('26 تشرين الثاني 2026', $v['po.deliver_by_ar'], 'the month as written in Jordan, the day and year in Western digits');
        self::assertSame('$384.00', $v['po.subtotal']);
        self::assertSame('$19.20', $v['po.tax']);
        self::assertSame('$403.20', $v['po.total']);
        self::assertNotNull($v['po.total_words_en']);
        self::assertNotNull($v['po.total_words_ar']);
        self::assertSame('Amro Saleh', $v['po.raised_by']);
        self::assertSame('Amro Saleh', $v['po.approved_by']);
        self::assertNull($v['po.notes'], 'nothing written is no value, not an empty one');

        self::assertSame('1', $v['po.lines.count']);
        self::assertSame('Flour', $v['po.lines.1.item']);
        self::assertSame('طحين', $v['po.lines.1.item_ar']);
        self::assertSame('24 bag', $v['po.lines.1.quantity'], 'quantity before unit (IV.21)');
        self::assertSame('$16.00', $v['po.lines.1.unit_price']);
        self::assertSame('$384.00', $v['po.lines.1.amount']);
    }

    public function testAGoodsReceivedNoteShowsWhatWasOrderedAndWhatCame(): void
    {
        $grn = (new ReceiptFieldProvider($this->pdo))->values(1, self::AMRO, self::allowed(), (string) $this->receiptId);

        self::assertNotNull($grn);
        $v = $grn['values'];
        self::assertSame('GRN-0001', $v['grn.number']);
        self::assertSame('PO-0001', $v['grn.order_number']);
        self::assertSame('Jordan Flour Mills', $v['grn.supplier']);
        self::assertSame('المطبخ المركزي', $v['grn.place_ar']);
        self::assertSame('Two bags torn', $v['grn.note']);
        self::assertSame('Amro Saleh', $v['grn.received_by']);
        self::assertSame('1', $v['grn.lines.count']);
        self::assertSame('24 bag', $v['grn.lines.1.ordered']);
        self::assertSame('10 bag', $v['grn.lines.1.received'], 'as it was counted at the door');
    }

    public function testALabelPrintsTheNamePriceAndTheSkuWhenThereIsNoBarcode(): void
    {
        $label = (new VariantLabelFieldProvider($this->pdo))->values(1, self::AMRO, self::allowed(), (string) $this->flour);

        self::assertNotNull($label);
        self::assertSame('Flour', $label['values']['item.name']);
        self::assertSame('طحين', $label['values']['item.name_ar']);
        self::assertSame('$25.00', $label['values']['item.price']);
        self::assertSame('FLR', $label['values']['item.barcode'], 'every label scans back to the item');
    }

    public function testNothingIsReadWithoutThePermissionOrFromAnotherWorkspace(): void
    {
        $none = static fn (string $permission): bool => false;
        $po = new PurchaseOrderFieldProvider($this->pdo);
        $grn = new ReceiptFieldProvider($this->pdo);
        $item = new VariantLabelFieldProvider($this->pdo);

        self::assertNull($po->values(1, self::AMRO, $none, (string) $this->orderId));
        self::assertNull($grn->values(1, self::AMRO, $none, (string) $this->receiptId));
        self::assertNull($item->values(1, self::AMRO, $none, (string) $this->flour));
        self::assertSame([], $po->records(1, self::AMRO, $none, '', 10));

        self::assertNull($po->values(2, self::AMRO, self::allowed(), (string) $this->orderId), "another workspace's order is not found");
        self::assertNull($grn->values(2, self::AMRO, self::allowed(), (string) $this->receiptId));
        self::assertNull($item->values(2, self::AMRO, self::allowed(), (string) $this->flour));
        self::assertNull($po->values(1, self::AMRO, self::allowed(), 'abc'));

        self::assertSame([['id' => (string) $this->orderId, 'label' => 'PO-0001 · Jordan Flour Mills']], $po->records(1, self::AMRO, self::allowed(), 'mills', 10));
        self::assertSame([], $po->records(2, self::AMRO, self::allowed(), '', 10));
    }

    public function testEveryStarterOnlyNamesFieldsItsRecordFills(): void
    {
        $paths = [];
        foreach (InventoryPrintFields::all($this->pdo) as $provider) {
            foreach ($provider->groups(1) as $group) {
                foreach ($group['items'] as $item) {
                    $paths[$provider->recordType()][$item['path']] = true;
                }
            }
        }

        foreach (InventoryStarters::documents() as $document) {
            foreach (['en', 'ar'] as $lang) {
                $starter = (new InventoryStarters())->build($document, $lang);
                self::assertSame($lang, $starter['lang']);
                $used = self::fieldPaths($starter['blocks']);
                self::assertNotSame([], $used, $document . ' reads its record');
                foreach ($used as $path) {
                    self::assertArrayHasKey($path, $paths[$starter['record_type']], "{$document} ({$lang}) prints {$path}");
                }
            }
        }
    }

    public function testEachTemplateIsSeededOncePublishedAndKeptWhenSomeoneChangesIt(): void
    {
        $documents = new InventoryDocuments(
            $this->pdo,
            new DocumentSourceRepository($this->pdo),
            new DocumentSourceVersionRepository($this->pdo),
            static fn (): TemplateIssuing => throw new \LogicException('not issued here'),
        );

        $templates = $documents->templates(1);
        self::assertSame(InventoryStarters::documents(), array_keys($templates));
        $po = $templates[InventoryStarters::PURCHASE_ORDER]['ar'];

        $row = $this->one('SELECT record_type, lang, published_version, scope FROM document_sources WHERE id = ' . $po);
        self::assertIsArray($row);
        // PostgreSQL answers the version as an int, SQLite as a string.
        $row['published_version'] = (string) $row['published_version'];
        self::assertSame(['record_type' => 'inventory.purchase_order', 'lang' => 'ar', 'published_version' => '1', 'scope' => 'system'], $row);

        $this->pdo->exec("UPDATE document_sources SET title = 'Our purchase order' WHERE id = " . $po);
        self::assertSame($templates, $documents->templates(1), 'seeded once; the second call finds them');
        self::assertSame(['title' => 'Our purchase order'], $this->one('SELECT title FROM document_sources WHERE id = ' . $po));
        self::assertNotSame($templates, $documents->templates(2), "each workspace has its own");
    }

    /** @return array<string, mixed>|false */
    private function one(string $sql): array|false
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
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
