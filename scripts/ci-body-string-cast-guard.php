<?php

declare(strict_types=1);

/**
 * CI request-body cast guard (#2198, #2174).
 *
 * THE INVARIANT
 * -------------
 * A request body field is read as text through the shared field validator
 * (`FieldValidator::text()` or a declared `Field`), not by casting it:
 * `(string) $body['key']` turns a JSON list into the text "Array" (with a PHP
 * warning) and stores it. Casts of `$body[...]` in `src/` are counted, and only
 * a count ABOVE the ceiling fails: a new cast fails CI. A lower count passes
 * with a notice, so lanes moving casts onto the validator in parallel never
 * break each other; the ceiling is lowered when convenient.
 *
 * Usage:  php scripts/ci-body-string-cast-guard.php
 */

/** `(string) $body[...]` casts in src/ outside the validator, as of #2198. */
const CEILING = 120;

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
        $hits = preg_match_all('/\(string\)\s*\(?\s*\$body\[/', $line);
        for ($i = 0; $i < (int) $hits; $i++) {
            $found[] = substr($path, strlen($root) + 1) . ':' . ($number + 1);
        }
    }
}

$count = count($found);
if ($count > CEILING) {
    fwrite(STDERR, "(string) casts of request body fields in src/: {$count}, more than the ceiling of " . CEILING . ".\n");
    fwrite(STDERR, "Read the field with Whity\Http\Validation\FieldValidator::text(\$body, 'field') instead.\n");
    fwrite(STDERR, "Casts found:\n  " . implode("\n  ", $found) . "\n");
    exit(1);
}
if ($count < CEILING) {
    // A NOTICE, NOT A FAILURE. Lanes merge in parallel: if a lower count failed,
    // the first one to move a cast onto the validator would turn every other
    // open PR red until someone lowered the constant.
    echo "(string) casts of request body fields in src/: {$count}, below the ceiling of " . CEILING . ". OK\n";
    echo "Notice: lower CEILING in scripts/ci-body-string-cast-guard.php to {$count} when convenient.\n";
    exit(0);
}

echo "(string) casts of request body fields in src/: {$count} (ceiling " . CEILING . "). OK\n";
