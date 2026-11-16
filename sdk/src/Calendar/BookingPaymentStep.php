<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * A payment taken before a booking is confirmed, such as a deposit through
 * Whity Pay (SDK 1.98, ADR 0017 section 4).
 *
 * Core asks {@see requirement()} for every booking. Null confirms it at once.
 * A requirement makes the booking `pending_payment`, holding its slot for the
 * workspace's `calendar.payment_hold_minutes`, and core then calls
 * {@see start()} after the booking is saved and sends the customer to the
 * returned URL. The plugin settles it through the host service
 * {@see Bookings}: `confirmPaid()` when the provider says it is paid,
 * `release()` when it will not be. A hold nobody settles expires on its own.
 *
 * Core never takes card details and never moves money.
 */
interface BookingPaymentStep
{
    public function requirement(BookingQuote $quote): ?PaymentRequirement;

    /** @param string $returnUrl where the customer comes back to after paying */
    public function start(PendingBooking $booking, string $returnUrl): PaymentHandoff;
}
