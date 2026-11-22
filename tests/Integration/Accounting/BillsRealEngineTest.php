<?php

declare(strict_types=1);

namespace Tests\Integration\Accounting;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\CommissionBooks;
use Whity\Core\Accounting\InvoiceApprovals;
use Whity\Core\Accounting\Invoices;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\Payments;
use Whity\Core\Accounting\Periods;
use Whity\Core\Accounting\Receivables;
use Whity\Core\Accounting\Taxes;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Calendar\CommissionClose;
use Whity\Sdk\Calendar\CommissionCloseLine;
use Whity\Sdk\Contribute\WorkspaceEInvoicing;
use Whity\Sdk\Hooks\Events;

/**
 * Supplier bills and payments made against the real schema (WHIT-1318 A3a,
 * design section 32 §5.3, §5.4): the entry a bill posts with the tax to
 * reclaim, the bill route, supplier credits, paying a supplier, cheques we
 * wrote, aged payables, the supplier statement, salon commission booked, and
 * refusals.
 *
 * Example Bakery is tenant 1, in dinars (3 decimals).
 */
final class BillsRealEngineTest extends TestCase
{
    private const AMRO = 11;

    private PDO $pdo;
    private AccountingWorkspace $books;
    private Ledger $ledger;
    private Invoices $invoices;
    private Payments $payments;
    private Receivables $receivables;
    private string $today = '2026-11-22';
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                    (12, 'Awss Haddad', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec(
            "INSERT INTO contacts (id, tenant_id, name, is_customer, is_supplier) VALUES
             (41, 1, 'Example Cafe', true, false), (42, 1, 'Jordan Flour Mills', false, true)"
        );
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new AccountingDb($this->pdo);
        $hooks = new HookManager();
        foreach ([Events::ACCOUNTING_BILL_POSTED, Events::ACCOUNTING_BILL_PAID, Events::ACCOUNTING_INVOICE_ISSUED] as $event) {
            $hooks->listen($event, function (array $data) use ($event): array {
                $this->events[] = $event;

                return $data;
            });
        }
        $this->books = new AccountingWorkspace($db, static fn (int $t): string => 'JOD');
        $this->ledger = new Ledger($db, $this->books, new Periods($db, $this->books), new SequenceCounters($this->pdo));
        $invDb = new InventoryDb($this->pdo);
        $invWorkspace = new InventoryWorkspace($invDb);
        $units = new UnitCatalog($invDb, $invWorkspace);
        $clock = fn (): \DateTimeImmutable => new \DateTimeImmutable($this->today);
        $this->invoices = new Invoices(
            $db,
            $this->books,
            $this->ledger,
            new Taxes($db, $this->books),
            new ItemService($invDb, $invWorkspace, $units, static fn (int $t): string => 'JOD'),
            $units,
            new SequenceCounters($this->pdo),
            static fn (int $t): array => ['accounting.invoice_payment_terms_days' => '30'],
            $hooks,
            static fn (): ?WorkspaceEInvoicing => null,
            $clock,
        );
        $this->payments = new Payments($db, $this->books, $this->ledger, $this->invoices, new SequenceCounters($this->pdo), $hooks, $clock);
        $this->receivables = new Receivables($db, $this->books);
    }

    public function testABillPostsTheCostAndTheTaxToReclaim(): void
    {
        $draft = $this->draft(100000, '6200');
        self::assertSame('purchase', $draft['kind']);
        self::assertSame('FM-7781', $draft['supplier_number']);
        self::assertSame(116000, $draft['total_minor']);

        $bill = $this->invoices->issue(1, $draft['id'], self::AMRO);
        self::assertSame('BIL-2026-0001', $bill['number']);
        self::assertSame('issued', $bill['status']);
        self::assertSame('none', $bill['einvoice_status'], 'bills are not reported to the tax authority');
        self::assertSame([Events::ACCOUNTING_BILL_POSTED], $this->events);

        $entry = $this->ledger->entry(1, (int) $bill['entry_id']);
        self::assertSame('PUR', $entry['journal_code']);
        self::assertSame('bill', $entry['source_type']);
        $byCode = [];
        foreach ($entry['lines'] as $l) {
            $byCode[$l['account_code']] = [$l['debit_minor'], $l['credit_minor'], $l['tax_base_minor']];
        }
        self::assertSame([0, 116000, null], $byCode['2100'], 'we owe the supplier the total');
        self::assertSame([100000, 0, 100000], $byCode['6200'], 'rent, with its tax base');
        self::assertSame([16000, 0, null], $byCode['1400'], 'the tax we can reclaim');
        self::assertSame(-116000, $this->balance('2100'));
    }

    public function testASupplierCreditAndAPaymentSettleTheBill(): void
    {
        $bill = $this->invoices->issue(1, $this->draft(50000)['id'], self::AMRO);
        $credit = $this->invoices->credit(1, $bill['id'], self::AMRO, ['reason' => 'Short delivery', 'lines' => [['line_id' => $bill['lines'][0]['id'], 'quantity' => '0.2']]])['invoice'];
        self::assertSame('purchase_credit', $credit['kind']);
        self::assertSame('SCR-2026-0001', $credit['number']);
        self::assertSame(11600, $credit['total_minor'], 'a fifth of 58.000');
        self::assertSame(46400, $this->invoices->get(1, $bill['id'])['due_minor']);

        $paid = $this->payments->pay(1, self::AMRO, ['contact_id' => 42, 'amount' => '46.400', 'method' => 'cliq', 'invoice_id' => $bill['id']])['payment'];
        self::assertSame('PAY-2026-0001', $paid['number']);
        self::assertSame('out', $paid['direction']);
        self::assertSame('paid', $this->invoices->get(1, $bill['id'])['status']);
        self::assertContains(Events::ACCOUNTING_BILL_PAID, $this->events);
        self::assertSame(0, $this->balance('2100'), 'nothing owed to the supplier');
        self::assertSame(-46400, $this->balance('1110'));
        self::assertTrue($this->ledger->verifyChain(1)['intact']);
    }

    public function testACheckWeWroteWaitsThenClears(): void
    {
        $bill = $this->invoices->issue(1, $this->draft(30000)['id'], self::AMRO);
        $cheque = $this->payments->pay(1, self::AMRO, [
            'contact_id' => 42, 'amount_minor' => 34800, 'method' => 'cheque', 'cheque_number' => '55010', 'cheque_due_on' => '2026-12-30', 'invoice_id' => $bill['id'],
        ])['payment'];
        self::assertSame('waiting', $cheque['cheque_status']);
        self::assertSame(-34800, $this->balance('2150'), 'on Cheques we wrote until it clears');
        self::assertSame(0, $this->balance('1110'));

        $cleared = $this->payments->deposit(1, $cheque['id'], self::AMRO, '2026-12-30');
        self::assertSame('cleared', $cleared['cheque_status']);
        self::assertSame(0, $this->balance('2150'));
        self::assertSame(-34800, $this->balance('1110'));
    }

    public function testAgedPayablesAndTheSupplierStatement(): void
    {
        $this->today = '2026-09-01';
        $old = $this->invoices->issue(1, $this->draft(10000)['id'], self::AMRO);
        $this->today = '2026-11-22';
        $this->invoices->issue(1, $this->draft(20000)['id'], self::AMRO);
        $this->payments->pay(1, self::AMRO, ['contact_id' => 42, 'amount_minor' => 1600, 'invoice_id' => $old['id'], 'paid_on' => '2026-10-01']);

        $aged = $this->receivables->aged(1, '2026-11-22', null, 'purchase');
        self::assertCount(1, $aged['rows']);
        self::assertSame('Jordan Flour Mills', $aged['rows'][0]['contact_name']);
        self::assertSame(10000, $aged['rows'][0]['days_31_60'], 'due 1 October, 52 days late');
        self::assertSame(23200, $aged['rows'][0]['not_due']);
        self::assertSame(33200, $aged['totals']['balance_minor']);
        self::assertSame(-33200, $this->balance('2100'), 'the report matches what the books owe');

        $statement = $this->receivables->statement(1, 42, '2026-10-01', '2026-11-22', 'purchase');
        self::assertSame(11600, $statement['opening_minor'], 'owed to them before October');
        self::assertSame(33200, $statement['closing_minor']);
        self::assertSame([10000, 33200], array_column($statement['lines'], 'balance_minor'));
    }

    public function testTheBillRouteApprovesBeforePosting(): void
    {
        $route = new class implements InvoiceApprovals {
            /** @var list<int> */
            public array $sent = [];
            public ?int $routeId = 88;

            public function issue(int $tenantId, ?int $actor, array $invoice): ?int
            {
                $this->sent[] = (int) $invoice['id'];

                return $this->routeId;
            }
        };
        $this->invoices->useApprovals($route, 'purchase');
        $draft = $this->draft(1000);
        self::assertSame('waiting_approval', $this->invoices->issue(1, $draft['id'], self::AMRO)['status']);
        $this->invoices->approve(1, $draft['id'], self::AMRO);
        self::assertSame('BIL-2026-0001', $this->invoices->get(1, $draft['id'])['number']);
        self::assertSame([$draft['id']], $route->sent);

        $sale = $this->invoices->create(1, self::AMRO, ['contact_id' => 41, 'lines' => [['description' => 'Cake', 'unit_price_minor' => 100]]])['invoice'];
        self::assertSame('issued', $this->invoices->issue(1, $sale['id'], self::AMRO)['status'], 'the bill route does not hold invoices');
    }

    public function testAClosedCommissionPeriodIsBooked(): void
    {
        $books = new CommissionBooks(new AccountingDb($this->pdo), $this->books, $this->ledger);
        $close = new CommissionClose(7, 1, '2026-11-01', '2026-11-15', [
            new CommissionCloseLine(11, 'JOD', 30, 600000, 45000),
            new CommissionCloseLine(12, 'JOD', 12, 200000, 15000),
            new CommissionCloseLine(12, 'JOD', 0, 0, 0),
        ], 11);

        $ref = $books->record($close);
        self::assertSame('GEN-2026-0001', $ref);
        self::assertSame(60000, $this->balance('6120'), 'staff commission');
        self::assertSame(-60000, $this->balance('2340'), 'commission to pay');
        self::assertSame($ref, $books->record($close), 'the same close books once');
        $lines = $this->ledger->entry(1, (int) $this->ledger->entries(1, ['source_type' => 'commission'])[0]['id'])['lines'];
        self::assertContains('Awss Haddad', array_column($lines, 'description'));

        try {
            $books->record(new CommissionClose(8, 1, '2026-11-16', '2026-11-30', [new CommissionCloseLine(11, 'USD', 1, 100, 10)], 11));
            self::fail('only the books\' currency');
        } catch (AccountingRejectedException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testRefusals(): void
    {
        foreach ([
            'a customer is not a supplier' => [['contact_id' => 41], 'contact_id'],
            'a sales tax code on a bill' => [['lines' => [['description' => 'Flour', 'unit_price_minor' => 1, 'tax_code' => 'JO-GST-16']]], 'lines[0].tax_code'],
            'a bill line is not income' => [['lines' => [['description' => 'Flour', 'unit_price_minor' => 1, 'account_code' => '4100']]], 'lines[0]'],
        ] as $why => [$change, $field]) {
            try {
                $this->invoices->create(1, self::AMRO, $change + ['kind' => 'purchase', 'contact_id' => 42, 'lines' => [['description' => 'Flour', 'unit_price_minor' => 1]]]);
                self::fail($why);
            } catch (AccountingRejectedException $e) {
                self::assertSame($field, $e->extra['field'] ?? null, $why);
            }
        }
        $sale = $this->invoices->issue(1, $this->invoices->create(1, self::AMRO, ['contact_id' => 41, 'lines' => [['description' => 'Cake', 'unit_price_minor' => 100]]])['invoice']['id'], self::AMRO);
        try {
            $this->payments->pay(1, self::AMRO, ['contact_id' => 42, 'amount_minor' => 116, 'invoice_id' => $sale['id']]);
            self::fail('a payment out settles bills, not invoices');
        } catch (AccountingRejectedException $e) {
            self::assertSame('invoice_id', $e->extra['field'] ?? null);
        }
    }

    /** @return array<string, mixed> a draft bill from Jordan Flour Mills, one 16% line */
    private function draft(int $priceMinor, string $accountCode = '5100'): array
    {
        return $this->invoices->create(1, self::AMRO, [
            'kind' => 'purchase',
            'contact_id' => 42,
            'supplier_number' => 'FM-7781',
            'issue_date' => $this->today,
            'lines' => [['description' => 'Flour, 50 kg', 'unit_price_minor' => $priceMinor, 'account_code' => $accountCode]],
        ])['invoice'];
    }

    private function balance(string $code): int
    {
        $this->books->ensure(1);
        $statement = $this->pdo->query("SELECT id FROM acc_accounts WHERE tenant_id = 1 AND code = '{$code}'");
        self::assertNotFalse($statement);

        return $this->ledger->balance(1, (int) $statement->fetchColumn(), '2027-12-31');
    }
}
