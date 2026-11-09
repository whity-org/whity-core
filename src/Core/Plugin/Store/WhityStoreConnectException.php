<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Store;

/**
 * Connecting to a Whity store failed. The message is safe to show the operator; the HTTP status
 * says whose problem it is (422 something to fix here, 502 the store or Whity ID, 403 who).
 */
final class WhityStoreConnectException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }

    /** What the operator is told: always one of this class's own fixed sentences. */
    public function clientMessage(): string
    {
        return $this->getMessage();
    }
}
