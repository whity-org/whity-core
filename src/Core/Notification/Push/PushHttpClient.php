<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

/** Posts one encrypted message to a push service (WHIT-1074). */
interface PushHttpClient
{
    /**
     * @param array<string, string> $headers
     * @return int The HTTP status, or 0 when the service could not be reached.
     */
    public function post(string $url, array $headers, string $body): int;
}
