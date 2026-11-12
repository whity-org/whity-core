<?php

declare(strict_types=1);

namespace Whity\Core\Http;

/**
 * Work that runs after the response has gone to the client (#1872).
 *
 * For work whose duration must not show in the response time. The customer
 * team invitation answers the same whether or not it sent an email; sending the
 * email inside the request would make the two answers take visibly different
 * times. So the handler defers the send here, and the entry point runs it
 * after `send()`, once the request is finished for the client.
 *
 * Process-wide like the request it serves: the entry point calls
 * {@see self::run()} once per request, and {@see self::reset()} drops anything
 * left over. A deferred callback that throws is logged and the rest still run.
 */
final class AfterResponse
{
    /** @var list<\Closure(): void> */
    private static array $queue = [];

    private function __construct()
    {
    }

    /** @param \Closure(): void $work */
    public static function defer(\Closure $work): void
    {
        self::$queue[] = $work;
    }

    /**
     * Finish the request for the client when the server allows it, then run
     * and forget everything deferred.
     */
    public static function run(bool $finishRequest = true): void
    {
        if (self::$queue === []) {
            return;
        }

        if ($finishRequest) {
            if (function_exists('frankenphp_finish_request')) {
                \frankenphp_finish_request();
            } elseif (function_exists('fastcgi_finish_request')) {
                \fastcgi_finish_request();
            }
        }

        $queue = self::$queue;
        self::$queue = [];
        foreach ($queue as $work) {
            try {
                $work();
            } catch (\Throwable $e) {
                error_log('[after-response] deferred work failed: ' . $e->getMessage());
            }
        }
    }

    public static function reset(): void
    {
        self::$queue = [];
    }
}
