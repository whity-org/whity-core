<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools\Undo;

/**
 * An agent's action can't be undone now: what it changed has changed again
 * since, or can no longer be read (WHIT-812). The message is for the person.
 */
final class UndoConflictException extends \RuntimeException
{
}
