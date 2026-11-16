<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * Where an event happens (ADR 0018): one of the workspace's rooms, which it
 * then holds like a booking does, and/or a place in words ("Head office",
 * a video link).
 */
final class EventPlace
{
    public const LOCATION_MAX = 255;

    public function __construct(
        public readonly ?int $roomId = null,
        public readonly string $location = '',
    ) {
    }
}
