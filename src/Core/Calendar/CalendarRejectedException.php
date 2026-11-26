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
    /** Why a time is not free (design 3.5, CV.9), sent as `reason` with a 409. */
    public const PERSON_BUSY = 'person_busy';
    public const ROOM_TAKEN = 'room_taken';
    public const CLOSED = 'closed';

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

    /**
     * A 409 that says why: the person is busy (staff may Book anyway), the
     * room is taken, or it is outside the person's hours.
     */
    public static function notFree(string $reason): self
    {
        $message = match ($reason) {
            self::PERSON_BUSY => 'That person is busy then.',
            self::ROOM_TAKEN => 'That room or equipment is taken then.',
            self::CLOSED => 'That is outside their working hours.',
            default => 'That time was just taken. Pick another time.',
        };

        return new self($message, 409, ['reason' => $reason]);
    }
}
