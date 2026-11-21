<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\Import\InventoryImports;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\LocationService;
use Whity\Core\Inventory\ReasonCatalog;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\UnitCatalog;

/**
 * Importing items and opening stock against the real schema (WHIT-1322,
 * design section 34 §6, register IM.1-IM.8): column suggestions from English
 * and Arabic headings, create and update by SKU without emptying fields,
 * skipped rows with reasons, opening stock at the given cost into a named
 * place, undo for both, refusing an undo once stock has moved, and tenant
 * isolation.
 *
 * Example Bakery (tenant 1), with a Central kitchen and a Shop.
 */
final class InventoryImportsRealEngineTest extends TestCase
{
    private const AMRO = 11;

    private PDO $pdo;
    private InventoryImports $imports;
    private ItemService $items;
    private StockLedger $ledger;
    private int $kitchen;
    private int $shop;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $hooks = new HookManager();
        $this->items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $hooks);
        $this->ledger = new StockLedger($db, $workspace, $this->items, $units, $hooks, new ReasonCatalog($db, $workspace));
        $locations = new LocationService($db, $workspace);
        $this->kitchen = $locations->create(1, ['name' => 'Central kitchen', 'code' => 'CK'])['id'];
        $this->shop = $locations->create(1, ['name' => ['en' => 'Shop', 'ar' => 'المحل']])['id'];
        $this->imports = new InventoryImports($db, $workspace, $this->items, $this->ledger, static fn (int $t): string => 'USD');
    }

    public function testColumnsAreSuggestedFromEnglishAndArabicHeadings(): void
    {
        $import = $this->upload('items', "رمز الصنف,Item name,الاسم العربي,Selling price,Email\nFLR,Flour,طحين,1.25,x@example.com\n");

        $suggested = array_map(static fn (array $c): ?string => $c['suggestion']['target'] ?? null, $import['columns']);
        self::assertSame(['sku', 'name', 'name_ar', 'sale_price', null], $suggested, 'no contact field is offered for items');
        self::assertSame(1, $import['row_count']);
    }

    public function testItemsAreCreatedAndUpdatedBySkuAndNeverEmptied(): void
    {
        $this->items->create(1, ['name' => 'Sugar', 'sku' => 'SUG', 'sale_price_minor' => 90, 'tax_category' => 'standard'], self::AMRO);

        $import = $this->upload('items', "SKU,Name,Arabic name,Sale price,Tax category,Unit,Barcode\n"
            . "FLR,Flour,طحين,1.25,standard,kg,6251234567890\n"
            . "SUG,,,0.95,,,\n"
            . ",No code,,,,,\n"
            . "FLR,Flour again,,,,,\n"
            . "OIL,Olive oil,,abc,,,\n"
            . "SLT,Salt,,,,box9,\n");
        $checked = $this->imports->check(1, 'items', $import['id'], ['mapping' => ['0' => 'sku', '1' => 'name', '2' => 'name_ar', '3' => 'sale_price', '4' => 'tax_category', '5' => 'unit', '6' => 'barcode']]);

        self::assertSame(['create' => 1, 'update' => 1, 'skip' => 4], array_intersect_key($checked['summary'], ['create' => 0, 'update' => 0, 'skip' => 0]));
        $reasons = [];
        foreach ($checked['summary']['rows'] as $r) {
            if ($r['action'] === 'skip') {
                $reasons[] = $r['reason'];
            }
        }
        self::assertSame(['No SKU', 'Same item as row 2', 'Sale price is not an amount in USD', 'There is no unit "box9"; add it under Units first'], $reasons);
        self::assertNull($this->items->itemIdBySku(1, 'FLR'), 'checking changes nothing');

        $this->imports->run(1, 'items', $import['id'], self::AMRO);
        $flour = $this->items->show(1, (int) $this->items->itemIdBySku(1, 'FLR'));
        self::assertSame(125, $flour['sale_price_minor']);
        self::assertSame('kg', $flour['base_unit']['code'] ?? null);
        $sugar = $this->items->row(1, (int) $this->items->itemIdBySku(1, 'SUG'));
        self::assertSame(95, (int) ($sugar['sale_price_minor'] ?? 0));
        self::assertSame('standard', $sugar['tax_category'] ?? null, 'a blank cell empties nothing');
        self::assertSame('Sugar', $sugar['name'] ?? null);
    }

    public function testUndoArchivesWhatItMadeAndPutsBackWhatItChanged(): void
    {
        $this->items->create(1, ['name' => 'Sugar', 'sku' => 'SUG', 'sale_price_minor' => 90], self::AMRO);
        $import = $this->upload('items', "SKU,Name,Sale price\nFLR,Flour,1.25\nSUG,,0.95\n");
        $this->imports->check(1, 'items', $import['id'], ['mapping' => ['0' => 'sku', '1' => 'name', '2' => 'sale_price']]);
        self::assertTrue($this->imports->run(1, 'items', $import['id'], self::AMRO)['can_undo']);

        $undone = $this->imports->undo(1, 'items', $import['id'], self::AMRO);

        self::assertSame('undone', $undone['status']);
        self::assertNotNull($this->items->row(1, (int) $this->items->itemIdBySku(1, 'FLR'))['archived_at'] ?? null);
        self::assertSame(90, (int) ($this->items->row(1, (int) $this->items->itemIdBySku(1, 'SUG'))['sale_price_minor'] ?? 0));
    }

    public function testOpeningStockGoesIntoTheNamedPlaceAtItsCostAndCanBeUndone(): void
    {
        $flour = $this->items->create(1, ['name' => 'Flour', 'sku' => 'FLR', 'unit' => 'kg'], self::AMRO)['variants'][0]['id'];
        $this->items->create(1, ['name' => 'Delivery', 'sku' => 'DLV', 'kind' => 'service'], self::AMRO);

        $import = $this->upload('opening_stock', "SKU,Quantity,Place,Unit cost\nFLR,250,المحل,0.60\nFLR,40,,0.60\nDLV,1,,\nNOPE,3,,\nFLR,5,Garage,\n");
        $checked = $this->imports->check(1, 'opening_stock', $import['id'], [
            'mapping' => ['0' => 'sku', '1' => 'quantity', '2' => 'place', '3' => 'unit_cost'], 'location_id' => $this->kitchen,
        ]);
        self::assertSame(2, $checked['summary']['create']);
        self::assertSame(3, $checked['summary']['skip']);

        $this->imports->run(1, 'opening_stock', $import['id'], self::AMRO);
        self::assertSame(250000, $this->ledger->onHandSteps(1, $flour, $this->shop), 'kg keeps 3 decimals');
        self::assertSame(40000, $this->ledger->onHandSteps(1, $flour, $this->kitchen));

        $this->imports->undo(1, 'opening_stock', $import['id'], self::AMRO);
        self::assertSame(0, $this->ledger->onHandSteps(1, $flour, $this->shop));
        self::assertSame(0, $this->ledger->onHandSteps(1, $flour, $this->kitchen));
    }

    public function testOpeningStockThatHasMovedCannotBeUndone(): void
    {
        $flour = $this->items->create(1, ['name' => 'Flour', 'sku' => 'FLR', 'unit' => 'kg'], self::AMRO)['variants'][0]['id'];
        $import = $this->upload('opening_stock', "SKU,Quantity\nFLR,10\n");
        $this->imports->check(1, 'opening_stock', $import['id'], ['mapping' => ['0' => 'sku', '1' => 'quantity'], 'location_id' => $this->kitchen]);
        $this->imports->run(1, 'opening_stock', $import['id'], self::AMRO);
        $this->ledger->adjust(1, self::AMRO, 'adjustment', [['variant_id' => $flour, 'location_id' => $this->kitchen, 'change' => '-1']], 'damaged', '', null, null);

        $this->expectException(InventoryRejectedException::class);
        $this->expectExceptionMessage('has moved since');
        $this->imports->undo(1, 'opening_stock', $import['id'], self::AMRO);
    }

    public function testOpeningStockNeedsAPlace(): void
    {
        $import = $this->upload('opening_stock', "SKU,Quantity\nFLR,10\n");

        $this->expectException(InventoryRejectedException::class);
        $this->imports->check(1, 'opening_stock', $import['id'], ['mapping' => ['0' => 'sku', '1' => 'quantity']]);
    }

    public function testAnotherWorkspaceCannotSeeOrRunTheImport(): void
    {
        $import = $this->upload('items', "SKU,Name\nFLR,Flour\n");

        self::assertSame([], $this->imports->recent(2, 'items'));
        $this->expectException(InventoryRejectedException::class);
        $this->imports->show(2, 'items', $import['id']);
    }

    public function testAnImportOfOneKindIsNotFoundAsAnother(): void
    {
        $import = $this->upload('items', "SKU,Name\nFLR,Flour\n");

        $this->expectException(InventoryRejectedException::class);
        $this->imports->show(1, 'opening_stock', $import['id']);
    }

    /** @return array<string, mixed> */
    private function upload(string $kind, string $csv): array
    {
        return $this->imports->upload(1, $kind, self::AMRO, $kind . '.csv', $csv);
    }
}
