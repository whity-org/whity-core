<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\Periods;
use Whity\Core\Accounting\StockBooks;
use Whity\Core\Inventory\InventoryCosts;
use Whity\Core\Inventory\InventoryDb;
use Whity\Database\Database;
use Whity\Database\SequenceCounters;

/**
 * `whity-cli accounting:stock-opening [--workspace=ID]` — brings each
 * workspace's stock into its books once (WHIT-1318 A4a, design section 32
 * §6): values the stock at its current moving average cost and posts what the
 * Stock account lacks against Opening balances. Workspaces whose stock is
 * already in the books, or that turned it off, are skipped. Safe to run on
 * every deploy.
 */
class AccountingStockOpeningCommand extends BaseCommand implements CliCommand
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string>|null */
    public function knownFlags(): ?array
    {
        return ['--workspace'];
    }

    /** @param array<int, string> $argv */
    public function execute(array $argv): int
    {
        $workspace = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--workspace=')) {
                $workspace = (int) substr($arg, strlen('--workspace='));
            } elseif ($arg === 'help' || $arg === '--help' || $arg === '-h') {
                $this->showHelp();

                return 0;
            }
        }

        $pdo = Database::connect()->getPdo();
        $db = new AccountingDb($pdo);
        $books = new AccountingWorkspace($db, static fn (int $t): string => 'USD');
        $ledger = new Ledger($db, $books, new Periods($db, $books), new SequenceCounters($pdo));
        $costs = new InventoryCosts(new InventoryDb($pdo), static fn (int $t): string => $books->books($t)['base_currency']);
        $stock = new StockBooks($db, $books, $ledger, $costs);

        $tenants = $workspace !== null ? [$workspace] : array_map(
            static fn (array $r): int => (int) $r['tenant_id'],
            // An operator's maintenance command: it only lists which workspaces have stock, then works on each one scoped
            $db->all('SELECT DISTINCT tenant_id FROM inv_levels WHERE tenant_id > :none AND on_hand <> 0 ORDER BY tenant_id', [':none' => 0])
        );
        foreach ($tenants as $tenantId) {
            try {
                $result = $stock->openNow($tenantId, null);
            } catch (AccountingRejectedException $e) {
                echo sprintf("workspace %d: skipped, %s\n", $tenantId, $e->clientMessage);
                continue;
            }
            echo sprintf(
                "workspace %d: %s%s\n",
                $tenantId,
                $result['posted'] ? 'stock brought into the books, ' . $result['amount_minor'] . ' minor units' : 'stock already matched the books',
                $result['unknown'] !== [] ? ', ' . count($result['unknown']) . ' item(s) have no cost yet' : ''
            );
        }

        return 0;
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli accounting:stock-opening [--workspace=ID]\n\n";
        echo "Brings each workspace's stock into its books once, at its current cost, against Opening balances.\n";
        echo "Workspaces already done, or with stock in the books turned off, are skipped.\n";
    }
}
