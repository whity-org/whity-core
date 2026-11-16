<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use Whity\Sdk\Calendar\CalendarEvents;

/**
 * The SDK's {@see CalendarEvents} host service (SDK 1.98): events from an
 * outside calendar, keyed by the plugin's own reference, written through the
 * booking service so they take the person's lock and fire the usual events.
 * Only staff of the workspace can have them.
 */
final class HostCalendarEvents implements CalendarEvents
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly CalendarRepository $calendar,
    ) {
    }

    public function upsertExternal(int $tenantId, int $profileId, string $externalRef, string $title, DateTimeImmutable $startUtc, DateTimeImmutable $endUtc, bool $busy = true): int
    {
        if ($externalRef === '' || strlen($externalRef) > 255 || !$this->calendar->isStaff($tenantId, $profileId)) {
            throw new \InvalidArgumentException('An external event needs a reference and a member of staff of this workspace.');
        }
        $when = new Interval($startUtc, $endUtc);
        if ($when->isEmpty()) {
            throw new \InvalidArgumentException('An event must end after it starts.');
        }
        $title = mb_substr(trim($title) !== '' ? trim($title) : 'Busy', 0, 200);
        $existing = $this->calendar->findEventByExternalRef($tenantId, $profileId, $externalRef);
        if ($existing !== null) {
            $this->bookings->updateEvent($tenantId, $existing, $title, '', $when, false, $busy);

            return $existing;
        }

        return (int) $this->bookings->createEvent($tenantId, $profileId, $title, '', $when, false, $busy, null, $externalRef)['event']['id'];
    }

    public function deleteExternal(int $tenantId, int $profileId, string $externalRef): bool
    {
        $existing = $this->calendar->findEventByExternalRef($tenantId, $profileId, $externalRef);
        if ($existing === null) {
            return false;
        }
        $this->bookings->cancelEvent($tenantId, $existing);

        return true;
    }
}
