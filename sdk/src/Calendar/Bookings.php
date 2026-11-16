<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * Host service for a payment step to settle a held booking (SDK 1.98).
 * Resolve it with `\Whity\app(Bookings::class)`.
 *
 * Both act only on a booking in `pending_payment` in the given workspace.
 */
interface Bookings
{
    /**
     * The customer paid: confirm the booking. Returns false when it can no
     * longer be confirmed (it was released, or its hold expired and the time
     * has been taken since); the plugin should then refund.
     */
    public function confirmPaid(int $tenantId, int $bookingId, string $paymentRef): bool;

    /** The payment will not happen: cancel the booking and free its time. */
    public function release(int $tenantId, int $bookingId, string $reason): bool;
}
