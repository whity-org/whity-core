<?php

declare(strict_types=1);

namespace Whity\Core\Sandbox;

/**
 * Runs code that must have no effect outside its own return value (WHIT-991):
 * a plugin's proposal preview.
 *
 * {@see \Whity\Mcp\Tools\ReadOnlyRun} rolls back what the code writes to the
 * database. This covers what a rollback can't: while it runs, sending mail,
 * sending a notification, queueing a job and dispatching a hook event are
 * refused with {@see SideEffectBlockedException}. Each of those places asks
 * {@see self::assertAllowed()} first.
 *
 * Per process and re-entrant; the depth is restored in `finally`, so a throw
 * never leaves a worker blocked for its next request.
 */
final class SideEffectGuard
{
    private static int $depth = 0;

    private static string $reason = '';

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public static function blocking(string $reason, callable $run): mixed
    {
        $outer = self::$reason;
        self::$depth++;
        self::$reason = $reason;
        try {
            return $run();
        } finally {
            self::$depth--;
            self::$reason = $outer;
        }
    }

    public static function isBlocking(): bool
    {
        return self::$depth > 0;
    }

    /** @throws SideEffectBlockedException while {@see self::blocking()} runs */
    public static function assertAllowed(string $what): void
    {
        if (self::$depth > 0) {
            throw new SideEffectBlockedException("{$what} is not allowed during " . self::$reason);
        }
    }
}
