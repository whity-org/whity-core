<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * The events the calendar fires (ADR 0017, section 4). Each fires after the
 * write commits, synchronously and as an `.async` twin on the event spine,
 * with ids only: `id`, `tenant_id`, `profile_id` (the staff member), and for
 * bookings `service_id` and `status`. The SDK mirrors these names.
 */
final class CalendarEventNames
{
    public const EVENT_CREATED = 'calendar.event.created';
    public const EVENT_UPDATED = 'calendar.event.updated';
    public const EVENT_DELETED = 'calendar.event.deleted';

    public const BOOKING_CREATED = 'booking.created';
    public const BOOKING_CONFIRMED = 'booking.confirmed';
    public const BOOKING_RESCHEDULED = 'booking.rescheduled';
    public const BOOKING_CANCELLED = 'booking.cancelled';
    public const BOOKING_EXPIRED = 'booking.expired';

    private function __construct()
    {
    }
}
