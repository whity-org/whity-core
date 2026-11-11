<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

use DateTimeImmutable;

/**
 * Host service for a calendar sync plugin to keep events from an outside
 * calendar on a person's core calendar (SDK 1.98). Resolve it with
 * `\Whity\app(CalendarEvents::class)`.
 *
 * Events are keyed by the plugin's own `externalRef`, so writing the same
 * one twice updates it. A busy event blocks bookings; the write takes the
 * person's calendar lock like any other.
 */
interface CalendarEvents
{
    /** Create or update; returns the core event id. */
    public function upsertExternal(int $tenantId, int $profileId, string $externalRef, string $title, DateTimeImmutable $startUtc, DateTimeImmutable $endUtc, bool $busy = true): int;

    /** Remove it; false when there was none. */
    public function deleteExternal(int $tenantId, int $profileId, string $externalRef): bool;
}
