<?php

declare(strict_types=1);

namespace Tests\Integration\Accounting;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\Periods;
use Whity\Core\Accounting\StockBooks;
use Whity\Core\Accounting\StockCostInboxSource;
use Whity\Core\Inventory\InventoryCosts;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\LocationService;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Contribute\WorkspaceAccountingExport;
use Whity\Sdk\Inventory\StockPosting;

/**
 * Stock in the books against the real schema (WHIT-1318 A4a, design section
 * 32 §6, section 31 D1, register IV.18, IV.22, AC.18): the moving average
 * cost, each stock batch posted to the stock journal through the accounting
 * export, stock that existed before brought in once at its cost, the switch,
 * moves with no cost waiting, and a closed month refusing the stock move.
 *
 * Example Bakery is tenant 1, in dinars (3 decimals).
 */
final class StockBooksRealEngineTest extends TestCase
{
    private const AMRO = 11;

    private PDO $pdo;
    private AccountingWorkspace $books;
    private Ledger $ledger;
    private Periods $periods;
    private InventoryWorkspace $places;
    private ItemService $items;
    private UnitCatalog $units;
    private LocationService $locations;
    private InventoryCosts $costs;
    private StockBooks $stock;
    private StockLedger $plain;
    private StockLedger $booked;
    private int $flour;
    private int $kitchen;
    private string $today;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->today = gmdate('Y-m-d');
        $db = new AccountingDb($this->pdo);
        $this->books = new AccountingWorkspace($db, static fn (int $t): string => 'JOD');
        $this->periods = new Periods($db, $this->books);
        $this->ledger = new Ledger($db, $this->books, $this->periods, new SequenceCounters($this->pdo));
        $invDb = new InventoryDb($this->pdo);
        $this->places = new InventoryWorkspace($invDb);
        $this->units = new UnitCatalog($invDb, $this->places);
        $this->items = new ItemService($invDb, $this->places, $this->units, static fn (int $t): string => 'JOD');
        $this->locations = new LocationService($invDb, $this->places);
        $this->costs = new InventoryCosts($invDb, static fn (int $t): string => 'JOD');
        $this->stock = new StockBooks($db, $this->books, $this->ledger, $this->costs);
        // Before A4: stock with no cost and no books. After: costs and the export.
        $this->plain = new StockLedger($invDb, $this->places, $this->items, $this->units, null, null, null, null, static fn (): ?WorkspaceAccountingExport => null);
        $this->booked = new StockLedger($invDb, $this->places, $this->items, $this->units, null, null, null, $this->costs, fn (): WorkspaceAccountingExport => $this->stock);
        $this->flour = (int) $this->items->create(1, ['name' => 'Flour, 1 kg bag', 'unit' => 'pc', 'purchase_price_minor' => 1000], self::AMRO)['variants'][0]['id'];
        $this->kitchen = (int) $this->locations->create(1, ['name' => 'Central kitchen'])['id'];
    }

    public function testStockThatExistedIsBroughtInOnceAndEachChangePosts(): void
    {
        // 10 kg there before stock was in the books.
        $this->plain->adjust(1, self::AMRO, 'opening', [['variant_id' => $this->flour, 'location_id' => $this->kitchen, 'change' => '10']], '', '', null, null);

        // A receipt of 5 kg at 1.200: the first change brings the 10 kg in at 1.000, then posts the receipt.
        $this->receive(5, 1200);
        self::assertSame(10000, $this->balance('3900') * -1, 'the stock that was there, against Opening balances');
        self::assertSame(-6000, $this->balance('2160'), 'received, not yet billed');
        self::assertSame(16000, $this->balance('1300'));
        self::assertSame(['quantity' => 15, 'value_minor' => 16000, 'average_minor' => 1067, 'currency' => 'JOD'], $this->costs->of(1, $this->flour));

        // 3 kg wasted, at the average: 16.000 × 3 / 15.
        $waste = $this->booked->adjust(1, self::AMRO, 'adjustment', [['variant_id' => $this->flour, 'location_id' => $this->kitchen, 'change' => '-3']], 'damaged', '', null, null);
        self::assertSame(3200, $waste['moves'][0]['value_minor'] ?? $this->moveValue($waste['batch_key']));
        self::assertSame(3200, $this->balance('5150'));
        self::assertSame(12800, $this->balance('1300'));

        // Undone: the mirror, exactly.
        $this->booked->reverse(1, $waste['batch_key'], self::AMRO, 'Counted wrong');
        self::assertSame(0, $this->balance('5150'));
        self::assertSame(16000, $this->balance('1300'));

        // A transfer between real places posts nothing.
        $pantry = (int) $this->locations->create(1, ['name' => 'Pantry'])['id'];
        $entries = $this->entries();
        $this->booked->post(1, self::AMRO, [['variant_id' => $this->flour, 'quantity' => '2', 'from_location_id' => $this->kitchen, 'to_location_id' => $pantry]], ['source_type' => 'transfer']);
        self::assertSame($entries, $this->entries());

        $status = $this->stock->status(1);
        self::assertTrue($status['stock_in_books']);
        self::assertSame($status['stock_value_minor'], $status['stock_account_minor'], 'the Stock account matches the stock value');
        self::assertTrue($this->ledger->verifyChain(1)['intact']);
    }

    public function testTheSwitchStopsPostingAndOnAgainPostsOnlyTheDifference(): void
    {
        $this->receive(10, 1000);
        self::assertSame(10000, $this->balance('1300'));

        $this->stock->setOn(1, false);
        $this->receive(5, 1000);
        self::assertSame(10000, $this->balance('1300'), 'nothing posts while off');

        $this->stock->setOn(1, true);
        $opened = $this->stock->openNow(1, self::AMRO);
        self::assertTrue($opened['posted']);
        self::assertSame(5000, $opened['amount_minor'], 'only what the books missed');
        self::assertSame(15000, $this->balance('1300'));

        $this->expectException(\Whity\Core\Accounting\AccountingRejectedException::class);
        $this->stock->openNow(1, self::AMRO);
    }

    public function testAMoveWithNoCostWaitsForAPerson(): void
    {
        $sugar = (int) $this->items->create(1, ['name' => 'Sugar', 'unit' => 'pc'], self::AMRO)['variants'][0]['id'];
        $this->receive(1, 1000);
        $this->booked->adjust(1, self::AMRO, 'opening', [['variant_id' => $sugar, 'location_id' => $this->kitchen, 'change' => '4']], '', '', null, null);

        $waiting = $this->stock->waiting(1);
        self::assertCount(1, $waiting);
        self::assertSame('Sugar', $waiting[0]['item_name']);
        self::assertSame(1000, $this->balance('1300'), 'sugar with no cost posts nothing');
        self::assertSame(1, $this->stock->status(1)['waiting']);
    }

    public function testACostPerKilogramValuesStockKeptInGrams(): void
    {
        $sugar = (int) $this->items->create(1, ['name' => 'Sugar', 'unit' => 'kg', 'purchase_price_minor' => 800], self::AMRO)['variants'][0]['id'];
        $this->plain->adjust(1, self::AMRO, 'opening', [['variant_id' => $sugar, 'location_id' => $this->kitchen, 'change' => '2.5']], '', '', null, null);
        $cost = $this->costs->of(1, $sugar);
        self::assertSame(2500, $cost['quantity'], 'in grams');
        self::assertSame(2000, $cost['value_minor'], '2.5 kg at 0.800 a kilogram');
    }

    public function testAClosedMonthRefusesTheStockMoveToo(): void
    {
        $this->receive(5, 1000);
        $month = $this->periods->periodFor(1, $this->today);
        $this->periods->close(1, $month['id'], self::AMRO);
        try {
            $this->booked->adjust(1, self::AMRO, 'adjustment', [['variant_id' => $this->flour, 'location_id' => $this->kitchen, 'change' => '-1']], 'damaged', '', null, null);
            self::fail('a closed month in the books is closed for stock');
        } catch (InventoryRejectedException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('period_closed', $e->extra['code'] ?? null);
            self::assertSame('accounting:close', $e->extra['reopened_with'] ?? null);
            // Plain words: the month is closed in the books, and who can reopen it.
            self::assertStringContainsString('that month is closed in the books', $e->getMessage());
            self::assertStringContainsString('close and reopen months in Accounting (an admin or the accountant) can reopen it', $e->getMessage());
        }
        self::assertSame(5, $this->costs->of(1, $this->flour)['quantity'], 'the stock did not move');
        $level = $this->pdo->query('SELECT SUM(on_hand) FROM inv_levels WHERE tenant_id = 1');
        self::assertNotFalse($level);
        self::assertSame(5, (int) $level->fetchColumn());
    }

    /**
     * Live (AAU IRB shape): stock already on hand, most of it with no purchase
     * price, and nobody runs the CLI. The first stock change brings in only
     * what has a cost, posts nothing for the rest, and lists it as waiting;
     * giving it a cost then posts it.
     */
    public function testTheFirstChangeOnALiveWorkspaceWithUncostedStockPostsNothingWrong(): void
    {
        $gloves = (int) $this->items->create(1, ['name' => 'Nitrile gloves, box', 'unit' => 'pc'], self::AMRO)['variants'][0]['id'];
        $swabs = (int) $this->items->create(1, ['name' => 'Sterile swabs', 'unit' => 'pc'], self::AMRO)['variants'][0]['id'];
        $this->plain->adjust(1, self::AMRO, 'opening', [
            ['variant_id' => $gloves, 'location_id' => $this->kitchen, 'change' => '40'],
            ['variant_id' => $swabs, 'location_id' => $this->kitchen, 'change' => '200'],
            ['variant_id' => $this->flour, 'location_id' => $this->kitchen, 'change' => '3'],
        ], '', '', null, null);
        self::assertSame(0, $this->entries(), 'nothing in the books before the first change');
        // The deploy comes later (SQLite keeps seconds only; PostgreSQL's cutover is the first batch's own instant).
        sleep(1);

        // The first change after the deploy: two boxes of gloves used.
        $this->booked->post(1, self::AMRO, [[
            'variant_id' => $gloves, 'quantity' => '2', 'from_location_id' => $this->kitchen, 'to_location_id' => $this->places->virtualId(1, 'consumed'),
        ]], ['source_type' => 'adjustment']);

        self::assertSame(3000, $this->balance('1300'), 'only the flour, which has a price, is brought in');
        self::assertSame(-3000, $this->balance('3900'));
        self::assertSame(0, $this->balance('5100'), 'gloves with no cost post no cost of sales');
        $status = $this->stock->status(1);
        self::assertSame($status['stock_value_minor'], $status['stock_account_minor']);
        self::assertSame(2, $status['items_without_cost']);

        $waiting = $this->stock->waitingAll(1);
        self::assertSame(['Nitrile gloves, box', 'Sterile swabs'], array_column($waiting['items'], 'item_name'));
        self::assertSame([38, 200], array_column($waiting['items'], 'quantity'));
        self::assertCount(1, $waiting['moves'], 'the gloves used');

        // Waiting for me in the Inbox, for whoever can set accounting up, and nobody else.
        $inbox = new StockCostInboxSource(new AccountingDb($this->pdo), static fn (int $p, string $perm, int $t): bool => $p === self::AMRO && $perm === 'accounting:settings');
        self::assertSame(2, $inbox->count(1, self::AMRO, true));
        self::assertSame(0, $inbox->count(1, 12, true));
        self::assertSame('Nitrile gloves, box', $inbox->list(1, self::AMRO, true, 10, 0)[1]->title);

        // A person gives the gloves a cost: their stock goes into the books.
        $given = $this->stock->setCost(1, $gloves, 2500, self::AMRO);
        self::assertSame(95000, $given['value_minor'], '38 boxes at 2.500');
        self::assertSame(98000, $this->balance('1300'));
        self::assertSame(1, $inbox->count(1, self::AMRO, true));
        $status = $this->stock->status(1);
        self::assertSame($status['stock_value_minor'], $status['stock_account_minor']);
        self::assertTrue($this->ledger->verifyChain(1)['intact']);
    }

    /**
     * Off means inventory as before A4: the export is never called, so a
     * closed month in the books cannot refuse a stock change.
     */
    public function testOffNeverCallsTheExportAndAClosedMonthDoesNotStopStock(): void
    {
        $calls = 0;
        $spy = new class ($this->stock, $calls) implements WorkspaceAccountingExport {
            public function __construct(private readonly StockBooks $inner, private int &$calls)
            {
            }

            public function keepsStock(int $workspaceId): bool
            {
                return $this->inner->keepsStock($workspaceId);
            }

            public function record(StockPosting $posting): ?string
            {
                $this->calls++;

                return $this->inner->record($posting);
            }
        };
        $ledger = new StockLedger(new InventoryDb($this->pdo), $this->places, $this->items, $this->units, null, null, null, $this->costs, static fn (): WorkspaceAccountingExport => $spy);

        $ledger->adjust(1, self::AMRO, 'adjustment', [['variant_id' => $this->flour, 'location_id' => $this->kitchen, 'change' => '4']], 'found', '', null, null);
        self::assertSame(1, $calls, 'on: each stock change goes to the books');

        $this->stock->setOn(1, false);
        $month = $this->periods->periodFor(1, $this->today);
        $this->periods->close(1, $month['id'], self::AMRO);
        $entries = $this->entries();
        $ledger->adjust(1, self::AMRO, 'adjustment', [['variant_id' => $this->flour, 'location_id' => $this->kitchen, 'change' => '-1']], 'damaged', '', null, null);
        self::assertSame(1, $calls, 'off: no call at all');
        self::assertSame($entries, $this->entries());
        self::assertSame(3, $this->costs->of(1, $this->flour)['quantity'], 'the stock moved, closed month or not');
    }

    public function testAPricedReceiptOfAWeighedItemIsValuedPerKilogram(): void
    {
        $sugar = (int) $this->items->create(1, ['name' => 'Sugar', 'unit' => 'kg'], self::AMRO)['variants'][0]['id'];
        $this->booked->post(1, self::AMRO, [[
            'variant_id' => $sugar, 'quantity' => '2.5',
            'from_location_id' => $this->places->virtualId(1, 'supplier'), 'to_location_id' => $this->kitchen,
            'unit_cost_minor' => 800, 'currency' => 'JOD',
        ]], ['source_type' => 'receipt']);
        self::assertSame(2000, $this->costs->of(1, $sugar)['value_minor'], '2.5 kg at 0.800 a kilogram, not 2,500 grams at 0.800');
        self::assertSame(2000, $this->balance('1300'));
    }

    private function receive(int $kg, int $unitCost): void
    {
        $this->booked->post(1, self::AMRO, [[
            'variant_id' => $this->flour, 'quantity' => (string) $kg,
            'from_location_id' => $this->places->virtualId(1, 'supplier'), 'to_location_id' => $this->kitchen,
            'unit_cost_minor' => $unitCost, 'currency' => 'JOD',
        ]], ['source_type' => 'receipt']);
    }

    private function moveValue(string $batchKey): int
    {
        $statement = $this->pdo->prepare('SELECT value_minor FROM inv_moves WHERE tenant_id = 1 AND batch_key = :k');
        $statement->execute([':k' => $batchKey]);

        return (int) $statement->fetchColumn();
    }

    private function entries(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM acc_entries WHERE tenant_id = 1');
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function balance(string $code): int
    {
        $this->books->ensure(1);
        $statement = $this->pdo->prepare('SELECT id FROM acc_accounts WHERE tenant_id = 1 AND code = :code');
        $statement->execute([':code' => $code]);

        return $this->ledger->balance(1, (int) $statement->fetchColumn(), '2027-12-31');
    }
}
