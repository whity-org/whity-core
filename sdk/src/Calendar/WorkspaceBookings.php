<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * Settle a held booking in the workspace the call runs in (SDK 1.161, decision
 * D2). Get it from {@see \Whity\Sdk\Context\PluginScope::bookings()}.
 *
 * The same service as {@see Bookings}, without the workspace argument: the
 * host uses the workspace the request or job runs in, so a call can't name
 * another. Both act only on a booking in `pending_payment`. Without a
 * workspace every call throws \RuntimeException.
 */
interface WorkspaceBookings
{
    /**
     * The customer paid: confirm the booking. False when it can no longer be
     * confirmed; the plugin should then refund.
     */
    public function confirmPaid(int $bookingId, string $paymentRef): bool;

    /** The payment will not happen: cancel the booking and free its time. */
    public function release(int $bookingId, string $reason): bool;
}
