<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

/**
 * Something the caller asked for cannot be sent or done. The message is plain
 * words safe to show to the person who asked.
 */
final class MessagingException extends \RuntimeException
{
}
