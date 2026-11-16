<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

use Whity\Core\Response;

/**
 * The one answer to a request over a limit (merge map plumbing, WHIT-1158):
 * 429, `Retry-After`, the code `rate_limited`, how many seconds to wait, and
 * the same plain sentence everywhere: "Too many attempts. Try again in 5
 * minutes." Clients read the status and the code; the sentence is for people.
 */
final class RateLimited
{
    public const CODE = 'rate_limited';

    private function __construct()
    {
    }

    public static function response(int $retryAfterSeconds): Response
    {
        $wait = max(1, $retryAfterSeconds);

        return Response::error(self::sentence($wait), 429, ['code' => self::CODE, 'retry_after' => $wait])
            ->withHeaders(['Retry-After' => (string) $wait]);
    }

    /** "Try again in a minute", "in 5 minutes", "in 3 hours". */
    public static function sentence(int $seconds): string
    {
        if ($seconds <= 60) {
            return 'Too many attempts. Try again in a minute.';
        }
        $minutes = (int) ceil($seconds / 60);
        if ($minutes <= 90) {
            return "Too many attempts. Try again in {$minutes} minutes.";
        }
        $hours = (int) ceil($minutes / 60);

        return "Too many attempts. Try again in {$hours} hours.";
    }
}
