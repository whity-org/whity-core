<?php

declare(strict_types=1);

namespace Whity\Sdk;

use DateTimeImmutable;
use Whity\Sdk\Calendar\BusyInterval;

/**
 * A plugin that knows when people are busy outside core, such as a Google or
 * Microsoft calendar sync (SDK 1.98, ADR 0017).
 *
 * Core asks when it lists free times and again inside the person's lock
 * before a booking is written. Answer quickly (cache; core logs any answer
 * slower than 1.5 seconds). A throw is logged and treated as "not busy", so
 * a provider that is down never stops bookings.
 */
interface PluginCalendarBusyInterface
{
    /** @return list<BusyInterval> */
    public function busyTimes(int $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array;
}
