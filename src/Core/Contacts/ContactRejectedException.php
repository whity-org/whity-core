<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

/**
 * A contacts request the caller can fix, with the words to show them and the
 * HTTP status to answer (WHIT-953).
 */
final class ContactRejectedException extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly string $clientMessage,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($clientMessage);
    }

    public static function notFound(string $what): self
    {
        return new self($what . ' not found', 404);
    }
}
