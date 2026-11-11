<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Whity\Sdk\Calendar\Bookings;

/** The SDK's {@see Bookings} host service, over the booking service (SDK 1.98). */
final class HostBookings implements Bookings
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    public function confirmPaid(int $tenantId, int $bookingId, string $paymentRef): bool
    {
        return $this->bookings->confirmPaid($tenantId, $bookingId, $paymentRef);
    }

    public function release(int $tenantId, int $bookingId, string $reason): bool
    {
        return $this->bookings->releaseHold($tenantId, $bookingId, $reason);
    }
}
