<?php

declare(strict_types=1);

namespace Whity\Core\Log;

use Whity\Core\Observability\ErrorScrubber;

/**
 * `error_log()` with the error tracker's scrubbing (READINESS CO-1).
 *
 * A line like `'[x] failed: ' . $e->getMessage()` carries whatever the
 * exception carried: a PDO message names the failing values (`Key (email)=(…)
 * already exists`), an HTTP client error carries the upstream body. stderr is
 * shipped to log aggregation, so those values must not reach it raw. Use this
 * instead of a bare `error_log()`; `scripts/ci-error-log-guard.php` keeps the
 * number of bare calls in `src/` from growing.
 */
final class ScrubbedErrorLog
{
    public static function write(string $message): void
    {
        error_log((new ErrorScrubber())->text($message));
    }

    /** `"{$prefix}: {exception message}"`, scrubbed. */
    public static function failure(string $prefix, \Throwable $e): void
    {
        self::write($prefix . ': ' . $e->getMessage());
    }
}
