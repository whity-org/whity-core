<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Audit\AuditSeals;
use Whity\Database\Database;

/**
 * The audit trail's hash chain (WHIT-1339).
 *
 *   whity-cli audit:seal [--settle=<seconds>]
 *       Seals every settled audit row after the head now, as the five-minute
 *       job does, and prints the head.
 *   whity-cli audit:verify
 *       Recomputes the chain from the first seal. Exit 1 when a sealed row was
 *       changed or removed, or the head doesn't match; rows below the head that
 *       were never sealed are reported, not failed.
 */
final class AuditCommand implements NamedSubcommand, CommandHelp
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string> */
    public function knownFlags(): array
    {
        return ['--settle'];
    }

    /** @param list<string> $argv */
    public function execute(array $argv, string $commandName): int
    {
        $settle = AuditSeals::SETTLE_SECONDS;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--settle=')) {
                $value = substr($arg, strlen('--settle='));
                if (!ctype_digit($value)) {
                    fwrite(STDERR, "--settle takes a number of seconds.\n");

                    return 2;
                }
                $settle = (int) $value;
            } elseif ($arg === 'help' || $arg === '--help' || $arg === '-h') {
                $this->showHelp();

                return 0;
            }
        }

        $seals = new AuditSeals(Database::connect()->getPdo());

        if ($commandName === 'audit:seal') {
            $total = 0;
            do {
                $result = $seals->seal(5000, $settle);
                $total += $result['sealed'];
            } while ($result['sealed'] === 5000);
            echo sprintf(
                "Sealed %d row(s). Head: audit_id %d, %d seal(s), %s\n",
                $total,
                $result['last_audit_id'],
                $result['sealed_count'],
                $result['head_hash']
            );

            return 0;
        }

        $result = $seals->verify();
        if ($result['ok']) {
            echo sprintf(
                "The chain holds: %d sealed row(s) up to audit_id %d. Head %s\n",
                $result['checked'],
                $result['last_audit_id'],
                $result['head_hash']
            );
        } else {
            echo sprintf(
                "The chain is broken%s: %s (%d row(s) checked before it).\n",
                $result['broken_at'] !== null ? ' at audit_id ' . $result['broken_at'] : '',
                (string) $result['reason'],
                $result['checked']
            );
        }
        if ($result['unsealed_below_head'] > 0) {
            echo sprintf(
                "%d row(s) below the head have no seal: they committed after sealing passed their id.\n",
                $result['unsealed_below_head']
            );
        }

        return $result['ok'] ? 0 : 1;
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli audit:seal [--settle=<seconds>]\n";
        echo "       whity-cli audit:verify\n\n";
        echo "audit:seal seals settled audit rows into the hash chain now (default: rows older than 300 seconds).\n";
        echo "audit:verify recomputes the chain; exit 1 when a sealed row was changed or removed.\n";
    }
}
