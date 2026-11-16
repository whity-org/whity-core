<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

use DateTimeImmutable;

/** A span when a person is busy outside core, in UTC (SDK 1.98). */
final class BusyInterval
{
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {
        if ($end <= $start) {
            throw new \InvalidArgumentException('A busy interval must end after it starts.');
        }
    }
}
