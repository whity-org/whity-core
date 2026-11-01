<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools\Undo;

/**
 * An agent's action can't be undone now: what it changed has changed again
 * since, or can no longer be read (WHIT-812). The message is for the person.
 */
final class UndoConflictException extends \RuntimeException
{
    /** Written for the person, so it is safe to show them. */
    public function __construct(public readonly string $clientMessage)
    {
        parent::__construct($clientMessage);
    }
}
