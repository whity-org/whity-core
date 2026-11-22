<?php

declare(strict_types=1);

namespace Tests\Integration\Accounting;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\AccountingWorkspace;
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
use Whity\Sdk\Contribute\WorkspaceEInvoicing;
use Whity\Sdk\Hooks\Events;

/**
 * Payments received against the real schema (WHIT-1318 A2b, design section
 * 32 §5.4, §5.5, §8): the entry a payment posts, settling invoices in full
 * and in part, the customer's credit and allocating it later, a provider's
 * fee, cheques that wait, clear and bounce, transfers, idempotency, aged
 * receivables, the customer statement, and refusals.
 *
 * Example Bakery is tenant 1, in dinars (3 decimals).
 */
final class PaymentsRealEngineTest extends TestCase
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
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec(
            "INSERT INTO contacts (id, tenant_id, name, is_customer, is_supplier) VALUES
             (41, 1, 'Example Cafe', true, false), (44, 1, 'Example Hotel', true, false), (42, 1, 'Jordan Flour Mills', false, true)"
        );
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new AccountingDb($this->pdo);
        $hooks = new HookManager();
        foreach ([Events::ACCOUNTING_PAYMENT_RECORDED, Events::ACCOUNTING_PAYMENT_ALLOCATED, Events::ACCOUNTING_INVOICE_PAID, Events::ACCOUNTING_INVOICE_PARTLY_PAID, Events::ACCOUNTING_CHEQUE_BOUNCED] as $event) {
            $hooks->listen($event, function (array $data) use ($event): array {
                $this->events[] = $event;

                return $data;
            });
        }
        $this->books = new AccountingWorkspace($db, static fn (int $t): string => 'JOD');
        $periods = new Periods($db, $this->books);
        $this->ledger = new Ledger($db, $this->books, $periods, new SequenceCounters($this->pdo));
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

    public function testAPaymentPostsAndSettlesItsInvoice(): void
    {
        $invoice = $this->invoice(41, 100000);
        self::assertSame(116000, $invoice['due_minor'], '100.000 plus 16%');

        $paid = $this->payments->receive(1, self::AMRO, [
            'contact_id' => 41, 'amount' => '116.000', 'method' => 'bank_transfer', 'reference' => 'TRX-881', 'invoice_id' => $invoice['id'],
        ])['payment'];
        self::assertSame('RCT-2026-0001', $paid['number']);
        self::assertSame(116000, $paid['allocated_minor']);
        self::assertSame(0, $paid['unallocated_minor']);
        self::assertSame('paid', $this->invoices->get(1, $invoice['id'])['status']);
        self::assertSame(0, $this->invoices->get(1, $invoice['id'])['due_minor']);

        $entry = $this->ledger->entry(1, (int) $paid['entry_id']);
        self::assertSame('BNK1', $entry['journal_code']);
        self::assertSame([['1110', 116000, 0], ['1200', 0, 116000]], array_map(static fn (array $l): array => [$l['account_code'], $l['debit_minor'], $l['credit_minor']], $entry['lines']));
        self::assertSame(0, $this->balance('1200'), 'nothing owed');
        self::assertSame(116000, $this->balance('1110'));
        self::assertSame([Events::ACCOUNTING_PAYMENT_RECORDED, Events::ACCOUNTING_PAYMENT_ALLOCATED, Events::ACCOUNTING_INVOICE_PAID], $this->events);
        self::assertTrue($this->ledger->verifyChain(1)['intact']);
    }

    public function testPartPaymentsAndTheCustomersCreditSettleLater(): void
    {
        $first = $this->invoice(41, 50000);
        $second = $this->invoice(41, 25000);

        $part = $this->payments->receive(1, self::AMRO, ['contact_id' => 41, 'amount_minor' => 20000, 'method' => 'cash', 'invoice_id' => $first['id']])['payment'];
        self::assertSame('CSH', $this->ledger->entry(1, (int) $part['entry_id'])['journal_code'], 'cash goes through the cash journal');
        self::assertSame('partly_paid', $this->invoices->get(1, $first['id'])['status']);
        self::assertSame(38000, $this->invoices->get(1, $first['id'])['due_minor']);
        self::assertContains(Events::ACCOUNTING_INVOICE_PARTLY_PAID, $this->events);

        $advance = $this->payments->receive(1, self::AMRO, ['contact_id' => 41, 'amount_minor' => 60000])['payment'];
        self::assertSame(60000, $advance['unallocated_minor'], 'unsettled: the customer\'s credit');
        $this->payments->allocate(1, $advance['id'], $first['id'], null, self::AMRO);
        self::assertSame('paid', $this->invoices->get(1, $first['id'])['status']);
        $after = $this->payments->allocate(1, $advance['id'], $second['id'], null, self::AMRO);
        self::assertSame(0, $after['unallocated_minor']);
        self::assertSame(7000, $this->invoices->get(1, $second['id'])['due_minor'], '29.000 owed, 22.000 left on the payment');

        try {
            $this->payments->allocate(1, $advance['id'], $second['id'], null, self::AMRO);
            self::fail('nothing left to allocate');
        } catch (AccountingRejectedException $e) {
            self::assertSame(422, $e->status);
        }
        self::assertSame(7000, $this->balance('1200'), 'the ledger and the documents agree');
    }

    public function testACardPaymentKeepsTheProviderFeeAndThePayoutReachesTheBank(): void
    {
        $invoice = $this->invoice(41, 10000);
        $card = $this->payments->receive(1, self::AMRO, [
            'contact_id' => 41, 'amount_minor' => 11600, 'method' => 'whity_pay', 'fee_minor' => 350, 'invoice_id' => $invoice['id'],
        ], ['external_ref' => 'whity-pay:pi_123'])['payment'];
        $again = $this->payments->receive(1, self::AMRO, [
            'contact_id' => 41, 'amount_minor' => 11600, 'method' => 'whity_pay', 'fee_minor' => 350, 'invoice_id' => $invoice['id'],
        ], ['external_ref' => 'whity-pay:pi_123']);
        self::assertTrue($again['repeated'], 'a webhook delivered twice records once');
        self::assertSame($card['id'], $again['payment']['id']);
        self::assertSame(11250, $this->balance('1160'), 'the provider holds the amount less its fee');
        self::assertSame(350, $this->balance('6700'));
        self::assertSame('paid', $this->invoices->get(1, $invoice['id'])['status'], 'the fee does not leave the customer owing');

        $payout = $this->payments->transfer(1, self::AMRO, [
            'from_account_id' => $this->accountId('1160'), 'to_account_id' => $this->accountId('1110'), 'amount_minor' => 11250, 'reference' => 'Payout 77',
        ])['payment'];
        self::assertSame('TRF-2026-0001', $payout['number']);
        self::assertSame('transfer', $payout['direction']);
        self::assertSame(0, $this->balance('1160'), 'Money at payment providers is back to zero');
        self::assertSame(11250, $this->balance('1110'));
    }

    public function testACheckWaitsClearsAndBouncesReopeningTheInvoice(): void
    {
        $invoice = $this->invoice(44, 200000);
        $cheque = $this->payments->receive(1, self::AMRO, [
            'contact_id' => 44, 'amount' => '232', 'method' => 'cheque', 'cheque_number' => '000917', 'cheque_bank' => 'Arab Bank',
            'cheque_due_on' => '2026-12-15', 'invoice_id' => $invoice['id'],
        ])['payment'];
        self::assertSame('waiting', $cheque['cheque_status']);
        self::assertSame(232000, $this->balance('1150'), 'a dated cheque waits on Cheques to collect');
        self::assertSame('paid', $this->invoices->get(1, $invoice['id'])['status']);

        $this->today = '2026-12-15';
        $cleared = $this->payments->deposit(1, $cheque['id'], self::AMRO);
        self::assertSame('cleared', $cleared['cheque_status']);
        self::assertSame(0, $this->balance('1150'));
        self::assertSame(232000, $this->balance('1110'));

        $bounced = $this->payments->bounce(1, $cheque['id'], self::AMRO, '2026-12-17', 5000, 'Insufficient funds');
        self::assertSame('bounced', $bounced['status']);
        self::assertTrue($bounced['allocations'][0]['released']);
        $reopened = $this->invoices->get(1, $invoice['id']);
        self::assertSame('issued', $reopened['status'], 'the invoice is owed again');
        self::assertSame(232000, $reopened['due_minor']);
        self::assertSame(232000, $this->balance('1200'));
        self::assertSame(-5000, $this->balance('1110'), 'the money left the bank, and the bank kept its fee');
        self::assertSame(5000, $this->balance('6700'));
        self::assertContains(Events::ACCOUNTING_CHEQUE_BOUNCED, $this->events);
        self::assertTrue($this->ledger->verifyChain(1)['intact']);

        try {
            $this->payments->bounce(1, $cheque['id'], self::AMRO);
            self::fail('a cheque bounces once');
        } catch (AccountingRejectedException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testAgedReceivablesAndTheStatementAgreeWithTheBooks(): void
    {
        $this->today = '2026-08-01';
        $old = $this->invoice(41, 10000);
        $this->today = '2026-10-15';
        $recent = $this->invoice(41, 20000);
        $this->today = '2026-11-22';
        $this->payments->receive(1, self::AMRO, ['contact_id' => 41, 'amount_minor' => 5000, 'invoice_id' => $old['id'], 'paid_on' => '2026-09-01']);
        $this->payments->receive(1, self::AMRO, ['contact_id' => 44, 'amount_minor' => 3000, 'paid_on' => '2026-11-01']);

        $aged = $this->receivables->aged(1, '2026-11-22');
        $byName = array_column($aged['rows'], null, 'contact_name');
        // Old: issued 1 Aug, due 31 Aug, 83 days late; recent: due 14 Nov, 8 days late.
        self::assertSame(6600, $byName['Example Cafe']['days_61_90']);
        self::assertSame(23200, $byName['Example Cafe']['days_1_30']);
        self::assertSame(29800, $byName['Example Cafe']['balance_minor']);
        self::assertSame(3000, $byName['Example Hotel']['credit_minor'], 'paid before any invoice');
        self::assertSame(-3000, $byName['Example Hotel']['balance_minor']);
        self::assertSame($this->balance('1200'), $aged['totals']['balance_minor'], 'the report adds up to the ledger');

        $before = $this->receivables->aged(1, '2026-10-01');
        $earlier = array_column($before['rows'], null, 'contact_name');
        self::assertSame(6600, $earlier['Example Cafe']['days_31_60'], 'what was owed on 1 October, 31 days late');
        self::assertArrayNotHasKey('Example Hotel', $earlier, 'a payment after the date is not counted');

        $statement = $this->receivables->statement(1, 41, '2026-09-01', '2026-11-22');
        self::assertSame(11600, $statement['opening_minor'], 'the August invoice');
        self::assertSame(29800, $statement['closing_minor']);
        self::assertSame([-5000, 23200], array_map(static fn (array $l): int => $l['debit_minor'] - $l['credit_minor'], $statement['lines']));
        self::assertSame(['payment', 'invoice'], array_column($statement['lines'], 'source_type'));
    }

    public function testRefusals(): void
    {
        $invoice = $this->invoice(41, 1000);
        foreach ([
            'a supplier is not a customer' => [['contact_id' => 42, 'amount_minor' => 100], 'contact_id'],
            'an amount above 0' => [['contact_id' => 41, 'amount_minor' => 0], 'amount_minor'],
            'dinars have three decimals' => [['contact_id' => 41, 'amount' => '1.0005'], 'amount'],
            'a cash payment has no provider fee' => [['contact_id' => 41, 'amount_minor' => 100, 'method' => 'cash', 'fee_minor' => 1], 'fee_minor'],
            'a cheque has a number' => [['contact_id' => 41, 'amount_minor' => 100, 'method' => 'cheque'], 'cheque_number'],
            'another customer\'s invoice' => [['contact_id' => 44, 'amount_minor' => 100, 'invoice_id' => $invoice['id']], 'invoice_id'],
            'only to an account money sits in' => [['contact_id' => 41, 'amount_minor' => 100, 'account_id' => $this->accountId('4100')], 'account_id'],
        ] as $why => [$data, $field]) {
            try {
                $this->payments->receive(1, self::AMRO, $data);
                self::fail($why);
            } catch (AccountingRejectedException $e) {
                self::assertSame($field, $e->extra['field'] ?? null, $why);
            }
        }
        try {
            $this->payments->receive(1, self::AMRO, ['contact_id' => 41, 'amount_minor' => 99999, 'allocations' => [['invoice_id' => $invoice['id'], 'amount_minor' => 99999]]]);
            self::fail('more than the invoice owes');
        } catch (AccountingRejectedException $e) {
            self::assertSame('allocations[0]', $e->extra['field'] ?? null);
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM acc_payments'), 'a refused payment leaves nothing behind');
        $this->expectException(AccountingRejectedException::class);
        $this->payments->get(2, 1);
    }

    /** @return array<string, mixed> an issued invoice with one 16% line */
    private function invoice(int $contactId, int $priceMinor): array
    {
        $draft = $this->invoices->create(1, self::AMRO, [
            'contact_id' => $contactId,
            'issue_date' => $this->today,
            'lines' => [['description' => 'Catering', 'unit_price_minor' => $priceMinor]],
        ])['invoice'];

        return $this->invoices->issue(1, $draft['id'], self::AMRO);
    }

    private function balance(string $code): int
    {
        return $this->ledger->balance(1, $this->accountId($code), '2027-12-31');
    }

    private function accountId(string $code): int
    {
        $this->books->ensure(1);

        return $this->scalar("SELECT id FROM acc_accounts WHERE tenant_id = 1 AND code = '{$code}'");
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement, $sql);

        return (int) $statement->fetchColumn();
    }
}
