<?php

declare(strict_types=1);

namespace Tests\Integration\Accounting;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingReports;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\ChartOfAccounts;
use Whity\Core\Accounting\Host\HostAccountingGuard;
use Whity\Core\Accounting\Host\HostAccounts;
use Whity\Core\Accounting\Host\HostLedger;
use Whity\Core\Accounting\Host\HostPeriods;
use Whity\Core\Accounting\Host\HostReports;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\Periods;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Accounting\AccountingRejected;
use Whity\Sdk\Accounting\EntryRequest;
use Whity\Sdk\Accounting\EntryRequestLine;
use Whity\Sdk\PluginInterface;

/**
 * A payroll plugin posts its run through the SDK (1.131, WHIT-1318): it adds
 * an account it owns, posts the month's salaries under its OWN name, retries
 * safely with an idempotency key, reverses it, reads the trial balance, and is
 * refused for a person without the permission, through the container's copy,
 * in a closed month, outside any workspace, or for an entry that doesn't
 * balance.
 *
 * Example Bakery is tenant 1: Amro Saleh (11) holds accounting:post and
 * accounting:settings, Awss Haddad (12) only reads. Tenant 2 is another
 * workspace.
 */
final class PluginAccountingRealEngineTest extends TestCase
{
    private PDO $pdo;
    private HostAccounts $accounts;
    private HostLedger $ledger;
    private HostPeriods $periods;
    private HostReports $reports;
    private Periods $corePeriods;
    private ?int $workspace = 1;
    private string $today;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 12 => 1, 21 => 2] as $profile => $tenant) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$profile}, 'p{$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $this->pdo->exec(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
                 VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)"
            );
        }
        $db = new AccountingDb($this->pdo);
        $books = new AccountingWorkspace($db, static fn (int $t): string => 'JOD');
        $this->corePeriods = new Periods($db, $books);
        $ledger = new Ledger($db, $books, $this->corePeriods, new SequenceCounters($this->pdo));
        $guard = new HostAccountingGuard($db, static fn (int $profile, string $permission, int $tenant): bool => $profile === 11
            || $permission === CorePermissions::ACCOUNTING_READ, fn (): ?int => $this->workspace);
        $this->accounts = new HostAccounts(new ChartOfAccounts($db, $books), $guard);
        $this->ledger = new HostLedger($ledger, $guard);
        $this->periods = new HostPeriods($this->corePeriods, $books, $guard);
        $this->reports = new HostReports(new AccountingReports($db, $books, $this->corePeriods), $guard);
        $this->today = gmdate('Y-m-d');
    }

    public function testAPayrollPluginPostsItsRunUnderItsOwnName(): void
    {
        $payroll = self::plugin('Payroll');
        PluginIdentity::bind($payroll, 'Payroll');
        $accounts = $this->accounts->for($payroll);
        $books = $this->ledger->for($payroll);

        $runs = $accounts->create(11, '2330', ['en' => 'Payroll runs to settle', 'ar' => 'رواتب بانتظار التسوية'], $this->accounts->byCode('2000')?->id);
        self::assertSame('liability', $runs->type);
        self::assertSame('payroll', $runs->ownerPlugin);
        $salaries = $this->accounts->byCode('6100');
        self::assertNotNull($salaries);

        $request = new EntryRequest(
            11,
            'GEN',
            $this->today,
            [EntryRequestLine::debit($salaries->id, 2400000, 'November salaries'), EntryRequestLine::credit($runs->id, 2400000)],
            'payroll_run',
            77,
            'November payroll',
            'run-77',
        );
        $entry = $books->post($request);
        $again = $books->post($request);
        self::assertSame('payroll.payroll_run', $entry->sourceType, 'stored under the plugin\'s own name');
        self::assertSame($entry->id, $again->id, 'the same key posts once');
        self::assertSame('JOD', $entry->currency);
        self::assertCount(2, $entry->lines);
        self::assertSame(2400000, $this->ledger->balance($salaries->id, $this->today));

        $tb = $this->reports->trialBalance(substr($this->today, 0, 8) . '01', $this->today);
        self::assertTrue($tb->balanced);
        self::assertSame(2400000, $tb->debitMinor);

        $reversal = $books->reverse($entry->id, 11, 'Posted twice by mistake');
        self::assertSame($entry->id, $reversal->reversesEntryId);
        self::assertSame('payroll.reversal', $reversal->sourceType);
        self::assertSame(0, $this->ledger->balance($salaries->id, $this->today));
        self::assertTrue($this->periods->isOpen($this->today));
        self::assertNull($this->periods->lockDate());
    }

    public function testThePluginIsRefusedWhereAPersonWouldBe(): void
    {
        $payroll = self::plugin('Payroll');
        PluginIdentity::bind($payroll, 'Payroll');
        $books = $this->ledger->for($payroll);
        $salaries = $this->accounts->byCode('6100');
        $bank = $this->accounts->byCode('1110');
        self::assertNotNull($salaries);
        self::assertNotNull($bank);
        $lines = [EntryRequestLine::debit($salaries->id, 100), EntryRequestLine::credit($bank->id, 100)];

        $this->refused(AccountingRejected::USE_PLUGIN_CONTEXT, fn () => $this->ledger->post(new EntryRequest(11, 'GEN', $this->today, $lines, 'run')));
        $this->refused(AccountingRejected::USE_PLUGIN_CONTEXT, fn () => $this->accounts->create(11, '9999', 'Mine'));
        $this->refused(AccountingRejected::FORBIDDEN, fn () => $books->post(new EntryRequest(12, 'GEN', $this->today, $lines, 'run')));
        $this->refused(AccountingRejected::FORBIDDEN, fn () => $books->post(new EntryRequest(21, 'GEN', $this->today, $lines, 'run')), 'not in this workspace');
        $this->refused(AccountingRejected::FORBIDDEN, fn () => $this->ledger->for(self::plugin('Stranger')), 'never loaded');
        $this->refused(AccountingRejected::UNBALANCED, fn () => $books->post(new EntryRequest(11, 'GEN', $this->today, [EntryRequestLine::debit($salaries->id, 100), EntryRequestLine::credit($bank->id, 90)], 'run')));
        $this->refused(AccountingRejected::INVALID, fn () => $books->post(new EntryRequest(11, 'GEN', $this->today, $lines, 'Run Now!')));

        $period = $this->corePeriods->periodFor(1, $this->today);
        $this->corePeriods->close(1, $period['id'], 11);
        $this->refused(AccountingRejected::PERIOD_CLOSED, fn () => $books->post(new EntryRequest(11, 'GEN', $this->today, $lines, 'run')));
        self::assertFalse($this->periods->isOpen($this->today));

        $this->workspace = null;
        $this->refused(AccountingRejected::NO_WORKSPACE, fn () => $this->accounts->list());
        $this->workspace = 2;
        $this->refused(AccountingRejected::NOT_FOUND, fn () => $this->accounts->get($salaries->id), 'another workspace\'s account');
    }

    private function refused(string $reason, callable $work, string $why = ''): void
    {
        try {
            $work();
            self::fail('accepted: ' . ($why !== '' ? $why : $reason));
        } catch (AccountingRejected $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    private static function plugin(string $name): PluginInterface
    {
        return new class ($name) implements PluginInterface {
            public function __construct(private string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getRoutes(): array
            {
                return [];
            }

            public function getPermissions(): array
            {
                return [];
            }

            public function getHooks(): array
            {
                return [];
            }

            public function getMigrations(): array
            {
                return [];
            }
        };
    }
}
