<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * A {@see BookingMessenger} that can say what happened to a reminder
 * (WHIT-1417, AP.14), so the desk sees "Reminder sent" or "Reminder failed".
 * Like every messenger it never throws for a delivery failure; it reports it.
 */
interface ReportsReminderDelivery
{
    /**
     * Send the reminder and say what happened.
     *
     * @param array<string, mixed> $booking
     * @return string a {@see BookingRepository} REMINDER_* outcome
     */
    public function remindReported(int $tenantId, array $booking): string;
}
