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
use Whity\Core\Inventory\SupplierItems;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Inventory\ReasonCatalog;
use Whity\Core\Inventory\StockLedger;

/**
 * The rest of the inventory import targets (WHIT-1234 P3d, design 31 §4.7,
 * section 34 §6.2, register IM.3): items with variants, a row per variant with
 * the option columns named by their headings, and supplier prices matched by
 * supplier and SKU. Both are checked without changing anything, run, and
 * undone, against the real schema.
 *
 * Example Bakery (tenant 1) buys from Example Flour Mills and Example Dairy.
 */
final class InventoryImportVariantsAndPricesRealEngineTest extends TestCase
{
    private const AMRO = 11;
    private const MILLS = 31;
    private const DAIRY = 32;
    private const NOT_SUPPLIER = 33;

    private PDO $pdo;
    private InventoryImports $imports;
    private ItemService $items;
    private SupplierItems $suppliers;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec(
            "INSERT INTO contacts (id, tenant_id, name, is_customer, is_supplier) VALUES
                (31, 1, 'Example Flour Mills', false, true), (32, 1, 'Example Dairy', false, true), (33, 1, 'Example Cafe', true, false)"
        );
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $hooks = new HookManager();
        $this->items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $hooks);
        $ledger = new StockLedger($db, $workspace, $this->items, $units, $hooks, new ReasonCatalog($db, $workspace));
        $this->suppliers = new SupplierItems($db, $this->items, $units);
        $this->imports = new InventoryImports($db, $workspace, $this->items, $ledger, static fn (int $t): string => 'USD', $this->suppliers);
    }

    public function testOptionColumnsAreSuggestedFromTheirHeadings(): void
    {
        $import = $this->upload('items', "Item group,SKU,Name,Size,Colour\nAPR,APR-S-W,Apron,S,White\n");

        $suggested = array_map(static fn (array $c): ?string => $c['suggestion']['target'] ?? null, $import['columns']);
        self::assertSame(['group', 'sku', 'name', 'option1', 'option2'], $suggested);
    }

    public function testARowPerVariantMakesOneItemWithItsVariants(): void
    {
        $import = $this->upload('items', "SKU,Name,Size,Colour,Sale price,Barcode\n"
            . "APR-S-W,Apron,S,White,12.00,6250000000011\n"
            . "APR-M-W,Apron,M,White,12.00,\n"
            . "APR-L-W,Apron,L,White,14.50,\n"
            . "APR-M-B,Apron,M,,12.00,\n"
            . "APR-M-W2,Apron,M,White,12.00,\n"
            . "CAP,Cap,,,5.00,\n");
        $checked = $this->imports->check(1, 'items', $import['id'], ['mapping' => [
            '0' => 'sku', '1' => 'name', '2' => 'option1', '3' => 'option2', '4' => 'sale_price', '5' => 'barcode',
        ]]);

        self::assertSame(['create' => 4, 'update' => 0, 'skip' => 2], array_intersect_key($checked['summary'], ['create' => 0, 'update' => 0, 'skip' => 0]));
        self::assertSame(
            [5 => 'Every variant needs its Colour', 6 => 'Same Size and Colour as row 3'],
            $this->reasons($checked['summary']['rows'])
        );
        self::assertNull($this->items->itemIdBySku(1, 'APR-S-W'), 'checking changes nothing');

        $this->imports->run(1, 'items', $import['id'], self::AMRO);

        $apron = $this->items->show(1, (int) $this->items->itemIdBySku(1, 'APR-S-W'));
        self::assertSame('Apron', $apron['name']);
        self::assertSame(1200, $apron['sale_price_minor'], 'the item\'s price comes from its first row');
        $variants = [];
        foreach ($apron['variants'] as $v) {
            $variants[$v['sku']] = [$v['options'], $v['sale_price_minor']];
        }
        self::assertSame([
            'APR-S-W' => [['Size' => 'S', 'Colour' => 'White'], null],
            'APR-M-W' => [['Size' => 'M', 'Colour' => 'White'], null],
            'APR-L-W' => [['Size' => 'L', 'Colour' => 'White'], 1450],
        ], $variants, 'one variant per row, a different price kept on its variant');
        self::assertSame('6250000000011', $apron['variants'][0]['barcodes'][0]['code'] ?? null);
        self::assertNotSame($apron['id'], $this->items->itemIdBySku(1, 'CAP'), 'a row with no options is an item of its own');
    }

    public function testNewVariantsAreAddedToAnItemThatExistsAndUndoTakesThemAway(): void
    {
        $this->items->create(1, ['name' => 'Apron', 'sale_price_minor' => 1200, 'variants' => [
            ['sku' => 'APR-S', 'options' => ['Size' => 'S']],
            ['sku' => 'APR-M', 'options' => ['Size' => 'M']],
        ]], self::AMRO);

        $import = $this->upload('items', "Model,SKU,Size\nApron,APR-M,M\nApron,APR-L,L\nApron,APR-XL,XL\n");
        $checked = $this->imports->check(1, 'items', $import['id'], ['mapping' => ['0' => 'group', '1' => 'sku', '2' => 'option1']]);
        self::assertSame(['create' => 2, 'update' => 0, 'skip' => 1], array_intersect_key($checked['summary'], ['create' => 0, 'update' => 0, 'skip' => 0]));

        $this->imports->run(1, 'items', $import['id'], self::AMRO);
        $apron = $this->items->show(1, (int) $this->items->itemIdBySku(1, 'APR-S'));
        self::assertSame(['APR-S', 'APR-M', 'APR-L', 'APR-XL'], $this->liveSkus($apron));

        $this->imports->undo(1, 'items', $import['id'], self::AMRO);
        self::assertSame(['APR-S', 'APR-M'], $this->liveSkus($this->items->show(1, $apron['id'])));
    }

    public function testVariantsByOtherOptionsThanTheItemsAreSkipped(): void
    {
        $this->items->create(1, ['name' => 'Apron', 'variants' => [
            ['sku' => 'APR-S', 'options' => ['Size' => 'S']],
            ['sku' => 'APR-M', 'options' => ['Size' => 'M']],
        ]], self::AMRO);

        $import = $this->upload('items', "Name,SKU,Colour\nApron,APR-S,White\nApron,APR-B,Black\n");
        $checked = $this->imports->check(1, 'items', $import['id'], ['mapping' => ['0' => 'name', '1' => 'sku', '2' => 'option2']]);

        $reason = 'Apron\'s variants are by Size; this file has Colour';
        self::assertSame([2 => $reason, 3 => $reason], $this->reasons($checked['summary']['rows']));
    }

    public function testSupplierPricesAreSetBySupplierAndSkuWithoutEmptyingAnything(): void
    {
        $flour = $this->items->create(1, ['name' => 'Flour', 'sku' => 'FLR', 'unit' => 'kg'], self::AMRO)['variants'][0]['id'];
        $this->items->create(1, ['name' => 'Delivery', 'sku' => 'DLV', 'kind' => 'service'], self::AMRO);
        $this->suppliers->set(1, ['variant_id' => $flour, 'contact_id' => self::DAIRY, 'price_minor' => 70, 'supplier_sku' => 'D-9', 'lead_days' => 2, 'is_preferred' => true], static fn (): string => 'USD');

        $import = $this->upload('supplier_prices', "Supplier,SKU,Price,Supplier code,Lead days,Preferred\n"
            . "Example Flour Mills,FLR,0.65,JFM-1,3,yes\n"
            . "Example Dairy,FLR,0.72,,,\n"
            . "Example Cafe,FLR,1,,,\n"
            . "Nobody,FLR,1,,,\n"
            . "Example Flour Mills,DLV,1,,,\n"
            . "Example Flour Mills,NOPE,1,,,\n"
            . "Example Dairy,FLR,abc,,,\n");
        $checked = $this->imports->check(1, 'supplier_prices', $import['id'], ['mapping' => [
            '0' => 'supplier', '1' => 'sku', '2' => 'price', '3' => 'supplier_sku', '4' => 'lead_days', '5' => 'preferred',
        ]]);

        self::assertSame(['create' => 1, 'update' => 1, 'skip' => 5], array_intersect_key($checked['summary'], ['create' => 0, 'update' => 0, 'skip' => 0]));
        self::assertSame([
            4 => 'Example Cafe is not marked as a supplier; mark them as one on their contact page first',
            5 => 'There is no contact called "Nobody"; add the supplier first',
            6 => 'A service has no supplier price',
            7 => 'No item has this SKU; import the items first',
            8 => 'Same item and supplier as row 3',
        ], $this->reasons($checked['summary']['rows']));

        $this->imports->run(1, 'supplier_prices', $import['id'], self::AMRO);

        $rows = [];
        foreach ($this->suppliers->list(1, ['variant_id' => $flour]) as $s) {
            $rows[$s['contact_id']] = [$s['price_minor'], $s['supplier_sku'], $s['lead_days'], $s['is_preferred']];
        }
        self::assertSame([
            self::MILLS => [65, 'JFM-1', 3, true],
            self::DAIRY => [72, 'D-9', 2, false],
        ], $rows, 'a blank cell keeps the supplier\'s code and lead days; the new preferred one takes it from the other');
    }

    public function testUndoRemovesNewSupplierPricesAndPutsBackChangedOnes(): void
    {
        $flour = $this->items->create(1, ['name' => 'Flour', 'sku' => 'FLR', 'unit' => 'kg'], self::AMRO)['variants'][0]['id'];
        $this->suppliers->set(1, ['variant_id' => $flour, 'contact_id' => self::DAIRY, 'price_minor' => 70, 'is_preferred' => true], static fn (): string => 'USD');

        $import = $this->upload('supplier_prices', "SKU,Price,Preferred\nFLR,0.65,yes\n");
        $this->imports->check(1, 'supplier_prices', $import['id'], ['mapping' => ['0' => 'sku', '1' => 'price', '2' => 'preferred'], 'contact_id' => self::MILLS]);
        $this->imports->run(1, 'supplier_prices', $import['id'], self::AMRO);
        self::assertCount(2, $this->suppliers->list(1, ['variant_id' => $flour]));

        $this->imports->undo(1, 'supplier_prices', $import['id'], self::AMRO);

        $left = $this->suppliers->list(1, ['variant_id' => $flour]);
        self::assertCount(1, $left);
        self::assertSame([self::DAIRY, 70, true], [$left[0]['contact_id'], $left[0]['price_minor'], $left[0]['is_preferred']], 'the one it took "preferred" from gets it back');
    }

    public function testSupplierPricesNeedASupplier(): void
    {
        $import = $this->upload('supplier_prices', "SKU,Price\nFLR,1\n");

        $this->expectException(InventoryRejectedException::class);
        $this->expectExceptionMessage('Choose the supplier');
        $this->imports->check(1, 'supplier_prices', $import['id'], ['mapping' => ['0' => 'sku', '1' => 'price']]);
    }

    public function testTheSupplierForTheFileMustBeASupplierHere(): void
    {
        $import = $this->upload('supplier_prices', "SKU,Price\nFLR,1\n");

        $this->expectException(InventoryRejectedException::class);
        $this->imports->check(1, 'supplier_prices', $import['id'], ['mapping' => ['0' => 'sku', '1' => 'price'], 'contact_id' => self::NOT_SUPPLIER]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, string> row number => reason, for skipped rows
     */
    private function reasons(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['action'] === 'skip') {
                $out[(int) $r['row']] = (string) $r['reason'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<string>
     */
    private function liveSkus(array $item): array
    {
        return array_values(array_map(
            static fn (array $v): string => (string) $v['sku'],
            array_filter($item['variants'], static fn (array $v): bool => !$v['archived'])
        ));
    }

    /** @return array<string, mixed> */
    private function upload(string $kind, string $csv): array
    {
        return $this->imports->upload(1, $kind, self::AMRO, $kind . '.csv', $csv);
    }
}
