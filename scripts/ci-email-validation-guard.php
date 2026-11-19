<?php

declare(strict_types=1);

/**
 * CI email-validation guard (#2174).
 *
 * THE INVARIANT
 * -------------
 * Request fields are checked by the shared field validator
 * (`src/Http/Validation`), not by hand. Hand-written
 * `filter_var(…, FILTER_VALIDATE_EMAIL)` calls elsewhere in `src/` are counted,
 * and the count may only go down: a new handler that checks an email address
 * by hand fails CI, and one that moves onto the validator lowers the ceiling.
 *
 * WHY A COUNT AND NOT A LIST
 * --------------------------
 * The handlers still checking by hand move over in later releases (#2174). A
 * per-file list would need editing on every move; a ceiling only asks that
 * the number is lowered here when one moves, which this script says when it
 * finds fewer than the ceiling.
 *
 * Usage:  php scripts/ci-email-validation-guard.php
 */

/** Hand-written email checks in src/ outside the validator, as of #2174 part 1. */
const CEILING = 35;

$root = dirname(__DIR__);
$validatorDir = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Validation';

$found = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_starts_with(str_replace('/', DIRECTORY_SEPARATOR, $path), $validatorDir)) {
        continue;
    }
    $lines = file($path) ?: [];
    foreach ($lines as $number => $line) {
        if (str_contains($line, 'FILTER_VALIDATE_EMAIL')) {
            $found[] = substr($path, strlen($root) + 1) . ':' . ($number + 1);
        }
    }
}

$count = count($found);
if ($count > CEILING) {
    fwrite(STDERR, "Hand-written email checks in src/: {$count}, more than the ceiling of " . CEILING . ".\n");
    fwrite(STDERR, "Check request fields with Whity\\Http\\Validation\\FieldValidator (Field::email()) instead.\n");
    fwrite(STDERR, "Uses found:\n  " . implode("\n  ", $found) . "\n");
    exit(1);
}
if ($count < CEILING) {
    fwrite(STDERR, "Hand-written email checks in src/: {$count}, fewer than the ceiling of " . CEILING . ".\n");
    fwrite(STDERR, "Lower CEILING in scripts/ci-email-validation-guard.php to {$count} so it cannot creep back.\n");
    exit(1);
}

echo "Hand-written email checks in src/: {$count} (ceiling " . CEILING . "). OK\n";
