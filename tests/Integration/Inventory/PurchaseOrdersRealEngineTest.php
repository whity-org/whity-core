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
use Whity\Core\Inventory\OrderRouteSubject;
use Whity\Core\Inventory\PurchaseOrders;
use Whity\Core\Inventory\ReasonCatalog;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\StockOperations;
use Whity\Core\Inventory\SupplierItems;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * Purchase orders against the real schema (WHIT-1234, design 31 §3.7,
 * register IV.12): prices from what the supplier sells, totals, sending with
 * no route, receiving in parts with the order's cost on each move, the
 * over-receipt rule, closing a short line, and tenant isolation.
 *
 * Example Bakery (tenant 1) buys flour from Jordan Flour Mills in 25 kg bags
 * at $16.00 a bag, received into the Central kitchen.
 */
final class PurchaseOrdersRealEngineTest extends TestCase
{
    private const AMRO = 11;

    private PDO $pdo;
    private PurchaseOrders $orders;
    private SupplierItems $suppliers;
    private StockLedger $ledger;
    private SettingsService $settings;
    private HookManager $hooks;
    private int $flour;
    private int $kitchen;
    private int $mills;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec("INSERT INTO contacts (id, tenant_id, name, is_customer, is_supplier) VALUES (31, 1, 'Jordan Flour Mills', false, true), (32, 1, 'Example Dairy', true, false)");
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $this->hooks = new HookManager();
        foreach ([Events::INVENTORY_ORDER_CREATED, Events::INVENTORY_ORDER_APPROVED, Events::INVENTORY_ORDER_PARTLY_DONE, Events::INVENTORY_ORDER_DONE, Events::INVENTORY_ORDER_REJECTED] as $event) {
            $this->hooks->listen($event, function (array $data) use ($event): array {
                $this->events[] = $event;

                return $data;
            });
        }
        $items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $this->hooks);
        $reasons = new ReasonCatalog($db, $workspace);
        $this->ledger = new StockLedger($db, $workspace, $items, $units, $this->hooks, $reasons);
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $effective = fn (int $t): array => $this->settings->effective($t);
        $operations = new StockOperations($db, $workspace, $items, $units, $this->ledger, $reasons, $effective, $this->hooks);
        $this->suppliers = new SupplierItems($db, $items, $units);
        $this->orders = new PurchaseOrders($db, $workspace, $items, $units, $operations, $this->suppliers, new SequenceCounters($this->pdo), $effective, static fn (int $t): string => 'USD', $this->hooks);

        $this->flour = $items->create(1, ['name' => ['en' => 'Flour', 'ar' => 'طحين'], 'unit' => 'kg', 'sku' => 'FLR', 'units' => [['unit' => 'bag', 'per' => '25', 'purpose' => 'purchase']]], self::AMRO)['variants'][0]['id'];
        $this->kitchen = (new LocationService($db, $workspace))->create(1, ['name' => 'Central kitchen'])['id'];
        $this->mills = 31;
        $this->suppliers->set(1, ['variant_id' => $this->flour, 'contact_id' => $this->mills, 'unit' => 'bag', 'price_minor' => 1600, 'is_preferred' => true, 'supplier_sku' => 'JFM-25'], static fn (int $t): string => 'USD');
    }

    public function testTheSuppliersPriceFillsTheLineAndTheTotalsAddUp(): void
    {
        $order = $this->draft('24');

        self::assertSame('PO-0001', $order['number']);
        self::assertSame('draft', $order['status']);
        self::assertSame('Jordan Flour Mills', $order['contact_name']);
        self::assertSame(1600, $order['lines'][0]['price_minor']);
        self::assertSame('24', $order['lines'][0]['quantity']);
        self::assertSame('bag', $order['lines'][0]['unit']);
        self::assertSame('600.000', $order['lines'][0]['base_quantity']);
        self::assertSame(38400, $order['subtotal_minor'], '24 bags at $16.00');
        self::assertSame(1920, $order['tax_minor'], '5% tax');
        self::assertSame(40320, $order['total_minor']);
        self::assertSame([Events::INVENTORY_ORDER_CREATED], $this->events);
    }

    public function testSentWithNoRouteItIsApprovedAndIsReceivedInPartsAtTheOrdersCost(): void
    {
        $order = $this->orders->submit(1, $this->draft('24')['id'], self::AMRO);
        self::assertSame('approved', $order['status'], 'no purchase route: the sender confirms it (IV.19)');
        self::assertSame('Amro Saleh', $order['approved_by_name']);

        $line = $order['lines'][0]['id'];
        $first = $this->orders->receive(1, $order['id'], self::AMRO, [['line_id' => $line, 'quantity' => '10']]);
        self::assertSame('partly_done', $first['order']['status']);
        self::assertSame('10', $first['order']['lines'][0]['received']);
        self::assertSame('GRN-0001', $first['receipt']['number']);
        self::assertSame('250.000', $this->onHand());

        $moves = $this->ledger->batch(1, (string) $first['receipt']['batch_key']);
        self::assertSame(64, $moves[0]['unit_cost_minor'], '$16.00 a 25 kg bag is $0.64 a kg');
        self::assertSame('USD', $moves[0]['currency']);
        self::assertSame('receipt', $moves[0]['source_type']);

        $rest = $this->orders->receive(1, $order['id'], self::AMRO, [['line_id' => $line, 'quantity' => '350', 'unit' => 'kg']]);
        self::assertSame('done', $rest['order']['status']);
        self::assertSame('600.000', $this->onHand());
        self::assertCount(2, $rest['order']['receipts']);
        self::assertSame(
            [Events::INVENTORY_ORDER_CREATED, Events::INVENTORY_ORDER_APPROVED, Events::INVENTORY_ORDER_PARTLY_DONE, Events::INVENTORY_ORDER_DONE],
            $this->events
        );
    }

    public function testReceivingMoreThanOrderedAsksAndTheToleranceLetsALittleThrough(): void
    {
        $order = $this->orders->submit(1, $this->draft('10')['id'], self::AMRO);
        $line = $order['lines'][0]['id'];
        try {
            $this->orders->receive(1, $order['id'], self::AMRO, [['line_id' => $line, 'quantity' => '11']]);
            self::fail('anything over asks by default');
        } catch (InventoryRejectedException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('over_receipt', $e->extra['code']);
        }
        self::assertSame('0.000', $this->onHand(), 'nothing moved');

        $this->settings->setTenant(1, SettingsRegistry::INVENTORY_OVER_RECEIPT_PERCENT, '10');
        $done = $this->orders->receive(1, $order['id'], self::AMRO, [['line_id' => $line, 'quantity' => '11']]);
        self::assertSame('done', $done['order']['status']);
        self::assertTrue($done['order']['lines'][0]['is_over']);

        $again = $this->orders->submit(1, $this->draft('1')['id'], self::AMRO);
        $big = $this->orders->receive(1, $again['id'], self::AMRO, [['line_id' => $again['lines'][0]['id'], 'quantity' => '3']], true);
        self::assertSame('3', $big['order']['lines'][0]['received'], 'a person can say yes to more');
    }

    public function testAShortLineIsClosedAndTheOrderIsDone(): void
    {
        $order = $this->orders->submit(1, $this->draft('10')['id'], self::AMRO);
        $line = $order['lines'][0]['id'];
        $this->orders->receive(1, $order['id'], self::AMRO, [['line_id' => $line, 'quantity' => '6']]);

        try {
            $this->orders->cancel(1, $order['id'], self::AMRO);
            self::fail('part of it arrived');
        } catch (InventoryRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        $closed = $this->orders->closeLine(1, $order['id'], $line, self::AMRO);
        self::assertSame('done', $closed['status']);
        self::assertTrue($closed['lines'][0]['is_closed']);
    }

    public function testTheRouteApprovesOrSendsBackAndTheSubmittingHookCanRefuse(): void
    {
        $subject = new OrderRouteSubject(new InventoryDb($this->pdo), $this->orders);
        $order = $this->draft('5');
        $this->pdo->exec("UPDATE inv_orders SET status = 'waiting_approval' WHERE id = " . $order['id']);
        self::assertSame('Purchase order PO-0001', $subject->title(1, $order['id']));
        self::assertNull($subject->title(2, $order['id']), 'another workspace sees nothing');

        $subject->rejected(1, $order['id'], 1);
        self::assertSame('draft', $this->orders->get(1, $order['id'])['status']);
        $this->pdo->exec("UPDATE inv_orders SET status = 'waiting_approval' WHERE id = " . $order['id']);
        $subject->approved(1, $order['id'], 1);
        self::assertSame('approved', $this->orders->get(1, $order['id'])['status']);

        $this->hooks->listen(Events::INVENTORY_ORDER_SUBMITTING, static function (array $data): array {
            throw new HookVetoException(Events::INVENTORY_ORDER_SUBMITTING, 'Over this month\'s flour budget');
        });
        try {
            $this->orders->submit(1, $this->draft('5')['id'], self::AMRO);
            self::fail('the hook refused it');
        } catch (InventoryRejectedException $e) {
            self::assertSame('Over this month\'s flour budget', $e->clientMessage);
        }
    }

    public function testOnlyASupplierADraftCanChangeAndAnotherWorkspaceSeesNothing(): void
    {
        try {
            $this->orders->create(1, self::AMRO, ['contact_id' => 32, 'location_id' => $this->kitchen, 'lines' => [['variant_id' => $this->flour, 'quantity' => '1']]]);
            self::fail('Example Dairy is not marked as a supplier');
        } catch (InventoryRejectedException $e) {
            self::assertSame('contact_id', $e->extra['field']);
        }

        $order = $this->draft('2');
        $changed = $this->orders->update(1, $order['id'], ['notes' => 'Deliver before 9', 'lines' => [['variant_id' => $this->flour, 'quantity' => '3', 'unit' => 'bag']]]);
        self::assertSame('Deliver before 9', $changed['notes']);
        self::assertSame(4800, $changed['subtotal_minor']);

        $key = ['idempotency_key' => 'mcp-9'];
        $data = ['contact_id' => $this->mills, 'location_id' => $this->kitchen, 'lines' => [['variant_id' => $this->flour, 'quantity' => '1', 'unit' => 'bag']]];
        $a = $this->orders->create(1, self::AMRO, $data, $key);
        $b = $this->orders->create(1, self::AMRO, $data, $key);
        self::assertTrue($b['repeated']);
        self::assertSame($a['order']['id'], $b['order']['id']);

        self::assertSame([], $this->orders->list(2));
        $this->expectException(InventoryRejectedException::class);
        $this->orders->get(2, $order['id']);
    }

    /** @return array<string, mixed> */
    private function draft(string $bags): array
    {
        return $this->orders->create(1, self::AMRO, [
            'contact_id' => $this->mills, 'location_id' => $this->kitchen, 'expected_on' => '2026-11-26',
            'lines' => [['variant_id' => $this->flour, 'quantity' => $bags, 'unit' => 'bag', 'tax_bp' => 500]],
        ])['order'];
    }

    private function onHand(): string
    {
        $levels = $this->ledger->levels(1, ['variant_id' => $this->flour, 'location_id' => $this->kitchen]);

        return $levels === [] ? '0.000' : $levels[0]['on_hand'];
    }
}
