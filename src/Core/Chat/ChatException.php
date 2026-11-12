<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

/**
 * Something the caller asked of a team chat cannot be done.
 *
 * Only Chat code throws it, always with a sentence written for the person who
 * asked, so {@see plain()} is safe to put in a response. `status` is the HTTP
 * status the handler answers with: 404 for a chat the caller is not in (an
 * agent or person never learns that a chat they are not in exists), 403 for
 * something their membership does not allow, 422 for input.
 */
final class ChatException extends \RuntimeException
{
    public function __construct(string $plain, private readonly int $status = 422)
    {
        parent::__construct($plain);
    }

    public static function notFound(): self
    {
        return new self('Chat not found', 404);
    }

    public function plain(): string
    {
        return $this->getMessage();
    }

    public function status(): int
    {
        return $this->status;
    }
}
