<?php

declare(strict_types=1);

namespace Tests\Integration\Accounting;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\Expenses;
use Whity\Core\Accounting\InvoiceApprovals;
use Whity\Core\Accounting\Invoices;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\Payments;
use Whity\Core\Accounting\Periods;
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
 * Expense claims and petty cash against the real schema (WHIT-1318 A3b,
 * design section 32 §5.3, Q5): a claim becomes a bill from the person who
 * paid through their own contact, travels the bill route, posts against what
 * the workspace owes them and is paid back; petty cash spends post and pay at
 * once from Petty cash; refusals.
 */
final class ExpensesRealEngineTest extends TestCase
{
    private const EMAD = 15;

    private PDO $pdo;
    private AccountingWorkspace $books;
    private Ledger $ledger;
    private Invoices $invoices;
    private Payments $payments;
    private Expenses $expenses;
    private string $today = '2026-11-22';
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (15, 'Emad Nasser', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                    (16, 'Outsider', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES (15, 1, 901, 'active', 'member', CURRENT_TIMESTAMP), (16, 2, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new AccountingDb($this->pdo);
        $hooks = new HookManager();
        foreach ([Events::ACCOUNTING_EXPENSE_CLAIMED, Events::ACCOUNTING_BILL_POSTED, Events::ACCOUNTING_BILL_PAID] as $event) {
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
            static fn (int $t): array => [],
            $hooks,
            static fn (): ?WorkspaceEInvoicing => null,
            $clock,
        );
        $this->payments = new Payments($db, $this->books, $this->ledger, $this->invoices, new SequenceCounters($this->pdo), $hooks, $clock);
        $this->expenses = new Expenses($db, $this->books, $this->invoices, $this->payments);
    }

    public function testAClaimPostsAgainstWhatTheWorkspaceOwesThePersonAndIsPaidBack(): void
    {
        $claim = $this->expenses->claim(1, self::EMAD, [
            'date' => '2026-11-20',
            'notes' => 'Visit to the flour mill',
            'lines' => [
                ['description' => 'Taxi', 'amount' => '5.800', 'account_code' => '6600'],
                ['description' => 'Parking', 'amount' => '2', 'tax_category' => 'exempt', 'account_code' => '6600'],
            ],
        ]);
        self::assertSame('purchase', $claim['kind']);
        self::assertSame('issued', $claim['status']);
        self::assertSame(self::EMAD, $claim['claimant_profile_id']);
        self::assertSame('Emad Nasser', $claim['contact_name'], 'the claim is from the person who paid');
        self::assertSame(7800, $claim['total_minor'], 'what was paid, tax included');
        self::assertSame(800, $claim['tax_minor'], '16% inside the taxi fare');
        self::assertSame([Events::ACCOUNTING_BILL_POSTED, Events::ACCOUNTING_EXPENSE_CLAIMED], $this->events);

        self::assertSame(7000, $this->balance('6600'));
        self::assertSame(800, $this->balance('1400'));
        self::assertSame(-7800, $this->balance('2100'), 'owed to Emad');
        $contact = $this->pdo->query("SELECT profile_id, is_supplier, is_customer FROM contacts WHERE id = " . (int) $claim['contact_id'])->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($contact);
        self::assertSame(self::EMAD, (int) $contact['profile_id']);

        $paid = $this->expenses->reimburse(1, $claim['id'], self::EMAD, ['method' => 'cliq']);
        self::assertSame('out', $paid['direction']);
        self::assertSame(7800, $paid['amount_minor']);
        self::assertSame('paid', $this->invoices->get(1, $claim['id'])['status']);
        self::assertSame(0, $this->balance('2100'));
        self::assertContains(Events::ACCOUNTING_BILL_PAID, $this->events);

        $second = $this->expenses->claim(1, self::EMAD, ['lines' => [['description' => 'Courier', 'amount' => '3']]]);
        self::assertSame($claim['contact_id'], $second['contact_id'], 'one contact per person');
        self::assertCount(2, $this->expenses->claims(1, self::EMAD));
        self::assertCount(0, $this->expenses->claims(1, 99));

        try {
            $this->expenses->reimburse(1, $claim['id'], self::EMAD, []);
            self::fail('paid back once');
        } catch (AccountingRejectedException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testAClaimWaitsOnTheBillRoute(): void
    {
        $this->invoices->useApprovals(new class implements InvoiceApprovals {
            public ?int $routeId = 5;

            public function issue(int $tenantId, ?int $actor, array $invoice): ?int
            {
                return $this->routeId;
            }
        }, 'purchase');
        $claim = $this->expenses->claim(1, self::EMAD, ['lines' => [['description' => 'Taxi', 'amount' => '5']]]);
        self::assertSame('waiting_approval', $claim['status']);
        try {
            $this->expenses->reimburse(1, $claim['id'], null, []);
            self::fail('not before it is approved');
        } catch (AccountingRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM acc_entries'), 'nothing in the books until approved');
    }

    public function testPettyCashSpendsPostAndPayAtOnce(): void
    {
        $this->books->ensure(1);
        $pettyCash = $this->scalar("SELECT id FROM acc_accounts WHERE tenant_id = 1 AND role = 'petty_cash'");
        $bank = $this->scalar("SELECT id FROM acc_accounts WHERE tenant_id = 1 AND role = 'bank'");
        $this->payments->transfer(1, self::EMAD, ['from_account_id' => $bank, 'to_account_id' => $pettyCash, 'amount' => '50']);
        self::assertSame(50000, $this->expenses->pettyCash(1, $this->today)['balance_minor']);

        $spent = $this->expenses->spend(1, self::EMAD, ['notes' => 'Cleaning supplies', 'lines' => [['description' => 'Soap', 'amount' => '11.600', 'account_code' => '6900']]]);
        self::assertSame('paid', $spent['bill']['status']);
        self::assertSame('Cash purchases', $spent['bill']['contact_name']);
        self::assertSame(11600, $spent['payment']['amount_minor']);
        self::assertSame(38400, $this->expenses->pettyCash(1, $this->today)['balance_minor']);
        self::assertSame(10000, $this->balance('6900'));
        self::assertSame(1600, $this->balance('1400'), 'the tax inside it is reclaimed');
        self::assertSame(0, $this->balance('2100'), 'nothing is owed for cash already spent');

        $again = $this->expenses->spend(1, self::EMAD, ['lines' => [['description' => 'Tape', 'amount' => '1']]], ['external_ref' => 'petty:1']);
        $repeat = $this->expenses->spend(1, self::EMAD, ['lines' => [['description' => 'Tape', 'amount' => '1']]], ['external_ref' => 'petty:1']);
        self::assertSame($again['bill']['id'], $repeat['bill']['id'], 'a retried spend records once');
        self::assertSame($again['payment']['id'], $repeat['payment']['id']);
        self::assertTrue($this->ledger->verifyChain(1)['intact']);
    }

    public function testRefusals(): void
    {
        foreach ([
            'needs a line' => [['lines' => []], 'lines'],
            'an amount above 0' => [['lines' => [['description' => 'Taxi', 'amount' => '0']]], 'lines[0].amount'],
            'dinars have three decimals' => [['lines' => [['description' => 'Taxi', 'amount' => '1.0001']]], 'lines[0].amount'],
            'a receipt from this workspace' => [['receipt_stored_file_id' => 999, 'lines' => [['description' => 'Taxi', 'amount' => '1']]], 'receipt_stored_file_id'],
        ] as $why => [$data, $field]) {
            try {
                $this->expenses->claim(1, self::EMAD, $data);
                self::fail($why);
            } catch (AccountingRejectedException $e) {
                self::assertSame($field, $e->extra['field'] ?? null, $why);
            }
        }
        try {
            $this->expenses->claim(1, 16, ['lines' => [['description' => 'Taxi', 'amount' => '1']]]);
            self::fail('only a member claims');
        } catch (AccountingRejectedException $e) {
            self::assertSame(403, $e->status);
        }
        $claim = $this->expenses->claim(1, self::EMAD, ['lines' => [['description' => 'Taxi', 'amount' => '1']]]);
        $this->expectException(AccountingRejectedException::class);
        $this->expenses->get(1, $claim['id'], 99);
    }

    private function balance(string $code): int
    {
        return $this->ledger->balance(1, $this->scalar("SELECT id FROM acc_accounts WHERE tenant_id = 1 AND code = '{$code}'"), '2027-12-31');
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement, $sql);

        return (int) $statement->fetchColumn();
    }
}
