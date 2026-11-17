<?php

declare(strict_types=1);

/**
 * Fold changelog fragments into CHANGELOG.md's [Unreleased] section (WHIT-791).
 *
 *     php scripts/changelog-assemble.php           fold changelog.d/ in and delete the fragments
 *     php scripts/changelog-assemble.php --check   only check the fragments (CI)
 *     ... --root=/path/to/repo                     work on another repository
 *
 * This is the one copy of the tool for every Whity repository (WHIT-1057). The
 * others run it through whity-org/.github: the reusable changelog-fragment.yml
 * workflow checks fragments on their pull requests, and changelog/assemble.sh
 * folds them in at release. Both pass --root.
 *
 * `release-prepare.yml` runs the first form just before it dates [Unreleased],
 * so the release commit carries the assembled notes and removes the fragments.
 * CI runs `--check` on every PR so a misnamed fragment fails there, not on the
 * release button. See scripts/lib/changelog-fragments.php for why fragments
 * exist.
 */

require __DIR__ . '/lib/changelog-fragments.php';

$root = dirname(__DIR__);
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/\\');
    }
}
if (!is_dir("{$root}/changelog.d")) {
    fwrite(STDERR, "::error::{$root} has no changelog.d/ folder.\n");
    exit(1);
}
$dir       = "{$root}/changelog.d";
$changelog = "{$root}/CHANGELOG.md";
$checkOnly = in_array('--check', $_SERVER['argv'] ?? [], true);

$problems = changelog_fragment_problems($dir);
foreach ($problems as $name => $found) {
    foreach ($found as $problem) {
        fwrite(STDERR, "::error file=changelog.d/{$name}::{$problem}\n");
    }
}
if ($problems !== []) {
    fwrite(STDERR, count($problems) . " changelog fragment(s) need fixing. See changelog.d/README.md.\n");
    exit(1);
}

$files = changelog_fragment_files($dir);
if ($checkOnly) {
    echo count($files) . " changelog fragment(s), all valid.\n";
    exit(0);
}

if ($files === []) {
    echo "No changelog fragments to fold in.\n";
    exit(0);
}

try {
    $log = changelog_assemble((string) file_get_contents($changelog), changelog_read_fragments($dir));
} catch (RuntimeException $e) {
    fwrite(STDERR, "::error::{$e->getMessage()}\n");
    exit(1);
}

file_put_contents($changelog, $log);
foreach ($files as $name) {
    unlink("{$dir}/{$name}");
}

echo 'Folded ' . count($files) . " changelog fragment(s) into [Unreleased].\n";
