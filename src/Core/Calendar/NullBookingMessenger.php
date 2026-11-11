<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/** Sends nothing. Used until a real messenger is wired, and in tests. */
final class NullBookingMessenger implements BookingMessenger
{
    public function confirmed(int $tenantId, array $booking): void
    {
    }

    public function rescheduled(int $tenantId, array $booking): void
    {
    }

    public function cancelled(int $tenantId, array $booking): void
    {
    }
}
