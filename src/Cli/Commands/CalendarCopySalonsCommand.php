<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Calendar\SalonsCopy;
use Whity\Database\Database;

/**
 * Copies the retired Salons plugin's walk-ins and commission rates into core
 * again (WHIT-1334, design section 35 §6-7), for the rows added since the
 * migration ran. Idempotent.
 *
 *   whity-cli calendar:copy-salons [--check]
 *       Copies, then prints per workspace how many rows Salons holds and how
 *       many core holds from it. With --check, copies nothing and exits 1
 *       when a Salons walk-in has no core row.
 */
final class CalendarCopySalonsCommand implements NamedSubcommand, CommandHelp
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string> */
    public function knownFlags(): array
    {
        return ['--check'];
    }

    /** @param list<string> $argv */
    public function execute(array $argv, string $commandName): int
    {
        $check = false;
        foreach ($argv as $arg) {
            if ($arg === '--check') {
                $check = true;
            } elseif ($arg === 'help' || $arg === '--help' || $arg === '-h') {
                $this->showHelp();

                return 0;
            }
        }

        $copy = new SalonsCopy(Database::connect()->getPdo());
        if (!$check) {
            $done = $copy->run();
            echo sprintf(
                "Copied %d walk-in(s) (%d skipped: their service is gone), %d rate(s), %d role grant(s); walk-ins turned on in %d workspace(s).\n",
                $done['walkins'],
                $done['walkins_skipped'],
                $done['rates'],
                $done['grants'],
                $done['switched_on'],
            );
        }

        $missing = 0;
        foreach ($copy->counts() as $tenantId => $c) {
            echo sprintf(
                "workspace %d: walk-ins %d in Salons, %d copied; rates %d in Salons, %d in core\n",
                $tenantId,
                $c['walkins'],
                $c['walkins_copied'],
                $c['rates'],
                $c['rates_in_core'],
            );
            $missing += max(0, $c['walkins'] - $c['walkins_copied']);
        }

        return $check && $missing > 0 ? 1 : 0;
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli calendar:copy-salons [--check]\n\n";
        echo "Copies the Salons plugin's walk-ins and commission rates into core, again and safely.\n";
        echo "  --check  copy nothing; exit 1 when a Salons walk-in has no core row\n";
    }
}
