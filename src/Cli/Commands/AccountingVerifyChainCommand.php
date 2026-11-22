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
 * `whity-cli accounting:verify-chain` (WHIT-1318, design 32 §3.5, AC.4).
 *
 * Walks each workspace's hash chain of journal entries and says whether it is
 * whole, or where it breaks. Reads only. Exits 1 when a chain is broken.
 */
class AccountingVerifyChainCommand extends BaseCommand implements CliCommand
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

        $broken = 0;
        foreach (AccountingRebuildBalancesCommand::workspaces($db, $workspace) as $tenantId) {
            $result = $ledger->verifyChain($tenantId);
            if ($result['intact']) {
                echo sprintf("workspace %d: %d entries, chain whole\n", $tenantId, $result['entries']);
                continue;
            }
            $broken++;
            echo sprintf("workspace %d: chain BROKEN at entry %d: %s\n", $tenantId, (int) $result['broken_at'], (string) $result['reason']);
        }

        return $broken > 0 ? 1 : 0;
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli accounting:verify-chain [--workspace=<id>]\n\n";
        echo "Checks that no journal entry changed after it was posted, by walking the hash chain.\n";
        echo "  --workspace=<id>  one workspace (default: every workspace with books)\n";
    }
}
