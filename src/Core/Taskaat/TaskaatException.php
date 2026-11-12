<?php

declare(strict_types=1);

namespace Whity\Core\Taskaat;

/**
 * A Taskaat connection or call failed in a way a person can act on. The message is plain words
 * for the person; the status is the HTTP status the API answers with.
 */
final class TaskaatException extends \RuntimeException
{
    public function __construct(public readonly string $clientMessage, public readonly int $status = 502)
    {
        parent::__construct($clientMessage);
    }
}
