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
use Whity\Core\Inventory\LotExpiryJob;
use Whity\Core\Inventory\LotService;
use Whity\Core\Inventory\ReasonCatalog;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\StockOperations;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Sdk\Hooks\Events;

/**
 * Lots, serial numbers and expiry against the real schema (WHIT-1235, design
 * 31 §3.5, register IN.1): a batch code and expiry when stock comes in, first
 * expiring first out when it leaves (or oldest first with FEFO off), lots
 * carried through a transfer on the way, the batch OPENING when batches are
 * turned on, serial numbers one piece each, and the lots that are expiring.
 *
 * Example Bakery keeps milk in batches (it expires) in its kitchen and shop.
 */
final class LotsRealEngineTest extends TestCase
{
    private const AMRO = 11;

    private PDO $pdo;
    private ItemService $items;
    private StockLedger $ledger;
    private StockOperations $operations;
    private LotService $lots;
    private int $kitchen;
    private int $shop;
    /** @var array<string, string> */
    private array $settings = [];

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
        $settings = fn (int $t): array => $this->settings;
        $this->items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $hooks);
        $reasons = new ReasonCatalog($db, $workspace);
        $this->lots = new LotService($db, $settings);
        $this->ledger = new StockLedger($db, $workspace, $this->items, $units, $hooks, $reasons, $this->lots);
        $this->items->useOpeningLots($this->ledger->moveIntoOpeningLots(...));
        $this->operations = new StockOperations($db, $workspace, $this->items, $units, $this->ledger, $reasons, $settings, $hooks);
        $locations = new LocationService($db, $workspace);
        $this->kitchen = $locations->create(1, ['name' => 'Central kitchen'])['id'];
        $this->shop = $locations->create(1, ['name' => 'Shop'])['id'];
    }

    public function testStockComingInNeedsItsBatchAndExpiry(): void
    {
        $milk = $this->milk();

        $this->expectRejected('keeps batches; give the batch code', fn () => $this->open($milk, '10', []));
        $this->expectRejected('expires; give the expiry date', fn () => $this->open($milk, '10', ['lot_code' => 'M-1']));

        $this->open($milk, '10', ['lot_code' => 'M-1', 'expires_on' => '2027-01-10']);
        $lot = $this->lots->findByCode(1, $milk, 'M-1');
        self::assertNotNull($lot);
        self::assertSame('2027-01-10', $lot['expires_on']);
        self::assertSame('10', $lot['on_hand']);
    }

    public function testShelfLifeGivesTheExpiryWhenNoneIsTyped(): void
    {
        $milk = $this->milk(['shelf_life_days' => 5]);

        $this->open($milk, '4', ['lot_code' => 'M-2', 'made_on' => '2026-12-01']);

        self::assertSame('2026-12-06', $this->lots->findByCode(1, $milk, 'M-2')['expires_on'] ?? null);
    }

    public function testStockLeavingTakesTheBatchThatExpiresFirst(): void
    {
        $milk = $this->milk();
        $this->open($milk, '5', ['lot_code' => 'LATE', 'expires_on' => '2027-02-01']);
        $this->open($milk, '3', ['lot_code' => 'SOON', 'expires_on' => '2027-01-05']);

        $this->ledger->adjust(1, self::AMRO, 'adjustment', [['variant_id' => $milk, 'location_id' => $this->kitchen, 'change' => '-4']], 'damaged', '', null, null);

        self::assertSame(['LATE' => '4', 'SOON' => '0'], $this->onHandByCode($milk), 'all 3 of SOON, then 1 of LATE');
    }

    public function testWithFefoOffTheOldestBatchGoesFirst(): void
    {
        $this->settings[SettingsRegistry::INVENTORY_FEFO] = 'false';
        $milk = $this->milk();
        $this->open($milk, '5', ['lot_code' => 'LATE', 'expires_on' => '2027-02-01']);
        $this->open($milk, '3', ['lot_code' => 'SOON', 'expires_on' => '2027-01-05']);

        $this->ledger->adjust(1, self::AMRO, 'adjustment', [['variant_id' => $milk, 'location_id' => $this->kitchen, 'change' => '-4']], 'damaged', '', null, null);

        self::assertSame(['LATE' => '1', 'SOON' => '3'], $this->onHandByCode($milk));
    }

    public function testANamedBatchIsTheOneUsed(): void
    {
        $milk = $this->milk();
        $this->open($milk, '5', ['lot_code' => 'LATE', 'expires_on' => '2027-02-01']);
        $this->open($milk, '3', ['lot_code' => 'SOON', 'expires_on' => '2027-01-05']);

        $this->ledger->adjust(1, self::AMRO, 'adjustment', [['variant_id' => $milk, 'location_id' => $this->kitchen, 'change' => '-2', 'lot_code' => 'LATE']], 'damaged', '', null, null);

        self::assertSame(['LATE' => '3', 'SOON' => '3'], $this->onHandByCode($milk));
    }

    public function testATransferOnTheWayKeepsItsBatches(): void
    {
        $milk = $this->milk();
        $this->open($milk, '2', ['lot_code' => 'SOON', 'expires_on' => '2027-01-05']);
        $this->open($milk, '5', ['lot_code' => 'LATE', 'expires_on' => '2027-02-01']);

        $sent = $this->operations->transfer(1, self::AMRO, [
            'from_location_id' => $this->kitchen, 'to_location_id' => $this->shop, 'lines' => [['variant_id' => $milk, 'quantity' => '4']],
        ])['operation'];
        $line = $sent['lines'][0]['id'];
        $this->operations->receive(1, $sent['id'], self::AMRO, [['line_id' => $line, 'received' => '3']]);

        $shop = [];
        foreach ($this->lots->list(1, ['variant_id' => $milk]) as $lot) {
            foreach ($lot['places'] as $p) {
                if ($p['location_id'] === $this->shop) {
                    $shop[$lot['code']] = $p['on_hand'];
                }
            }
        }
        self::assertSame(['SOON' => '2', 'LATE' => '1'], $shop, 'what arrived came out of the batches sent, first expiring first');
        self::assertSame(['LATE' => '4', 'SOON' => '2'], $this->onHandByCode($milk), 'both places: the one lost on the way came out of LATE');
    }

    public function testTurningBatchesOnPutsTheStockIntoOpening(): void
    {
        $flour = $this->items->create(1, ['name' => 'Flour', 'sku' => 'FLR', 'unit' => 'kg'], self::AMRO)['variants'][0]['id'];
        $this->ledger->adjust(1, self::AMRO, 'opening', [['variant_id' => $flour, 'location_id' => $this->kitchen, 'change' => '20']], '', '', null, null);

        $this->items->update(1, (int) $this->items->itemIdBySku(1, 'FLR'), ['tracking' => 'lot'], self::AMRO);

        self::assertSame(['OPENING' => '20.000'], $this->onHandByCode($flour));
        self::assertSame(20000, $this->ledger->onHandSteps(1, $flour, $this->kitchen), 'the amount itself is unchanged');
    }

    public function testSerialNumbersAreOnePieceEachAndCantBeTurnedOnOverStock(): void
    {
        $mixer = $this->items->create(1, ['name' => 'Mixer', 'sku' => 'MIX', 'tracking' => 'serial'], self::AMRO)['variants'][0]['id'];

        $this->expectRejected('a serial number is one', fn () => $this->open($mixer, '2', ['lot_code' => 'SN-1']));
        $this->open($mixer, '1', ['lot_code' => 'SN-1']);
        $this->expectRejected('already in stock', fn () => $this->open($mixer, '1', ['lot_code' => 'SN-1']));

        $oven = $this->items->create(1, ['name' => 'Oven', 'sku' => 'OVN'], self::AMRO)['variants'][0]['id'];
        $this->ledger->adjust(1, self::AMRO, 'opening', [['variant_id' => $oven, 'location_id' => $this->kitchen, 'change' => '2']], '', '', null, null);
        $this->expectRejected('serial numbers can\'t be turned on', fn () => $this->items->update(1, (int) $this->items->itemIdBySku(1, 'OVN'), ['tracking' => 'serial'], self::AMRO));
    }

    public function testAnItemWithoutBatchesRefusesABatchCode(): void
    {
        $flour = $this->items->create(1, ['name' => 'Flour', 'sku' => 'FLR'], self::AMRO)['variants'][0]['id'];

        $this->expectRejected('doesn\'t keep batches', fn () => $this->open($flour, '1', ['lot_code' => 'X']));
    }

    public function testExpiringBatchesAreToldOnceEach(): void
    {
        $milk = $this->milk();
        $this->open($milk, '2', ['lot_code' => 'OLD', 'expires_on' => '2026-11-30']);
        $this->open($milk, '2', ['lot_code' => 'SOON', 'expires_on' => '2026-12-05']);
        $this->open($milk, '2', ['lot_code' => 'LATER', 'expires_on' => '2027-03-01']);

        self::assertSame(['OLD', 'SOON'], array_column($this->lots->expiring(1, 7, '2026-12-01'), 'code'));
        $due = $this->lots->due('2026-12-01');
        self::assertSame([['OLD', 'expired'], ['SOON', 'expiring']], array_map(static fn (array $d): array => [$d['code'], $d['state']], $due));

        foreach ($due as $d) {
            $this->lots->markTold($d['tenant_id'], $d['id'], $d['state'], '2026-12-01');
        }
        self::assertSame([], $this->lots->due('2026-12-01'), 'each is told once');
        self::assertSame([['SOON', 'expired']], array_map(static fn (array $d): array => [$d['code'], $d['state']], $this->lots->due('2026-12-06')));
    }

    public function testTheDailyJobFiresEachExpiryEventOnce(): void
    {
        $milk = $this->milk();
        $this->open($milk, '2', ['lot_code' => 'OLD', 'expires_on' => '2026-11-30']);
        $this->open($milk, '2', ['lot_code' => 'SOON', 'expires_on' => '2026-12-05']);
        $hooks = new HookManager();
        $heard = [];
        foreach ([Events::STOCK_LOT_EXPIRING, Events::STOCK_LOT_EXPIRED] as $event) {
            $hooks->listen($event, function (array $data) use ($event, &$heard): array {
                $heard[] = [$event, $data['code'], $data['on_hand']];

                return $data;
            });
        }
        $job = new LotExpiryJob($this->lots, $hooks, static fn (): string => '2026-12-01');

        self::assertSame(['expiring' => 1, 'expired' => 1], $job->handle([]));
        self::assertSame([[Events::STOCK_LOT_EXPIRED, 'OLD', '2'], [Events::STOCK_LOT_EXPIRING, 'SOON', '2']], $heard);
        self::assertSame(['expiring' => 0, 'expired' => 0], $job->handle([]), 'a second run the same day says nothing again');
    }

    public function testAnotherWorkspaceDoesNotSeeTheBatches(): void
    {
        $milk = $this->milk();
        $this->open($milk, '2', ['lot_code' => 'M-1', 'expires_on' => '2027-01-10']);

        self::assertNull($this->lots->findByCode(2, $milk, 'M-1'));
        self::assertSame([], $this->lots->list(2, ['variant_id' => $milk]));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function milk(array $extra = []): int
    {
        return $this->items->create(1, ['name' => 'Milk', 'sku' => 'MLK', 'tracking' => 'lot', 'expires' => true] + $extra, self::AMRO)['variants'][0]['id'];
    }

    /** @param array<string, mixed> $lot */
    private function open(int $variant, string $quantity, array $lot): void
    {
        $this->ledger->adjust(1, self::AMRO, 'opening', [['variant_id' => $variant, 'location_id' => $this->kitchen, 'change' => $quantity] + $lot], '', '', null, null);
    }

    /** @return array<string, string> */
    private function onHandByCode(int $variant): array
    {
        $out = [];
        foreach ($this->lots->list(1, ['variant_id' => $variant]) as $lot) {
            $out[$lot['code']] = $lot['on_hand'];
        }
        ksort($out);

        return $out;
    }

    private function expectRejected(string $message, callable $work): void
    {
        try {
            $work();
        } catch (InventoryRejectedException $e) {
            self::assertStringContainsString($message, $e->clientMessage);

            return;
        }
        self::fail('Expected a refusal: ' . $message);
    }
}
