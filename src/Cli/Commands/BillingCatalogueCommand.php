<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Plan\CatalogueImporter;
use Whity\Database\Database;

/**
 * `whity-cli billing:catalogue:apply <file.json>` adds plans, prices and limits
 * from a file. It only ever adds: see {@see CatalogueImporter}.
 *
 * `--check` validates the file without connecting to a database, so a catalogue
 * can be proved correct on a machine that has no install at all.
 */
final class BillingCatalogueCommand implements CliCommand, CommandHelp
{
    public function printHelp(string $commandName): bool
    {
        echo <<<HELP
        whity-cli billing:catalogue:apply <file.json> [--check]

        Adds the plans, prices and limits a JSON file lists. It never changes what
        is already there, so anything edited at /admin/billing stays as it is and
        running it twice changes nothing. It reports what it added and skipped.

        The whole file is checked first. If anything is wrong, nothing is written.

        Options:
          --check   Validate the file and stop. Needs no database.

        Amounts are whole minor units of the currency (fils for JOD). A plan with
        no prices cannot be bought, which is how a quote-only plan is written.

        HELP;

        return true;
    }

    /** @return list<string> */
    public function knownFlags(): array
    {
        return ['--check'];
    }

    /**
     * @param array<int, string> $argv
     */
    public function execute(array $argv): int
    {
        $check = in_array('--check', $argv, true);
        $files = array_values(array_filter($argv, static fn (string $arg): bool => !str_starts_with($arg, '--')));

        if (count($files) !== 1) {
            echo "Name one catalogue file.\n";
            $this->printHelp('billing:catalogue:apply');

            return 1;
        }

        $raw = is_file($files[0]) ? file_get_contents($files[0]) : false;
        if ($raw === false) {
            echo "Cannot read {$files[0]}.\n";

            return 1;
        }

        try {
            $catalogue = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            echo "{$files[0]} is not valid JSON: {$e->getMessage()}\n";

            return 1;
        }

        $problems = CatalogueImporter::problems($catalogue);
        if ($problems !== []) {
            echo "Nothing was written. Problems in {$files[0]}:\n";
            foreach ($problems as $problem) {
                echo "  - {$problem}\n";
            }

            return 1;
        }

        if ($check) {
            echo sprintf("%s is valid: %d plan(s). Nothing was written.\n", $files[0], count($catalogue['plans']));

            return 0;
        }

        $report = CatalogueImporter::forDatabase(Database::connect()->getPdo())->apply($catalogue);

        foreach (['added' => 'Added', 'skipped' => 'Skipped'] as $kind => $title) {
            echo sprintf("%s: %d\n", $title, count($report[$kind]));
            foreach ($report[$kind] as $line) {
                echo "  {$line}\n";
            }
        }

        return 0;
    }
}
