<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Whity\Core\Calendar\HoldConflicts;
use Whity\Database\Database;

/**
 * Lists live bookings that overlap another on the same person or room
 * (WHIT-1412, design section 39 §5.1, AP.12). Writes nothing.
 *
 *   whity-cli calendar:overlaps [--tenant=ID] [--all] [--check]
 *       From now on (--all: also the past). "before the guard" marks a
 *       booking that already overlapped when migration 360 arrived; the rest
 *       are Book anyway bookings, which staff chose. With --check, exits 1
 *       when a booking from before the guard still overlaps from now on.
 */
final class CalendarOverlapsCommand implements NamedSubcommand, CommandHelp
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string> */
    public function knownFlags(): array
    {
        return ['--tenant', '--all', '--check'];
    }

    /** @param list<string> $argv */
    public function execute(array $argv, string $commandName): int
    {
        $tenantId = null;
        $all = false;
        $check = false;
        foreach ($argv as $arg) {
            if ($arg === 'help' || $arg === '--help' || $arg === '-h') {
                $this->showHelp();

                return 0;
            }
            if ($arg === '--all') {
                $all = true;
            } elseif ($arg === '--check') {
                $check = true;
            } elseif (preg_match('/^--tenant=(\d+)$/', $arg, $m) === 1) {
                $tenantId = (int) $m[1];
            } else {
                fwrite(STDERR, "Unknown option: {$arg}\n");
                $this->showHelp();

                return 2;
            }
        }

        $pdo = Database::connect()->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            echo "Only PostgreSQL keeps holds; on SQLite the calendar's locks are the guard. Nothing to list.\n";

            return 0;
        }
        $from = $all ? new DateTimeImmutable('1970-01-01', new DateTimeZone('UTC')) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rows = HoldConflicts::overlaps($pdo, $tenantId, $from);
        $before = 0;
        foreach ($rows as $row) {
            echo sprintf(
                "workspace %d: booking %d overlaps booking %d on %s %d, %s to %s UTC (%s)\n",
                $row['tenant_id'],
                $row['booking_id'],
                $row['other_booking_id'],
                $row['holder_kind'] === 'resource' ? 'room' : 'person',
                $row['holder_id'],
                $row['holds_from'],
                $row['holds_until'],
                $row['before_guard'] ? 'before the guard' : 'Book anyway',
            );
            if ($row['before_guard']) {
                $before++;
            }
        }
        echo sprintf("%d overlap(s), %d from before the guard.\n", count($rows), $before);

        return $check && $before > 0 ? 1 : 0;
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli calendar:overlaps [--tenant=ID] [--all] [--check]\n\n";
        echo "Lists live bookings that overlap another on the same person or room. Writes nothing.\n";
        echo "  --tenant=ID  one workspace only\n";
        echo "  --all        include the past (default: from now on)\n";
        echo "  --check      exit 1 when a booking from before the guard still overlaps\n";
    }
}
