<?php

declare(strict_types=1);

namespace Tests\Integration\Accounting;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\InvoiceApprovals;
use Whity\Core\Accounting\InvoiceCounter;
use Whity\Core\Accounting\InvoiceQuoteConversion;
use Whity\Core\Accounting\Invoices;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\Periods;
use Whity\Core\Accounting\Taxes;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Accounting\EInvoiceResult;
use Whity\Sdk\Accounting\Invoice;
use Whity\Sdk\Contribute\WorkspaceEInvoicing;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Sales\QuoteSnapshot;
use Whity\Sdk\Sales\QuoteSnapshotLine;

/**
 * Sales invoices and credit notes against the real schema (WHIT-1318 A2a,
 * design section 32 §5.2, §7): the Jordan tax codes on first use, line
 * arithmetic with prices that include or exclude tax, the entry an invoice
 * posts, gap-free numbers, frozen issued invoices, credit notes that settle
 * their invoice, the issuing veto, the approval route, e-invoicing after
 * commit, the quote conversion, the invoices meter and tenant isolation.
 *
 * Example Bakery is tenant 1, in dinars (3 decimals); tenant 2 is another
 * workspace.
 */
final class InvoicesRealEngineTest extends TestCase
{
    private const AMRO = 11;

    private PDO $pdo;
    private AccountingWorkspace $books;
    private Ledger $ledger;
    private Taxes $taxes;
    private Invoices $invoices;
    private HookManager $hooks;
    private ItemService $items;
    private UnitCatalog $units;
    private ?WorkspaceEInvoicing $connector = null;
    /** @var array<string, string> */
    private array $settings = [];
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
             (41, 1, 'Example Cafe', true, false), (42, 1, 'Jordan Flour Mills', false, true), (43, 2, 'Other Cafe', true, false)"
        );
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new AccountingDb($this->pdo);
        $this->hooks = new HookManager();
        foreach ([Events::ACCOUNTING_INVOICE_ISSUED, Events::ACCOUNTING_INVOICE_CREDITED] as $event) {
            $this->hooks->listen($event, function (array $data) use ($event): array {
                $this->events[] = $event;

                return $data;
            });
        }
        $this->books = new AccountingWorkspace($db, static fn (int $t): string => $t === 1 ? 'JOD' : 'USD');
        $periods = new Periods($db, $this->books, $this->hooks);
        $this->ledger = new Ledger($db, $this->books, $periods, new SequenceCounters($this->pdo), $this->hooks);
        $this->taxes = new Taxes($db, $this->books);
        $invDb = new InventoryDb($this->pdo);
        $invWorkspace = new InventoryWorkspace($invDb);
        $this->units = new UnitCatalog($invDb, $invWorkspace);
        $this->items = new ItemService($invDb, $invWorkspace, $this->units, static fn (int $t): string => 'JOD', null, $this->hooks);
        $this->invoices = new Invoices(
            $db,
            $this->books,
            $this->ledger,
            $this->taxes,
            $this->items,
            $this->units,
            new SequenceCounters($this->pdo),
            fn (int $t): array => $this->settings,
            $this->hooks,
            fn (): ?WorkspaceEInvoicing => $this->connector,
            fn (): \DateTimeImmutable => new \DateTimeImmutable($this->today),
        );
    }

    public function testTheJordanTaxCodesArriveWithTheBooks(): void
    {
        $codes = $this->taxes->list(1);
        self::assertCount(10, $codes);
        $standard = $this->taxes->codeFor(1, '', 'output', $this->today);
        self::assertNotNull($standard);
        self::assertSame('JO-GST-16', $standard['code']);
        self::assertSame(1600, $standard['rate_bp']);
        self::assertSame($this->accountId(1, '2200'), $standard['account_id'], 'output tax goes to Sales tax we collected');
        self::assertSame(['en' => 'Sales tax 16%', 'ar' => 'ضريبة المبيعات 16%'], $standard['names']);
        self::assertSame('JO-GST-IN-16', $this->taxes->codeFor(1, 'standard', 'input', $this->today)['code'] ?? null);
        self::assertSame('JO-EXEMPT', $this->taxes->codeFor(1, 'exempt', 'output', $this->today)['code'] ?? null);
        self::assertSame($this->accountId(1, '1400'), $this->taxes->codeFor(1, 'zero', 'input', $this->today)['account_id'] ?? null);
        self::assertNull($this->taxes->codeFor(1, 'luxury', 'output', $this->today), 'an unknown category has no code');
    }

    public function testTheSeededCodesAreEditableButARateNeverChanges(): void
    {
        $standard = $this->taxes->codeFor(1, '', 'output', $this->today);
        self::assertNotNull($standard);
        $renamed = $this->taxes->update(1, $standard['id'], ['name' => ['en' => 'General sales tax 16%', 'ar' => 'الضريبة العامة على المبيعات 16%']]);
        self::assertSame('General sales tax 16%', $renamed['name']);
        self::assertSame(1600, $renamed['rate_bp']);

        try {
            $this->taxes->update(1, $standard['id'], ['rate_bp' => 1500]);
            self::fail('a rate never changes');
        } catch (AccountingRejectedException $e) {
            self::assertSame('rate_bp', $e->extra['field'] ?? null);
        }

        // A new rate is a new code from its date; the old one ends the day before.
        $this->taxes->update(1, $standard['id'], ['valid_to' => '2026-12-31']);
        $next = $this->taxes->create(1, [
            'code' => 'JO-GST-15', 'name' => 'Sales tax 15%', 'direction' => 'output', 'rate_bp' => 1500,
            'category' => 'standard', 'valid_from' => '2027-01-01',
        ]);
        self::assertSame($this->accountId(1, '2200'), $next['account_id'], 'output tax goes to its default account');
        self::assertSame('JO-GST-16', $this->taxes->codeFor(1, '', 'output', '2026-12-31')['code'] ?? null);
        self::assertSame('JO-GST-15', $this->taxes->codeFor(1, '', 'output', '2027-01-01')['code'] ?? null);

        try {
            $this->taxes->create(1, ['code' => 'jo-gst-15', 'name' => 'Again', 'direction' => 'output', 'rate_bp' => 1500]);
            self::fail('codes are unique');
        } catch (AccountingRejectedException $e) {
            self::assertSame(409, $e->status);
        }

        $exempt = $this->taxes->codeFor(1, 'exempt', 'output', $this->today);
        self::assertNotNull($exempt);
        $this->taxes->archive(1, $exempt['id']);
        self::assertNull($this->taxes->codeFor(1, 'exempt', 'output', $this->today), 'an archived code is not offered');
        $this->taxes->archive(1, $exempt['id'], false);
        self::assertNotNull($this->taxes->codeFor(1, 'exempt', 'output', $this->today));
        self::assertCount(10, $this->taxes->list(2), 'another workspace keeps its own codes');
    }

    public function testLineArithmeticRoundsOncePerLine(): void
    {
        self::assertSame(['net' => 10000, 'tax' => 1600, 'total' => 11600], Taxes::split(10000, 1600, false));
        // 11.600 including 16% is 10.000 net.
        self::assertSame(['net' => 10000, 'tax' => 1600, 'total' => 11600], Taxes::split(11600, 1600, true));
        // 1.005 at 16% is 0.1608 tax, rounded to 0.161; including tax, 1.005 is 0.866 net.
        self::assertSame(['net' => 1005, 'tax' => 161, 'total' => 1166], Taxes::split(1005, 1600, false));
        self::assertSame(['net' => 866, 'tax' => 139, 'total' => 1005], Taxes::split(1005, 1600, true));
        // 2.5 hours at 12.000 less 10%: 30.000 less 3.000.
        self::assertSame(27000, Taxes::lineAmount(2500, 3, 12000, 1000));
        self::assertSame(3, Taxes::divRound(5, 2), 'half away from zero');
        self::assertSame(-3, Taxes::divRound(-5, 2));
        self::assertSame(2, Taxes::divRound(7, 4), '1.75 rounds to 2');
    }

    public function testIssuingNumbersFreezesAndPostsTheInvoice(): void
    {
        $draft = $this->draft();
        self::assertSame('draft', $draft['status']);
        self::assertNull($draft['number']);
        self::assertSame(19000, $draft['subtotal_minor'], '2 cakes at 4.500 and 2.5 hours at 4.000');
        self::assertSame(1440, $draft['tax_minor'], '16% on the cakes, nothing on the exempt hours');
        self::assertSame(20440, $draft['total_minor']);
        self::assertSame('2026-12-22', $draft['due_date'], '30 days of terms');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM acc_entries'), 'a draft posts nothing');

        $issued = $this->invoices->issue(1, $draft['id'], self::AMRO);
        self::assertSame('issued', $issued['status']);
        self::assertSame('INV-2026-0001', $issued['number']);
        self::assertSame(20440, $issued['due_minor']);
        self::assertSame('not_required', $issued['einvoice_status'], 'no connector: not required');
        self::assertSame([Events::ACCOUNTING_INVOICE_ISSUED], $this->events);

        $entry = $this->ledger->entry(1, (int) $issued['entry_id']);
        self::assertSame('SAL-2026-0001', $entry['number']);
        self::assertSame('invoice', $entry['source_type']);
        $byAccount = [];
        foreach ($entry['lines'] as $l) {
            $byAccount[$l['account_code']][] = [$l['debit_minor'], $l['credit_minor'], $l['tax_code_id'], $l['tax_base_minor'], $l['contact_id']];
        }
        $gst = (int) ($this->taxes->codeFor(1, '', 'output', $this->today)['id'] ?? 0);
        $exempt = (int) ($this->taxes->codeFor(1, 'exempt', 'output', $this->today)['id'] ?? 0);
        self::assertSame([[20440, 0, null, null, 41]], $byAccount['1200'], 'the customer owes the total');
        self::assertSame([[0, 9000, $gst, 9000, 41]], $byAccount['4100'], 'cakes to Sales, with their tax base');
        self::assertSame([[0, 10000, $exempt, 10000, 41]], $byAccount['4200'], 'hours to Services, exempt base kept for the return');
        self::assertSame([[0, 1440, $gst, null, 41]], $byAccount['2200'], 'the tax collected');
        self::assertTrue($this->ledger->verifyChain(1)['intact']);

        // Frozen: no edits, no second issue, no cancel.
        foreach ([
            fn () => $this->invoices->update(1, $draft['id'], ['notes' => 'changed']),
            fn () => $this->invoices->issue(1, $draft['id'], self::AMRO),
            fn () => $this->invoices->cancel(1, $draft['id'], self::AMRO),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('an issued invoice cannot change');
            } catch (AccountingRejectedException $e) {
                self::assertSame(409, $e->status);
            }
        }

        // The next one takes the next number; a cancelled draft takes none.
        $cancelled = $this->draft();
        $this->invoices->cancel(1, $cancelled['id'], self::AMRO);
        $second = $this->invoices->issue(1, $this->draft()['id'], self::AMRO);
        self::assertSame('INV-2026-0002', $second['number'], 'numbers have no gaps');
    }

    public function testPricesIncludingTaxSplitPerLine(): void
    {
        $draft = $this->invoices->create(1, self::AMRO, [
            'contact_id' => 41,
            'prices_include_tax' => true,
            'lines' => [['description' => 'Bread basket', 'quantity' => '3', 'unit_price_minor' => 1005]],
        ])['invoice'];

        self::assertSame(3015, $draft['total_minor'], 'what was typed is what is charged');
        self::assertSame(2599, $draft['subtotal_minor']);
        self::assertSame(416, $draft['tax_minor']);
    }

    public function testACreditNoteMirrorsTheLinesAndSettlesTheInvoice(): void
    {
        $invoice = $this->invoices->issue(1, $this->draft()['id'], self::AMRO);
        $cakeLine = $invoice['lines'][0]['id'];

        $partial = $this->invoices->credit(1, $invoice['id'], self::AMRO, [
            'lines' => [['line_id' => $cakeLine, 'quantity' => '1']],
            'reason' => 'One cake arrived broken',
        ])['invoice'];
        self::assertSame('sale_credit', $partial['kind']);
        self::assertSame('CRN-2026-0001', $partial['number']);
        self::assertSame(5220, $partial['total_minor'], 'one cake at 4.500 plus 16%');
        self::assertSame(5220, $partial['credited_minor'], 'all of it settled the invoice');
        $after = $this->invoices->get(1, $invoice['id']);
        self::assertSame('partly_paid', $after['status']);
        self::assertSame(15220, $after['due_minor']);
        self::assertSame(5220, $after['credited_minor']);

        $entry = $this->ledger->entry(1, (int) $partial['entry_id']);
        self::assertSame('credit_note', $entry['source_type']);
        $receivable = array_values(array_filter($entry['lines'], static fn (array $l): bool => $l['account_code'] === '1200'));
        self::assertSame([0, 5220], [$receivable[0]['debit_minor'], $receivable[0]['credit_minor']], 'the customer owes less');
        $sales = array_values(array_filter($entry['lines'], static fn (array $l): bool => $l['account_code'] === '4100'));
        self::assertSame([4500, -4500], [$sales[0]['debit_minor'], $sales[0]['tax_base_minor']], 'sales and the tax base go back');

        try {
            $this->invoices->credit(1, $invoice['id'], self::AMRO, ['lines' => [['line_id' => $cakeLine, 'quantity' => '2']]]);
            self::fail('only one cake is left to credit');
        } catch (AccountingRejectedException $e) {
            self::assertSame(422, $e->status);
        }

        $rest = $this->invoices->credit(1, $invoice['id'], self::AMRO, [])['invoice'];
        self::assertSame(15220, $rest['total_minor'], 'the rest: one cake and the hours');
        $final = $this->invoices->get(1, $invoice['id']);
        self::assertSame('credited', $final['status']);
        self::assertSame(0, $final['due_minor']);
        self::assertCount(2, $final['allocations']);
        self::assertContains(Events::ACCOUNTING_INVOICE_CREDITED, $this->events);

        $tb = $this->ledger->balance(1, $this->accountId(1, '1200'), $this->today);
        self::assertSame(0, $tb, 'nothing is owed once fully credited');
    }

    public function testAPluginCanVetoIssuing(): void
    {
        $this->hooks->listen(Events::ACCOUNTING_INVOICE_ISSUING, static function (array $data): array {
            if ($data['total_minor'] > 10000) {
                throw new HookVetoException(Events::ACCOUNTING_INVOICE_ISSUING, 'Example Cafe is over its credit limit');
            }

            return $data;
        });
        $draft = $this->draft();
        try {
            $this->invoices->issue(1, $draft['id'], self::AMRO);
            self::fail('vetoed');
        } catch (AccountingRejectedException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('vetoed', $e->extra['code'] ?? null);
            self::assertSame('Example Cafe is over its credit limit', $e->clientMessage);
        }
        self::assertSame('draft', $this->invoices->get(1, $draft['id'])['status']);
        self::assertNull($this->invoices->get(1, $draft['id'])['number'], 'a refused invoice takes no number');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM acc_entries'));
    }

    public function testTheInvoiceRouteApprovesBeforeIssuing(): void
    {
        $route = new class implements InvoiceApprovals {
            /** @var list<int> */
            public array $sent = [];
            public ?int $routeId = 77;

            public function issue(int $tenantId, ?int $actor, array $invoice): ?int
            {
                $this->sent[] = (int) $invoice['id'];

                return $this->routeId;
            }
        };
        $this->invoices->useApprovals($route);
        $draft = $this->draft();
        $waiting = $this->invoices->issue(1, $draft['id'], self::AMRO);
        self::assertSame('waiting_approval', $waiting['status']);
        self::assertSame(77, $waiting['route_id']);
        self::assertNull($waiting['number']);
        self::assertSame([$draft['id']], $route->sent);

        $this->invoices->rejected(1, $draft['id']);
        self::assertSame('draft', $this->invoices->get(1, $draft['id'])['status'], 'sent back: a draft again');

        $this->invoices->issue(1, $draft['id'], self::AMRO);
        $this->invoices->approve(1, $draft['id'], self::AMRO);
        $approved = $this->invoices->get(1, $draft['id']);
        self::assertSame('issued', $approved['status']);
        self::assertSame('INV-2026-0001', $approved['number']);
    }

    public function testEInvoicingReportsAfterIssuingAndAFailureLeavesItPending(): void
    {
        $this->connector = new class implements WorkspaceEInvoicing {
            public bool $fail = false;
            /** @var list<string> */
            public array $seen = [];

            public function country(): string
            {
                return 'JO';
            }

            public function isOn(): bool
            {
                return true;
            }

            public function submit(Invoice $invoice): EInvoiceResult
            {
                if ($this->fail) {
                    throw new \RuntimeException('JoFotara did not answer');
                }
                $this->seen[] = (string) $invoice->number;

                return EInvoiceResult::accepted('uuid-1', 'JO-REF-1', 'qr-data', 1);
            }
        };
        $accepted = $this->invoices->issue(1, $this->draft()['id'], self::AMRO);
        self::assertSame('accepted', $accepted['einvoice_status']);
        self::assertSame('JO-REF-1', $accepted['einvoice_ref']);
        self::assertSame('qr-data', $accepted['einvoice_qr']);
        self::assertSame(['INV-2026-0001'], $this->connector->seen);

        $this->connector->fail = true;
        $pending = $this->invoices->issue(1, $this->draft()['id'], self::AMRO);
        self::assertSame('issued', $pending['status'], 'a failing authority never blocks issuing');
        self::assertSame('pending', $pending['einvoice_status']);
    }

    public function testAQuoteTurnsIntoADraftInvoice(): void
    {
        $conversion = new InvoiceQuoteConversion($this->invoices, $this->units, fn (): ?int => $this->workspaceFor('quote'));
        $quote = new QuoteSnapshot(5, 'QUO-0005', 1, 41, 'Example Cafe', 'JOD', false, 9000, 1440, 10440, '2026-12-01', 'Thanks', '', [
            new QuoteSnapshotLine(1, null, null, 'Catering', '2', null, 4500, 0, 'standard', 1600, 9000),
        ]);
        self::assertTrue($conversion->canConvert($quote, self::AMRO)->possible);
        $made = $conversion->convert($quote, self::AMRO);
        $draft = $this->invoices->get(1, $made->id);
        self::assertSame('draft', $draft['status']);
        self::assertSame(5, $draft['quote_id']);
        self::assertSame(10440, $draft['total_minor']);
        self::assertSame($made->id, $conversion->convert($quote, self::AMRO)->id, 'once per quote');
    }

    public function testTheMeterCountsIssuedSalesInvoicesOnly(): void
    {
        $counter = new InvoiceCounter($this->invoices);
        $from = new \DateTimeImmutable('-1 day');
        $until = new \DateTimeImmutable('+1 day');
        $this->draft();
        $issued = $this->invoices->issue(1, $this->draft()['id'], self::AMRO);
        $this->invoices->credit(1, $issued['id'], self::AMRO, []);
        self::assertSame(1, $counter->issuedBetween(1, $from, $until), 'drafts and credit notes do not count');
        self::assertSame(0, $counter->issuedBetween(2, $from, $until));
    }

    public function testCustomersCurrencyAndWorkspacesAreChecked(): void
    {
        $cases = [
            [['contact_id' => 42], 'contact_id', 'a supplier is not a customer'],
            [['contact_id' => 43], 'contact_id', 'another workspace\'s customer'],
            [['currency' => 'USD'], 'currency', 'the books are in dinars'],
            [['lines' => [['description' => 'X', 'tax_code' => 'JO-GST-IN-16']]], 'lines[0].tax_code', 'a purchase code on a sale'],
            [['lines' => [['description' => 'X', 'account_code' => '1110']]], 'lines[0]', 'income accounts only'],
            [['lines' => []], 'lines', 'needs a line'],
        ];
        foreach ($cases as [$change, $field, $why]) {
            try {
                $this->invoices->create(1, self::AMRO, $change + ['contact_id' => 41, 'lines' => [['description' => 'X', 'unit_price_minor' => 100]]]);
                self::fail($why);
            } catch (AccountingRejectedException $e) {
                self::assertSame($field, $e->extra['field'] ?? null, $why);
            }
        }
        $mine = $this->draft();
        $this->expectException(AccountingRejectedException::class);
        $this->invoices->get(2, $mine['id']);
    }

    public function testIssuedInvoicesGrantedToAccountantsAndAdmins(): void
    {
        $this->books->ensure(1);
        $roleId = $this->scalar("SELECT id FROM roles WHERE tenant_id = 1 AND name = 'Accountant'");
        $statement = $this->pdo->prepare('SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :role');
        $statement->execute([':role' => $roleId]);
        $held = $statement->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains(CorePermissions::INVOICES_READ, $held);
        self::assertContains(CorePermissions::INVOICES_WRITE, $held);
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        $this->settings = ['accounting.invoice_payment_terms_days' => '30'];
        $cake = $this->items->create(1, [
            'name' => ['en' => 'Birthday cake ' . uniqid(), 'ar' => 'كعكة'], 'unit' => 'pc', 'sale_price_minor' => 4500, 'tax_category' => 'standard',
        ], self::AMRO)['variants'][0]['id'];

        return $this->invoices->create(1, self::AMRO, [
            'contact_id' => 41,
            'lines' => [
                ['variant_id' => $cake, 'quantity' => '2'],
                ['description' => 'Decorating', 'quantity' => '2.5', 'unit_price_minor' => 4000, 'tax_category' => 'exempt', 'account_code' => '4200'],
            ],
        ])['invoice'];
    }

    /** The workspace a call runs in: Example Bakery, unless none. */
    private function workspaceFor(string $what): ?int
    {
        return $what === '' ? null : 1;
    }

    private function accountId(int $tenantId, string $code): int
    {
        $this->books->ensure($tenantId);

        return $this->scalar("SELECT id FROM acc_accounts WHERE tenant_id = {$tenantId} AND code = '{$code}'");
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement, $sql);

        return (int) $statement->fetchColumn();
    }
}
