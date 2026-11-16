<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Security\SecretReencryptor;
use Whity\Database\Database;

/**
 * `whity-cli secrets reencrypt [--dry-run]` (merge map plumbing, WHIT-1047):
 * after a key rotation, move every stored secret onto the current key. See
 * {@see SecretReencryptor} for what it does and doesn't touch.
 */
class SecretsCommand extends BaseCommand implements CliCommand
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string>|null */
    public function knownFlags(): ?array
    {
        return ['--dry-run'];
    }

    /** @param list<string> $argv */
    public function execute(array $argv): int
    {
        $action = $argv[0] ?? 'help';
        if ($action === 'help' || $action === '--help' || $action === '-h') {
            $this->showHelp();

            return 0;
        }
        if ($action !== 'reencrypt') {
            echo "Unknown secrets subcommand: {$action}\n";
            $this->showHelp();

            return 1;
        }

        $dryRun = in_array('--dry-run', $argv, true);
        $report = (new SecretReencryptor(Database::connect()->getPdo(), EncryptedSecretStore::fromEnv($_ENV)))->run($dryRun);

        $failed = 0;
        foreach ($report as $line) {
            if ($line['missing']) {
                echo sprintf("  %-40s not in this database\n", $line['label']);
                continue;
            }
            echo sprintf(
                "  %-40s %d %s, %d already current, %d unreadable\n",
                $line['label'],
                $line['moved'],
                $dryRun ? 'to move' : 'moved',
                $line['current'],
                $line['failed'],
            );
            $failed += $line['failed'];
        }
        echo $dryRun ? "Nothing was written (--dry-run).\n" : "Done. Two-step sign-in secrets have their own: whity-cli totp reencrypt.\n";

        return $failed > 0 ? 1 : 0;
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli secrets <subcommand>\n\n";
        echo "Subcommands:\n";
        echo "  reencrypt [--dry-run]   Move every stored secret onto the current key after a key rotation (safe to repeat)\n";
    }
}
