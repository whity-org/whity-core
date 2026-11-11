<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Whity\Sdk\Hooks\Events;

/**
 * The events the calendar fires (ADR 0017, section 4). Each fires after the
 * write commits, synchronously and as an `.async` twin on the event spine,
 * with ids only: `id`, `tenant_id`, `profile_id` (the staff member), and for
 * bookings `service_id` and `status`. The names are the SDK's (1.98).
 */
final class CalendarEventNames
{
    public const EVENT_CREATED = Events::CALENDAR_EVENT_CREATED;
    public const EVENT_UPDATED = Events::CALENDAR_EVENT_UPDATED;
    public const EVENT_DELETED = Events::CALENDAR_EVENT_DELETED;

    public const BOOKING_CREATED = Events::BOOKING_CREATED;
    public const BOOKING_CONFIRMED = Events::BOOKING_CONFIRMED;
    public const BOOKING_RESCHEDULED = Events::BOOKING_RESCHEDULED;
    public const BOOKING_CANCELLED = Events::BOOKING_CANCELLED;
    public const BOOKING_EXPIRED = Events::BOOKING_EXPIRED;

    private function __construct()
    {
    }
}
