<?php

declare(strict_types=1);

namespace Tests\Integration\Accounting;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\AccountingReports;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\ChartOfAccounts;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\ManualEntries;
use Whity\Core\Accounting\Periods;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * The books against the real schema (WHIT-1318, design section 32, register
 * AC.3–AC.10): the Jordan chart and the Accountant role on first use, manual
 * entries that post balanced, an append-only ledger, reversals, closed months,
 * idempotent posting, the hash chain, the veto hook, balances that rebuild to
 * the same figures, the trial balance and statements, and tenant isolation.
 *
 * Example Bakery is tenant 1, in dinars; tenant 2 is another workspace.
 */
final class AccountingLedgerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private AccountingWorkspace $books;
    private ChartOfAccounts $chart;
    private Ledger $ledger;
    private ManualEntries $manual;
    private Periods $periods;
    private AccountingReports $reports;
    private HookManager $hooks;
    private string $today;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Ibrahim Mansour', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec("INSERT INTO contacts (id, tenant_id, name, is_customer, is_supplier) VALUES (41, 1, 'Example Cafe', true, false)");
        $db = new AccountingDb($this->pdo);
        $this->hooks = new HookManager();
        $this->books = new AccountingWorkspace($db, static fn (int $t): string => $t === 1 ? 'JOD' : 'USD');
        $this->periods = new Periods($db, $this->books, $this->hooks);
        $this->ledger = new Ledger($db, $this->books, $this->periods, new SequenceCounters($this->pdo), $this->hooks);
        $this->chart = new ChartOfAccounts($db, $this->books);
        $this->manual = new ManualEntries($db, $this->books, $this->ledger);
        $this->reports = new AccountingReports($db, $this->books, $this->periods);
        $this->today = gmdate('Y-m-d');
    }

    public function testTheJordanChartJournalsAndAccountantRoleArriveOnFirstUse(): void
    {
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM acc_accounts'), 'nothing until accounting is opened');

        $accounts = $this->chart->list(1);
        $receivable = $this->chart->byRole(1, 'receivable');
        self::assertNotNull($receivable);
        self::assertSame('1200', $receivable['code']);
        self::assertSame(['en' => 'Customers owe us', 'ar' => 'الذمم المدينة'], $receivable['names']);
        self::assertFalse($receivable['allows_manual']);
        self::assertSame(0, $accounts[0]['depth']);
        self::assertSame('1000', $accounts[0]['code']);
        self::assertTrue($accounts[0]['is_group']);
        self::assertSame(['SAL', 'PUR', 'BNK1', 'CSH', 'GEN', 'STK', 'OPN'], array_column($this->chart->journals(1), 'code'));
        self::assertSame('JOD', $this->books->books(1)['base_currency']);
        self::assertFalse($this->books->books(1)['currency_fixed']);

        $roleId = $this->scalar("SELECT id FROM roles WHERE tenant_id = 1 AND name = 'Accountant'");
        self::assertGreaterThan(0, $roleId, 'the workspace gets an Accountant role');
        $statement = $this->pdo->prepare(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :role ORDER BY p.name'
        );
        $statement->execute([':role' => $roleId]);
        $held = $statement->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains(CorePermissions::ACCOUNTING_POST, $held);
        self::assertContains(CorePermissions::ACCOUNTING_CLOSE, $held);
        self::assertNotContains(CorePermissions::ACCOUNTING_SETTINGS, $held, 'the accountant does not change the settings');

        // A second workspace gets its own, and opening it again adds nothing.
        $this->chart->list(2);
        $this->chart->list(1);
        self::assertSame(2 * 53, $this->scalar('SELECT COUNT(*) FROM acc_accounts'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM roles WHERE tenant_id = 1 AND name = 'Accountant'"));
    }

    public function testAManualEntryPostsBalancedAndShowsOnTheTrialBalance(): void
    {
        $bank = $this->code(1, '1110');
        $capital = $this->code(1, '3100');
        $rent = $this->code(1, '6200');

        $draft = $this->manual->create(1, 11, [
            'date' => $this->today,
            'memo' => 'Owners put money in',
            'lines' => [
                ['account_id' => $bank, 'debit' => '5000.000'],
                ['account_id' => $capital, 'credit' => '5000'],
            ],
        ]);
        self::assertSame('draft', $draft['status']);
        self::assertTrue($draft['balanced']);
        self::assertSame(5000000, $draft['debit_minor'], 'dinars keep 3 decimals');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM acc_entries'), 'a draft is not in the ledger');

        $posted = $this->manual->post(1, $draft['id'], 11);
        self::assertSame('posted', $posted['status']);
        $entry = $this->ledger->entry(1, (int) $posted['entry_id']);
        self::assertSame('GEN-' . substr($this->today, 0, 4) . '-0001', $entry['number']);
        self::assertSame('manual', $entry['source_type']);
        self::assertSame('JOD', $entry['currency']);
        self::assertTrue($this->books->books(1)['currency_fixed'], 'the base currency is fixed at the first posting');

        $rentEntry = $this->ledger->post(1, 11, [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual',
            'lines' => [['account_id' => $rent, 'debit_minor' => 350000], ['account_id' => $bank, 'credit_minor' => 350000]],
        ])['entry'];
        self::assertSame('GEN-' . substr($this->today, 0, 4) . '-0002', $rentEntry['number']);

        $tb = $this->reports->trialBalance(1, substr($this->today, 0, 8) . '01', $this->today);
        self::assertTrue($tb['balanced']);
        self::assertSame(5350000, $tb['totals']['debit_minor']);
        $byCode = array_column($tb['rows'], null, 'code');
        self::assertSame(4650000, $byCode['1110']['closing_debit_minor']);
        self::assertSame(5000000, $byCode['3100']['closing_credit_minor']);
        self::assertSame(4650000, $this->ledger->balance(1, $bank, $this->today));

        $statement = $this->reports->accountStatement(1, $bank, substr($this->today, 0, 8) . '01', $this->today, null, null, 1);
        self::assertSame(5000000, $statement['lines'][0]['balance_minor']);
        self::assertNotNull($statement['next']);
        $next = $this->reports->accountStatement(1, $bank, substr($this->today, 0, 8) . '01', $this->today, $statement['next']['after_date'], $statement['next']['after_line'], 1);
        self::assertSame(4650000, $next['lines'][0]['balance_minor'], 'the running balance carries over the page');
        self::assertSame(4650000, $next['closing_minor']);

        // The chart shows the balance, and a heading carries its children's.
        $assets = array_column($this->chart->list(1), null, 'code')['1000'];
        self::assertSame(4650000, $assets['balance_minor']);
    }

    public function testEntriesMustBalanceAndDocumentAccountsRefuseManualLines(): void
    {
        $bank = $this->code(1, '1110');
        $sales = $this->code(1, '4100');
        $receivable = $this->code(1, '1200');
        $heading = $this->code(1, '4000');

        $this->refused('unbalanced', fn () => $this->ledger->post(1, 11, [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual',
            'lines' => [['account_id' => $bank, 'debit_minor' => 100], ['account_id' => $sales, 'credit_minor' => 99]],
        ]));
        $this->refused(null, fn () => $this->ledger->post(1, 11, [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual',
            'lines' => [['account_id' => $bank, 'debit_minor' => 100, 'credit_minor' => 100], ['account_id' => $sales, 'credit_minor' => 0]],
        ]), 'a line is a debit or a credit');
        $this->refused(null, fn () => $this->ledger->post(1, 11, [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual',
            'lines' => [['account_id' => $receivable, 'debit_minor' => 100], ['account_id' => $sales, 'credit_minor' => 100]],
        ]), 'receivables are kept by invoices');
        $this->refused(null, fn () => $this->ledger->post(1, 11, [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual',
            'lines' => [['account_id' => $heading, 'debit_minor' => 100], ['account_id' => $sales, 'credit_minor' => 100]],
        ]), 'a heading takes no lines');

        // Opening balances may name what a customer owed on the first day.
        $opening = $this->manual->create(1, 11, [
            'journal' => 'OPN', 'date' => $this->today,
            'lines' => [
                ['account_id' => $receivable, 'debit' => '120.500', 'contact_id' => 41],
                ['account_id' => $this->code(1, '3900'), 'credit' => '120.500'],
            ],
        ]);
        $posted = $this->manual->post(1, $opening['id'], 11);
        $entry = $this->ledger->entry(1, (int) $posted['entry_id']);
        self::assertSame('opening', $entry['source_type']);
        self::assertSame(41, $entry['lines'][0]['contact_id']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM acc_entries WHERE number LIKE \'GEN%\''));
    }

    public function testTheLedgerIsAppendOnlyAndReversedOnce(): void
    {
        $entry = $this->rent(1, 200000);

        foreach (['UPDATE acc_lines SET debit_minor = 1 WHERE entry_id = ' . $entry['id'], 'DELETE FROM acc_entries WHERE id = ' . $entry['id'],
            "UPDATE acc_entries SET memo = 'x' WHERE id = " . $entry['id']] as $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('the ledger changed: ' . $sql);
            } catch (\PDOException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $reversal = $this->ledger->reverse(1, $entry['id'], 11)['entry'];
        self::assertSame($entry['id'], $reversal['reverses_entry_id']);
        self::assertSame($reversal['id'], $this->ledger->entry(1, $entry['id'])['reversed_by_entry_id']);
        self::assertSame($entry['lines'][0]['debit_minor'], $reversal['lines'][0]['credit_minor']);
        self::assertSame(0, $this->ledger->balance(1, $this->code(1, '6200'), $this->today));

        $this->refused(null, fn () => $this->ledger->reverse(1, $entry['id'], 11), 'reversed once', 409);
        $this->refused(null, fn () => $this->ledger->reverse(1, $reversal['id'], 11), 'a reversal is not reversed', 409);
    }

    public function testAClosedMonthRefusesEntriesUntilReopenedWithAReason(): void
    {
        $period = $this->periods->periodFor(1, $this->today);
        $this->periods->close(1, $period['id'], 11);
        $this->refused('period_closed', fn () => $this->rent(1, 1000));
        $this->refused(null, fn () => $this->periods->reopen(1, $period['id'], 11, '  '), 'a reason is needed');

        $reopened = $this->periods->reopen(1, $period['id'], 11, 'A late rent receipt');
        self::assertSame('open', $reopened['status']);
        self::assertSame('A late rent receipt', $reopened['reopen_reason']);
        self::assertSame(1000, $this->rent(1, 1000)['total_minor']);

        $this->pdo->exec("UPDATE acc_books SET lock_date = '" . $this->today . "' WHERE tenant_id = 1");
        $this->refused('period_closed', fn () => $this->rent(1, 1000), 'nothing on or before the lock date');
    }

    public function testTheSameIdempotencyKeyPostsOnce(): void
    {
        $request = [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual', 'idempotency_key' => 'mcp:abc',
            'lines' => [['account_id' => $this->code(1, '6200'), 'debit_minor' => 500], ['account_id' => $this->code(1, '1110'), 'credit_minor' => 500]],
        ];
        $first = $this->ledger->post(1, 11, $request);
        $again = $this->ledger->post(1, 11, $request);
        self::assertFalse($first['repeated']);
        self::assertTrue($again['repeated']);
        self::assertSame($first['entry']['id'], $again['entry']['id']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM acc_entries'));
    }

    public function testTheHashChainShowsAnEntryChangedBehindTheLedgersBack(): void
    {
        $first = $this->rent(1, 100);
        $second = $this->rent(1, 200);
        self::assertSame($first['hash'], $second['prev_hash']);
        self::assertSame('', $first['prev_hash']);
        $whole = $this->ledger->verifyChain(1);
        self::assertTrue($whole['intact']);
        self::assertSame(2, $whole['entries']);

        // Someone with database access drops the guard and edits a figure.
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $this->pdo->exec('DROP TRIGGER trg_acc_lines_append_only ON acc_lines');
        } else {
            $this->pdo->exec('DROP TRIGGER trg_acc_lines_no_update');
        }
        $this->pdo->exec('UPDATE acc_lines SET description = \'edited\' WHERE entry_id = ' . $first['id'] . ' AND line_no = 1');
        $result = $this->ledger->verifyChain(1);
        self::assertFalse($result['intact']);
        self::assertSame($first['id'], $result['broken_at']);
    }

    public function testAPostingHookCanRefuseAnEntry(): void
    {
        $this->hooks->listen(Events::ACCOUNTING_ENTRY_POSTING, static function (array $payload): array {
            if ($payload['memo'] === 'blocked') {
                throw new HookVetoException('Our auditor froze this account');
            }

            return $payload;
        });
        $this->refused('vetoed', fn () => $this->ledger->post(1, 11, [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual', 'memo' => 'blocked',
            'lines' => [['account_id' => $this->code(1, '6200'), 'debit_minor' => 1], ['account_id' => $this->code(1, '1110'), 'credit_minor' => 1]],
        ]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM acc_entries'));
    }

    public function testBalancesRebuildToTheSameFigures(): void
    {
        $this->rent(1, 300);
        $this->rent(1, 700);
        self::assertSame([], $this->ledger->rebuildBalances(1)['differences']);

        $this->pdo->exec('UPDATE acc_balances SET debit_minor = 1 WHERE tenant_id = 1');
        $rebuilt = $this->ledger->rebuildBalances(1);
        self::assertNotSame([], $rebuilt['differences']);
        self::assertSame(1000, $this->ledger->balance(1, $this->code(1, '6200'), $this->today));
    }

    public function testAnotherWorkspaceSeesNothingAndCannotUseTheseAccounts(): void
    {
        $entry = $this->rent(1, 100);
        $this->refused(null, fn () => $this->ledger->entry(2, $entry['id']), 'another workspace\'s entry', 404);
        $this->refused(null, fn () => $this->ledger->post(2, 11, [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual',
            'lines' => [['account_id' => $this->code(1, '6200'), 'debit_minor' => 1], ['account_id' => $this->code(1, '1110'), 'credit_minor' => 1]],
        ]), 'another workspace\'s accounts');
        self::assertSame([], $this->ledger->entries(2, []));
        self::assertSame('USD', $this->books->books(2)['base_currency']);
    }

    public function testTheChartCanGrowAndCodesFreezeOnceUsed(): void
    {
        $costs = $this->code(1, '6000');
        $cleaning = $this->chart->create(1, ['code' => '6150', 'name' => ['en' => 'Cleaning', 'ar' => 'التنظيف'], 'parent_id' => $costs]);
        self::assertSame('expense', $cleaning['type'], 'a child takes its heading\'s type');
        $this->refused(null, fn () => $this->chart->create(1, ['code' => '6150', 'name' => 'Again', 'parent_id' => $costs]), 'codes are unique', 409);
        $this->refused(null, fn () => $this->chart->update(1, $cleaning['id'], ['parent_id' => $this->code(1, '4000')]), 'stays under its own type');

        $this->ledger->post(1, 11, [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual',
            'lines' => [['account_id' => $cleaning['id'], 'debit_minor' => 10], ['account_id' => $this->code(1, '1100'), 'credit_minor' => 10]],
        ]);
        $this->refused(null, fn () => $this->chart->update(1, $cleaning['id'], ['code' => '6151']), 'a used code stays', 409);
        self::assertSame('Cleaning materials', $this->chart->update(1, $cleaning['id'], ['name' => 'Cleaning materials'])['name']);
        self::assertSame('التنظيف', $this->chart->get(1, $cleaning['id'])['names']['ar'], 'an English rename keeps the Arabic');
        $this->refused(null, fn () => $this->chart->archive(1, $cleaning['id']), 'a balance stays visible', 409);
        $this->refused(null, fn () => $this->chart->archive(1, $this->code(1, '1200')), 'a role account stays', 409);
    }

    /** The first column of the first row, as a whole number. */
    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement, $sql);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function rent(int $tenantId, int $minor): array
    {
        return $this->ledger->post($tenantId, 11, [
            'journal' => 'GEN', 'date' => $this->today, 'source_type' => 'manual', 'memo' => 'Rent',
            'lines' => [['account_id' => $this->code($tenantId, '6200'), 'debit_minor' => $minor], ['account_id' => $this->code($tenantId, '1110'), 'credit_minor' => $minor]],
        ])['entry'];
    }

    private function code(int $tenantId, string $code): int
    {
        $account = $this->chart->byCode($tenantId, $code);
        self::assertNotNull($account, $code);

        return (int) $account['id'];
    }

    private function refused(?string $code, callable $work, string $why = '', ?int $status = null): void
    {
        try {
            $work();
            self::fail('accepted: ' . ($why !== '' ? $why : (string) $code));
        } catch (AccountingRejectedException $e) {
            if ($code !== null) {
                self::assertSame($code, $e->extra['code'] ?? null, $e->clientMessage);
            }
            if ($status !== null) {
                self::assertSame($status, $e->status, $e->clientMessage);
            }
        }
    }
}
