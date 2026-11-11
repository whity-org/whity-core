<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;

/**
 * Busy time from outside core, such as a person's Google or Microsoft
 * calendar through a sync plugin (ADR 0017, section 4). The SDK adapter wraps
 * each plugin's provider in one of these.
 *
 * An implementation must answer within its budget and must not throw: a
 * failure is logged and treated as "nothing busy" (fail open), so a slow
 * provider never stops bookings.
 */
interface ExternalBusySource
{
    /** @return list<Interval> */
    public function busyTimes(int $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array;
}
