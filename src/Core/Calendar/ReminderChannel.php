<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * Another way to remind a customer of a booking, beside the email core
 * sends (design 3.3: reminders by WhatsApp are a paid plugin). Every channel
 * added to {@see CalendarPlugins} is asked once per reminder, after core's
 * own email, and only for a confirmed booking. A channel decides itself
 * whether it can reach the customer (a phone number, the customer's consent)
 * and must never throw for a delivery failure; core logs and carries on if it
 * does.
 */
interface ReminderChannel
{
    /** A short name for logs and the booking's history, such as `whatsapp`. */
    public function name(): string;

    /**
     * @param array<string, mixed> $booking {@see BookingService::bookingOrFail()}
     */
    public function remind(int $tenantId, array $booking): void;
}
