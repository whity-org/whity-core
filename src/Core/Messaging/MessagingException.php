<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

/**
 * Something the caller asked for cannot be sent or done.
 *
 * Only Messages code throws it, always with a sentence written for the person
 * who asked ("The message is empty."), never with driver or provider text. That
 * is what makes {@see plain()} safe to put in a response, unlike
 * `getMessage()` on an arbitrary exception (WC-186).
 */
final class MessagingException extends \RuntimeException
{
    /** The plain-words reason, safe to show to the person who asked. */
    public function plain(): string
    {
        return $this->getMessage();
    }
}
