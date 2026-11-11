<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * A calendar request that cannot be done, with the plain-words message the
 * caller may see and the HTTP status it maps to: 404 for something not in this
 * workspace, 409 for a time that was just taken, 422 for anything else.
 */
final class CalendarRejectedException extends \RuntimeException
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

    /** @param array<string, mixed> $extra */
    public static function taken(array $extra = []): self
    {
        return new self('That time was just taken. Pick another time.', 409, $extra);
    }
}
