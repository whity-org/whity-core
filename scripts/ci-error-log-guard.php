<?php

declare(strict_types=1);

/**
 * CI guard: no new bare error_log() in src/ (READINESS CO-1).
 *
 * THE INVARIANT
 * -------------
 * A line written to stderr goes to log aggregation, so it must not carry what
 * an exception carried (a PDO message names the failing values, an HTTP error
 * the upstream body). `Whity\Core\Log\ScrubbedErrorLog` writes it through the
 * error tracker's scrubber; a bare `error_log()` doesn't. Bare calls in `src/`
 * are counted, and only a count ABOVE the ceiling fails: a new one fails CI. A
 * lower count passes with a notice, so lanes moving calls over in parallel
 * never break each other; the ceiling is lowered when convenient.
 *
 * The scanner checks itself first: it must find the one call inside
 * ScrubbedErrorLog, or it exits 2. "Nothing found" and "the pattern stopped
 * matching" must not look the same.
 *
 * Usage:  php scripts/ci-error-log-guard.php
 */

/** Bare error_log() calls in src/, as of WHIT-1349. */
const CEILING = 547;

$root = dirname(__DIR__);

/** @return list<int> line numbers of error_log( calls outside comments */
$calls = static function (string $path): array {
    $found = [];
    foreach (file($path) ?: [] as $number => $line) {
        $code = ltrim($line);
        if (str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
            continue;
        }
        $hits = preg_match_all('/(?<![\w>:$\\\\])\\\\?error_log\s*\(/', $line);
        for ($i = 0; $i < (int) $hits; $i++) {
            $found[] = $number + 1;
        }
    }

    return $found;
};

$wrapper = $root . '/src/Core/Log/ScrubbedErrorLog.php';
if (!is_file($wrapper) || count($calls($wrapper)) !== 1) {
    fwrite(STDERR, "The scanner did not find the one error_log() call in src/Core/Log/ScrubbedErrorLog.php; the pattern is broken.\n");
    exit(2);
}

$skip = [
    str_replace('\\', '/', $wrapper),
    str_replace('\\', '/', $root . '/src/Core/Log/ErrorLogLogger.php'),
];
$found = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (in_array($path, $skip, true)) {
        continue;
    }
    foreach ($calls($path) as $line) {
        $found[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1) . ':' . $line;
    }
}

$count = count($found);
if ($count > CEILING) {
    fwrite(STDERR, "Bare error_log() calls in src/: {$count}, more than the ceiling of " . CEILING . ".\n");
    fwrite(STDERR, "Use Whity\\Core\\Log\\ScrubbedErrorLog::write() or ::failure() instead.\n");
    fwrite(STDERR, "Calls found:\n  " . implode("\n  ", $found) . "\n");
    exit(1);
}
if ($count < CEILING) {
    echo "Bare error_log() calls in src/: {$count}, below the ceiling of " . CEILING . ". OK\n";
    echo "Notice: lower CEILING in scripts/ci-error-log-guard.php to {$count} when convenient.\n";
    exit(0);
}
echo "Bare error_log() calls in src/: {$count}. OK\n";
exit(0);
