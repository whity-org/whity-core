<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Database\Database;

/**
 * `whity-cli inventory:rebuild-levels` (WHIT-1232, design 31 §3.3).
 *
 * Stock levels are a projection of the append-only ledger. This recomputes
 * them from the moves, for one workspace or every workspace with stock, and
 * prints each level that differed. `--check` only reports. Safe to re-run.
 */
class InventoryCommand extends BaseCommand implements CliCommand
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string>|null */
    public function knownFlags(): ?array
    {
        return ['--workspace', '--check'];
    }

    /** @param array<int, string> $argv */
    public function execute(array $argv): int
    {
        $workspace = null;
        $check = false;
        foreach ($argv as $arg) {
            if ($arg === '--check') {
                $check = true;
            } elseif (str_starts_with($arg, '--workspace=')) {
                $workspace = (int) substr($arg, strlen('--workspace='));
            } elseif ($arg === 'help' || $arg === '--help' || $arg === '-h') {
                $this->showHelp();

                return 0;
            }
        }

        $db = new InventoryDb(Database::connect()->getPdo());
        $workspaces = $workspace !== null ? [$workspace] : array_map(
            static fn (array $r): int => (int) $r['tenant_id'],
            // An operator's maintenance command: it only lists which workspaces have a ledger, then works on each one scoped
            $db->all('SELECT DISTINCT tenant_id FROM inv_moves WHERE tenant_id > :none ORDER BY tenant_id', [':none' => 0])
        );
        $state = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $state);
        $items = new ItemService($db, $state, $units, static fn (int $t): string => 'USD');
        $ledger = new StockLedger($db, $state, $items, $units);

        $differed = 0;
        foreach ($workspaces as $tenantId) {
            foreach ($ledger->rebuild($tenantId, !$check) as $diff) {
                $differed++;
                echo sprintf(
                    "workspace %d, variant %d, place %d, lot %d: was %d, ledger says %d%s\n",
                    $tenantId, $diff['variant_id'], $diff['location_id'], $diff['lot_id'], $diff['was'], $diff['is'], $check ? '' : ' (fixed)'
                );
            }
        }
        echo sprintf("%d workspace(s) checked, %d level(s) %s.\n", count($workspaces), $differed, $check ? 'differ' : 'fixed');

        return $check && $differed > 0 ? 1 : 0;
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli inventory:rebuild-levels [--workspace=<id>] [--check]\n\n";
        echo "Recomputes stock levels from the stock ledger and prints what differed.\n";
        echo "  --workspace=<id>  one workspace (default: every workspace with stock)\n";
        echo "  --check           only report; exit 1 when a level differs\n";
    }
}
