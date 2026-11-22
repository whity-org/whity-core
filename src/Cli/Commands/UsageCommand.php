<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Usage\StorageUsage;
use Whity\Core\Usage\UsageSnapshotJob;
use Whity\Core\Usage\UsageSnapshots;
use Whity\Database\Database;

/**
 * The usage ledger's maintenance (section 33 §5).
 *
 *   whity-cli usage:storage-rebuild [--workspace=<id>] [--check]
 *       Sets each workspace's stored bytes from its files again. With --check,
 *       only reports where the kept total differs; exit 1 when one does.
 *   whity-cli usage:snapshot [--workspace=<id>]
 *       Takes the snapshot meters now (seats, customers, items, invoices,
 *       storage), as the daily job does.
 */
final class UsageCommand implements NamedSubcommand, CommandHelp
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string> */
    public function knownFlags(): array
    {
        return ['--workspace', '--check'];
    }

    /** @param list<string> $argv */
    public function execute(array $argv, string $commandName): int
    {
        $command = $commandName;
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
        if ($workspace !== null && $workspace <= 0) {
            fwrite(STDERR, "--workspace takes a workspace id.\n");

            return 2;
        }

        $pdo = Database::connect()->getPdo();

        if ($command === 'usage:snapshot') {
            $snapshots = UsageSnapshots::forDatabase($pdo);
            $ids = $workspace !== null ? [$workspace] : UsageSnapshotJob::workspaceIds($pdo);
            foreach ($ids as $tenantId) {
                $values = $snapshots->take($tenantId);
                echo sprintf("workspace %d: %s\n", $tenantId, self::describe($values));
            }
            echo sprintf("%d workspace(s) snapshotted.\n", count($ids));

            return 0;
        }

        $ids = $workspace !== null ? [$workspace] : StorageUsage::tenantIds($pdo);
        $differed = 0;
        foreach ($ids as $tenantId) {
            $kept = StorageUsage::bytes($pdo, $tenantId);
            if ($check) {
                $sum = $pdo->prepare('SELECT COALESCE(SUM(byte_size), 0) FROM stored_files WHERE tenant_id = :tenant_id');
                $sum->execute([':tenant_id' => $tenantId]);
                $actual = (int) $sum->fetchColumn();
            } else {
                [$actual] = StorageUsage::rebuild($pdo, $tenantId);
            }
            if ($actual !== $kept) {
                $differed++;
                echo sprintf("workspace %d: kept %d bytes, files add up to %d%s\n", $tenantId, $kept, $actual, $check ? '' : ' (fixed)');
            }
        }
        echo sprintf("%d workspace(s) checked, %d total(s) %s.\n", count($ids), $differed, $check ? 'differ' : 'fixed');

        return $check && $differed > 0 ? 1 : 0;
    }

    /** @param array<string, int> $values */
    private static function describe(array $values): string
    {
        $parts = [];
        foreach ($values as $meter => $value) {
            $parts[] = $meter . ' ' . $value;
        }

        return implode(', ', $parts);
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli usage:storage-rebuild [--workspace=<id>] [--check]\n";
        echo "       whity-cli usage:snapshot [--workspace=<id>]\n\n";
        echo "usage:storage-rebuild sets each workspace's stored bytes from its files.\n";
        echo "  --workspace=<id>  one workspace (default: every workspace that stores files)\n";
        echo "  --check           only report; exit 1 when a total differs\n";
        echo "usage:snapshot takes seats, customers, items, invoices and storage into the usage ledger now.\n";
    }
}
