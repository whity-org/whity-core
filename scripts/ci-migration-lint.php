<?php

declare(strict_types=1);

/**
 * Migration safety lint (WHIT-1240). See docs/ops/migrations.md.
 *
 *   php scripts/ci-migration-lint.php            lint the migrations newer than
 *                                                the schema baseline; exit 1 on
 *                                                any error (CI runs this)
 *   php scripts/ci-migration-lint.php --all      report on every migration,
 *                                                with totals; always exit 0
 *   php scripts/ci-migration-lint.php FILE...    lint just these files
 *
 * Migrations in the baseline have already run everywhere that matters, so CI
 * holds only new ones to the rules; `--all` is for looking back.
 */

use Whity\Database\Migration\Baseline;
use Whity\Database\Migration\MigrationLint;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$args = array_slice($argv, 1);
$all = in_array('--all', $args, true);
$explicit = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--')));

$lint = MigrationLint::forRepository($root);

if ($explicit !== []) {
    $files = $explicit;
} else {
    $files = glob($root . '/database/migrations/*.php') ?: [];
    sort($files);
    if (!$all) {
        $baseline = Baseline::fromDirectory($root . '/database/baseline');
        $from = $baseline?->through() ?? 0;
        $files = array_values(array_filter($files, static fn(string $f): bool => (int) basename($f) > $from));
    }
}

$findings = [];
foreach ($files as $file) {
    array_push($findings, ...$lint->lintFile($file));
}

$errors = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'error'));

foreach ($findings as $f) {
    printf(
        "%s %s:%d [%s] %s\n    %s\n",
        $f['severity'] === 'error' ? 'ERROR  ' : 'warning',
        $f['migration'],
        $f['line'],
        $f['rule'],
        $f['message'],
        $f['sql']
    );
}

if ($all) {
    $byRule = [];
    foreach ($findings as $f) {
        $byRule[$f['rule']][$f['severity']] = ($byRule[$f['rule']][$f['severity']] ?? 0) + 1;
    }
    ksort($byRule);
    $migrations = count(array_unique(array_column($findings, 'migration')));
    echo "\nTotals over " . count($files) . " migrations ({$migrations} with findings):\n";
    foreach ($byRule as $rule => $counts) {
        printf("  %-22s %3d error(s) %3d warning(s)\n", $rule, $counts['error'] ?? 0, $counts['warning'] ?? 0);
    }
    exit(0);
}

$checked = count($files);
if ($errors !== []) {
    echo "\n" . count($errors) . " error(s) in {$checked} migration(s). Each rule and its safe form: docs/ops/migrations.md.\n"
        . "If a finding is wrong for this migration, say why in it: // migration-lint: allow <rule> <reason>\n";
    exit(1);
}

echo "Migration lint: {$checked} migration(s) checked, no errors"
    . ($findings !== [] ? ', ' . count($findings) . ' warning(s)' : '') . ".\n";
exit(0);
