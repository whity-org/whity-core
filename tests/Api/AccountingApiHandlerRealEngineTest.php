<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AccountingApiHandler;
use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingReports;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\ChartOfAccounts;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\ManualEntries;
use Whity\Core\Accounting\Periods;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\SequenceCounters;
use Whity\OpenAPI\AccountingApiSchemas;

/**
 * The accounting routes of phase A1 (WHIT-1318): that each declares the
 * permission design section 32 §9.1 says and is registered with it, a manual
 * entry from draft to the trial balance, the refusals people see, and that
 * another workspace's ids answer 404.
 */
final class AccountingApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private AccountingApiHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $db = new AccountingDb($this->pdo);
        $books = new AccountingWorkspace($db, static fn (int $t): string => 'JOD');
        $periods = new Periods($db, $books);
        $ledger = new Ledger($db, $books, $periods, new SequenceCounters($this->pdo));
        $this->handler = new AccountingApiHandler(
            $books,
            new ChartOfAccounts($db, $books),
            $ledger,
            new ManualEntries($db, $books, $ledger),
            $periods,
            new AccountingReports($db, $books, $periods),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testEveryRouteDeclaresTheDesignsPermission(): void
    {
        $byRoute = [];
        foreach (AccountingApiSchemas::routes() as $route) {
            if (self::isInvoiceRoute($route['path']) || self::isPaymentRoute($route['path']) || self::isBillRoute($route['path']) || self::isExpenseRoute($route['path'])) {
                continue;
            }
            $byRoute[$route['method'] . ' ' . $route['path']] = $route['requiredPermission'];
        }
        self::assertCount(22, $byRoute);
        $constants = [
            'accounting:read' => 'ACCOUNTING_READ',
            'accounting:post' => 'ACCOUNTING_POST',
            'accounting:close' => 'ACCOUNTING_CLOSE',
            'accounting:settings' => 'ACCOUNTING_SETTINGS',
        ];
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        foreach ($byRoute as $route => $permission) {
            // Design 32 §9.1: reading is accounting:read; entries are :post;
            // months are :close; the chart is :settings.
            $expected = match (true) {
                str_starts_with($route, 'GET ') => 'accounting:read',
                str_contains($route, '/periods/') => 'accounting:close',
                str_contains($route, '/accounts') => 'accounting:settings',
                default => 'accounting:post',
            };
            self::assertSame($expected, $permission, $route);
            [$method, $path] = explode(' ', $route, 2);
            self::assertMatchesRegularExpression(
                '#\$router->register\(\'' . $method . '\',\s+\'' . preg_quote($path, '#') . '\',\s+\[\$accountingHandler, \'\w+\'\],\s+null, null, CorePermissions::' . $constants[$expected] . '\);#',
                $index,
                $route . ' is registered with its permission'
            );
        }
    }

    public function testEveryInvoiceRouteDeclaresTheDesignsPermission(): void
    {
        $byRoute = [];
        foreach (AccountingApiSchemas::routes() as $route) {
            if (self::isInvoiceRoute($route['path'])) {
                $byRoute[$route['method'] . ' ' . $route['path']] = $route['requiredPermission'];
            }
        }
        self::assertCount(14, $byRoute);
        $constants = ['invoices:read' => 'INVOICES_READ', 'invoices:write' => 'INVOICES_WRITE', 'accounting:settings' => 'ACCOUNTING_SETTINGS'];
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        foreach ($byRoute as $route => $permission) {
            // Design 32 §9.1: reading invoices is invoices:read, changing them
            // invoices:write, and the invoice settings accounting:settings.
            $expected = match (true) {
                str_starts_with($route, 'GET ') => 'invoices:read',
                str_contains($route, '/invoice-settings'), str_contains($route, '/tax-codes') => 'accounting:settings',
                default => 'invoices:write',
            };
            self::assertSame($expected, $permission, $route);
            [$method, $path] = explode(' ', $route, 2);
            self::assertMatchesRegularExpression(
                '#\$router->register\(\'' . $method . '\',\s+\'' . preg_quote($path, '#') . '\',\s+\[\$accountingInvoicesHandler, \'\w+\'\],\s+null, null, CorePermissions::' . $constants[$expected] . '\);#',
                $index,
                $route . ' is registered with its permission'
            );
        }
    }

    public function testEveryPaymentRouteDeclaresTheDesignsPermission(): void
    {
        $byRoute = [];
        foreach (AccountingApiSchemas::routes() as $route) {
            if (self::isPaymentRoute($route['path'])) {
                $byRoute[$route['method'] . ' ' . $route['path']] = $route['requiredPermission'];
            }
        }
        self::assertCount(9, $byRoute);
        $constants = ['payments:read' => 'PAYMENTS_READ', 'payments:write' => 'PAYMENTS_WRITE', 'invoices:read' => 'INVOICES_READ'];
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        foreach ($byRoute as $route => $permission) {
            // Design 32 §9.1: payments are payments:read and :write; what
            // customers owe reads like their invoices.
            $expected = match (true) {
                str_contains($route, '/aged-receivables'), str_contains($route, '/customers/') => 'invoices:read',
                str_starts_with($route, 'GET ') => 'payments:read',
                default => 'payments:write',
            };
            self::assertSame($expected, $permission, $route);
            [$method, $path] = explode(' ', $route, 2);
            self::assertMatchesRegularExpression(
                '#\$router->register\(\'' . $method . '\',\s+\'' . preg_quote($path, '#') . '\',\s+\[\$accountingPaymentsHandler, \'\w+\'\],\s+null, null, CorePermissions::' . $constants[$expected] . '\);#',
                $index,
                $route . ' is registered with its permission'
            );
        }
    }

    public function testEveryBillRouteDeclaresTheDesignsPermission(): void
    {
        $byRoute = [];
        foreach (AccountingApiSchemas::routes() as $route) {
            if (self::isBillRoute($route['path'])) {
                $byRoute[$route['method'] . ' ' . $route['path']] = $route['requiredPermission'];
            }
        }
        self::assertCount(12, $byRoute);
        $constants = ['bills:read' => 'BILLS_READ', 'bills:write' => 'BILLS_WRITE', 'payments:write' => 'PAYMENTS_WRITE', 'accounting:settings' => 'ACCOUNTING_SETTINGS'];
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        foreach ($byRoute as $route => $permission) {
            // Design 32 §9.1: bills are bills:read and :write, paying is
            // payments:write, the bill route is a setting.
            $expected = match (true) {
                str_contains($route, '/payments-made') => 'payments:write',
                str_contains($route, '/bill-settings') && str_starts_with($route, 'PATCH') => 'accounting:settings',
                str_starts_with($route, 'GET ') => 'bills:read',
                default => 'bills:write',
            };
            self::assertSame($expected, $permission, $route);
            [$method, $path] = explode(' ', $route, 2);
            self::assertMatchesRegularExpression(
                '#\$router->register\(\'' . $method . '\',\s+\'' . preg_quote($path, '#') . '\',\s+\[\$accountingBillsHandler, \'\w+\'\],\s+null, null, CorePermissions::' . $constants[$expected] . '\);#',
                $index,
                $route . ' is registered with its permission'
            );
        }
    }

    public function testEveryExpenseRouteDeclaresItsPermission(): void
    {
        $byRoute = [];
        foreach (AccountingApiSchemas::routes() as $route) {
            if (self::isExpenseRoute($route['path'])) {
                $byRoute[$route['method'] . ' ' . $route['path']] = $route['requiredPermission'];
            }
        }
        self::assertCount(6, $byRoute);
        $constants = ['expenses:submit' => 'EXPENSES_SUBMIT', 'payments:read' => 'PAYMENTS_READ', 'payments:write' => 'PAYMENTS_WRITE'];
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        foreach ($byRoute as $route => $permission) {
            // A person files and reads their own claims; paying back and
            // petty cash move money.
            $expected = match (true) {
                str_contains($route, '/reimburse'), $route === 'POST /api/accounting/petty-cash/spends' => 'payments:write',
                str_contains($route, '/petty-cash') => 'payments:read',
                default => 'expenses:submit',
            };
            self::assertSame($expected, $permission, $route);
            [$method, $path] = explode(' ', $route, 2);
            self::assertMatchesRegularExpression(
                '#\$router->register\(\'' . $method . '\',\s+\'' . preg_quote($path, '#') . '\',\s+\[\$accountingExpensesHandler, \'\w+\'\],\s+null, null, CorePermissions::' . $constants[$expected] . '\);#',
                $index,
                $route . ' is registered with its permission'
            );
        }
    }

    public function testAManualEntryFromDraftToTheTrialBalance(): void
    {
        TenantContext::setTenantId(1);
        $books = $this->call('showBooks', 'GET', '/api/accounting/books');
        self::assertSame('JOD', $books['body']['data']['base_currency']);

        $accounts = array_column($this->call('listAccounts', 'GET', '/api/accounting/accounts')['body']['data'], 'id', 'code');
        $draft = $this->call('createManualEntry', 'POST', '/api/accounting/manual-entries', [
            'date' => gmdate('Y-m-d'),
            'memo' => 'Rent for the shop',
            'lines' => [['account_id' => $accounts['6200'], 'debit' => '350'], ['account_id' => $accounts['1110'], 'credit' => '350.000']],
        ]);
        self::assertSame(201, $draft['status']);
        self::assertTrue($draft['body']['data']['balanced']);

        $posted = $this->call('postManualEntry', 'POST', '/api/accounting/manual-entries/x/post', null, ['id' => (string) $draft['body']['data']['id']]);
        self::assertSame('posted', $posted['body']['data']['status']);
        $again = $this->call('postManualEntry', 'POST', '/api/accounting/manual-entries/x/post', null, ['id' => (string) $draft['body']['data']['id']]);
        self::assertSame(409, $again['status'], 'posted once');

        $tb = $this->call('trialBalance', 'GET', '/api/accounting/reports/trial-balance');
        self::assertTrue($tb['body']['data']['balanced']);
        self::assertSame(350000, $tb['body']['data']['totals']['debit_minor']);

        $entryId = (string) $posted['body']['data']['entry_id'];
        $reversed = $this->call('reverseEntry', 'POST', '/api/accounting/entries/x/reverse', ['note' => 'Wrong month'], ['id' => $entryId]);
        self::assertSame(201, $reversed['status']);
        self::assertSame('Wrong month', $reversed['body']['data']['memo']);

        $refused = $this->call('createManualEntry', 'POST', '/api/accounting/manual-entries', [
            'date' => gmdate('Y-m-d'),
            'lines' => [['account_id' => $accounts['6200'], 'debit' => '1.0005'], ['account_id' => $accounts['1110'], 'credit' => '1']],
        ]);
        self::assertSame(422, $refused['status'], 'more decimals than dinars have');
        self::assertSame('lines[0].debit', $refused['body']['details']['field'] ?? null);

        $noReason = $this->call('reopenPeriod', 'POST', '/api/accounting/periods/x/reopen', ['reason' => ''], ['id' => '1']);
        self::assertSame(422, $noReason['status']);

        TenantContext::reset();
        TenantContext::setTenantId(2);
        self::assertSame(404, $this->call('showEntry', 'GET', '/api/accounting/entries/x', null, ['id' => $entryId])['status']);
        self::assertSame(404, $this->call('showAccount', 'GET', '/api/accounting/accounts/x', null, ['id' => (string) $accounts['6200']])['status']);
    }

    private static function isInvoiceRoute(string $path): bool
    {
        return str_contains($path, '/invoices') || str_contains($path, '/tax-codes') || str_contains($path, '/invoice-settings');
    }

    private static function isPaymentRoute(string $path): bool
    {
        return !self::isBillRoute($path)
            && (str_contains($path, '/payments') || str_contains($path, '/transfers') || str_contains($path, '/aged-receivables') || str_contains($path, '/customers/'));
    }

    private static function isExpenseRoute(string $path): bool
    {
        return str_contains($path, '/expense-claims') || str_contains($path, '/petty-cash');
    }

    private static function isBillRoute(string $path): bool
    {
        return str_contains($path, '/bills') || str_contains($path, '/payments-made') || str_contains($path, '/aged-payables')
            || str_contains($path, '/suppliers/') || str_contains($path, '/bill-settings');
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $params
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $method, string $http, string $path, ?array $body = null, array $params = []): array
    {
        $request = new Request($http, $path, ['Content-Type' => 'application/json'], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => 11];
        $response = $params === [] ? $this->handler->{$method}($request) : $this->handler->{$method}($request, $params);
        $decoded = json_decode($response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
