<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\LocationService;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * Items, places and the stock ledger against the real schema (WHIT-1232,
 * design section 31): exact unit conversions, levels as the sum of the
 * ledger, an append-only ledger, undo by reversing moves, idempotent posts,
 * what the item limit counts, the stock.moving veto, and tenant isolation.
 *
 * Example Bakery is tenant 1; tenant 2 is another workspace.
 */
final class InventoryLedgerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private ItemService $items;
    private LocationService $locations;
    private StockLedger $ledger;
    private HookManager $hooks;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Ibrahim Mansour', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $this->hooks = new HookManager();
        $this->items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $this->hooks);
        $this->locations = new LocationService($db, $workspace);
        $this->ledger = new StockLedger($db, $workspace, $this->items, $units, $this->hooks);
    }

    public function testOpeningStockInBagsIsKeptExactlyInKilograms(): void
    {
        $flour = $this->items->create(1, [
            'name' => ['en' => 'Flour', 'ar' => 'طحين'],
            'unit' => 'kg',
            'sku' => 'FLR-25',
            'barcodes' => ['٦٢٥١٢٣٤٥٦٧٨٩٠'],
            'units' => [['unit' => 'bag', 'per' => '25', 'purpose' => 'purchase']],
            'purchase_price_minor' => 1600,
        ], 11);
        self::assertSame(['en' => 'Flour', 'ar' => 'طحين'], $flour['names']);
        self::assertSame('USD', $flour['currency']);
        self::assertSame('6251234567890', $flour['variants'][0]['barcodes'][0]['code']);
        self::assertSame('ean13', $flour['variants'][0]['barcodes'][0]['symbology']);
        self::assertSame([['unit' => 'bag', 'per' => '25', 'purpose' => 'purchase', 'is_default' => false]], $flour['units']);

        $kitchen = $this->locations->create(1, ['name' => 'Central kitchen']);
        $variant = $flour['variants'][0]['id'];
        $this->ledger->adjust(1, 11, 'opening', [
            ['variant_id' => $variant, 'location_id' => $kitchen['id'], 'change' => '18', 'unit' => 'bag'],
            ['variant_id' => $variant, 'location_id' => $kitchen['id'], 'change' => '2.5'],
            ['variant_id' => $variant, 'location_id' => $kitchen['id'], 'change' => '750', 'unit' => 'g'],
        ], '', '', null, null);

        $level = $this->ledger->levels(1, ['variant_id' => $variant]);
        self::assertCount(1, $level, 'virtual places hold no stock');
        self::assertSame('453.250', $level[0]['on_hand']);
        self::assertSame('kg', $level[0]['unit']);
        self::assertSame('453.250', $this->items->show(1, $flour['id'])['on_hand']);

        $found = $this->items->lookup(1, '6251234567890');
        self::assertNotNull($found);
        self::assertSame($variant, $found['variant_id']);
    }

    public function testAdjustmentsUndoAndTheLedgerNeverChanges(): void
    {
        [$variant, $shop] = $this->butterInAbdoun('10');

        $waste = $this->ledger->adjust(1, 11, 'adjustment', [['variant_id' => $variant, 'location_id' => $shop, 'change' => '-1.5']], 'spoiled', 'Fridge failed', null, null);
        self::assertSame('8.500', $this->onHand($variant, $shop));
        self::assertSame('spoiled', $waste['moves'][0]['reason_code']);
        self::assertSame('Ibrahim Mansour', $waste['moves'][0]['posted_by_name']);

        $undo = $this->ledger->reverse(1, $waste['batch_key'], 11);
        self::assertSame('10.000', $this->onHand($variant, $shop));
        self::assertSame($waste['moves'][0]['id'], $undo['moves'][0]['reverses_move_id']);

        try {
            $this->ledger->reverse(1, $waste['batch_key'], 11);
            self::fail('a batch is undone once');
        } catch (InventoryRejectedException $e) {
            self::assertSame(409, $e->status);
        }

        self::assertCount(3, $this->ledger->history(1, ['variant_id' => $variant]));
        foreach (['UPDATE inv_moves SET quantity = 1 WHERE tenant_id = 1', 'DELETE FROM inv_moves WHERE tenant_id = 1'] as $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('the ledger is append-only: ' . $sql);
            } catch (\PDOException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }

        self::assertSame([], $this->ledger->rebuild(1), 'levels match the ledger');
        $this->pdo->exec('UPDATE inv_levels SET on_hand = 1 WHERE tenant_id = 1');
        self::assertCount(1, $this->ledger->rebuild(1));
        self::assertSame('10.000', $this->onHand($variant, $shop));
    }

    public function testAReasonIsNeededAndStockCanGoBelowZero(): void
    {
        [$variant, $shop] = $this->butterInAbdoun('1');
        try {
            $this->ledger->adjust(1, 11, 'adjustment', [['variant_id' => $variant, 'location_id' => $shop, 'change' => '-1']], 'found', '', null, null);
            self::fail('found is not a reason for stock going out');
        } catch (InventoryRejectedException $e) {
            self::assertSame('reason_code', $e->extra['field']);
        }
        $this->ledger->adjust(1, 11, 'adjustment', [['variant_id' => $variant, 'location_id' => $shop, 'change' => '-3']], 'damaged', '', null, null);
        self::assertSame('-2.000', $this->onHand($variant, $shop), 'register IV.8: allowed by default');
    }

    public function testAPostWithTheSameKeyHappensOnce(): void
    {
        [$variant, $shop] = $this->butterInAbdoun('5');
        $first = $this->ledger->adjust(1, 11, 'adjustment', [['variant_id' => $variant, 'location_id' => $shop, 'change' => '-1']], 'sample', '', null, 'phone-42');
        $again = $this->ledger->adjust(1, 11, 'adjustment', [['variant_id' => $variant, 'location_id' => $shop, 'change' => '-1']], 'sample', '', null, 'phone-42');

        self::assertFalse($first['repeated']);
        self::assertTrue($again['repeated']);
        self::assertSame($first['batch_key'], $again['batch_key']);
        self::assertSame('4.000', $this->onHand($variant, $shop));
    }

    public function testAListenerCanRefuseAMoveAndNothingIsWritten(): void
    {
        [$variant, $shop] = $this->butterInAbdoun('5');
        $this->hooks->listen(Events::STOCK_MOVING, static function (array $payload): array {
            throw new HookVetoException(Events::STOCK_MOVING, 'The month is closed');
        });
        try {
            $this->ledger->adjust(1, 11, 'adjustment', [['variant_id' => $variant, 'location_id' => $shop, 'change' => '-1']], 'sample', '', null, null);
            self::fail('vetoed');
        } catch (InventoryRejectedException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('The month is closed', $e->clientMessage);
        }
        self::assertSame('5.000', $this->onHand($variant, $shop));
        self::assertCount(1, $this->ledger->history(1, ['variant_id' => $variant]));
    }

    public function testItemsAreNeverRefusedAndTheCountIsLiveVariants(): void
    {
        $shirt = $this->items->create(1, ['name' => 'T-shirt', 'variants' => [
            ['options' => ['size' => 'S']], ['options' => ['size' => 'M']], ['options' => ['size' => 'L']],
        ]], 11);
        self::assertSame(['ITEM-' . $shirt['id'] . '-1', 'ITEM-' . $shirt['id'] . '-2', 'ITEM-' . $shirt['id'] . '-3'], array_column($shirt['variants'], 'sku'));
        $this->items->create(1, ['name' => 'Cap'], 11);
        self::assertSame(4, $this->items->countLiveVariants(1));

        $this->items->archive(1, $shirt['id'], true, 11);
        self::assertSame(1, $this->items->countLiveVariants(1));
        self::assertSame(0, $this->items->countLiveVariants(2));
    }

    public function testAnotherWorkspaceSeesNothingAndCannotMoveIt(): void
    {
        [$variant, $shop] = $this->butterInAbdoun('5');
        self::assertSame([], $this->ledger->levels(2));
        self::assertSame(0, $this->items->list(2, [], 50, 0)['total']);
        self::assertNull($this->items->lookup(2, 'BTR-10'));

        $this->expectException(InventoryRejectedException::class);
        $this->ledger->adjust(2, 11, 'opening', [['variant_id' => $variant, 'location_id' => $shop, 'change' => '1']], '', '', null, null);
    }

    public function testDeletingAWorkspaceTakesItsLedgerWithIt(): void
    {
        $this->butterInAbdoun('5');
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
        $this->pdo->exec('DELETE FROM tenants WHERE id = 1');
        $left = $this->pdo->query('SELECT COUNT(*) FROM inv_moves');
        self::assertNotFalse($left);
        self::assertSame(0, (int) $left->fetchColumn());
    }

    /** @return array{0: int, 1: int} */
    private function butterInAbdoun(string $kg): array
    {
        $butter = $this->items->create(1, ['name' => 'Butter', 'unit' => 'kg', 'sku' => 'BTR-10'], 11);
        $shop = $this->locations->create(1, ['name' => 'Abdoun']);
        $variant = $butter['variants'][0]['id'];
        $this->ledger->adjust(1, 11, 'opening', [['variant_id' => $variant, 'location_id' => $shop['id'], 'change' => $kg]], '', '', null, null);

        return [$variant, $shop['id']];
    }

    private function onHand(int $variant, int $place): string
    {
        foreach ($this->ledger->levels(1, ['variant_id' => $variant]) as $level) {
            if ($level['location_id'] === $place) {
                return $level['on_hand'];
            }
        }

        return '0';
    }
}
