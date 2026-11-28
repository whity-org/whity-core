<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * A deposit to refund (SDK 1.177): the booking, the payment reference the
 * step gave to `Bookings::confirmPaid()`, the amount in minor units and its
 * currency (the whole deposit), and the person who pressed Refund.
 */
final class DepositRefund
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $bookingId,
        public readonly string $paymentRef,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly int $actingProfileId,
    ) {
    }
}
