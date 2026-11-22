<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\Periods;
use Whity\Database\Database;
use Whity\Database\SequenceCounters;

/**
 * `whity-cli accounting:rebuild-balances` (WHIT-1318, design 32 §3.3).
 *
 * Monthly balances are a projection of the append-only ledger. This
 * recomputes them from the lines, for one workspace or every workspace with
 * books, and prints each balance that differed. Safe to re-run.
 */
class AccountingRebuildBalancesCommand extends BaseCommand implements CliCommand
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
        $workspaces = self::workspaces($db, $workspace);
        $books = new AccountingWorkspace($db, static fn (int $t): string => 'USD');
        $ledger = new Ledger($db, $books, new Periods($db, $books), new SequenceCounters($pdo));

        $differed = 0;
        foreach ($workspaces as $tenantId) {
            $result = $ledger->rebuildBalances($tenantId);
            foreach ($result['differences'] as $diff) {
                $differed++;
                echo sprintf(
                    "workspace %d, account %d, period %d, branch %d: was %d/%d, ledger says %d/%d (fixed)\n",
                    $tenantId, $diff['account_id'], $diff['period_id'], $diff['ou_id'],
                    $diff['was'][0], $diff['was'][1], $diff['now'][0], $diff['now'][1]
                );
            }
        }
        echo sprintf("%d workspace(s) rebuilt, %d balance(s) differed.\n", count($workspaces), $differed);

        return 0;
    }

    /** @return list<int> */
    public static function workspaces(AccountingDb $db, ?int $workspace): array
    {
        if ($workspace !== null) {
            return [$workspace];
        }

        return array_map(
            static fn (array $r): int => (int) $r['tenant_id'],
            // An operator's maintenance command: it only lists which workspaces have books, then works on each one scoped
            $db->all('SELECT tenant_id FROM acc_books WHERE tenant_id > :none ORDER BY tenant_id', [':none' => 0])
        );
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli accounting:rebuild-balances [--workspace=<id>]\n\n";
        echo "Recomputes the monthly account balances from the ledger and prints what differed.\n";
        echo "  --workspace=<id>  one workspace (default: every workspace with books)\n";
    }
}
