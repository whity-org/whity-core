<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

use DateTimeImmutable;

/**
 * A booking held for payment (SDK 1.98). The slot stays the customer's until
 * `holdExpiresAt`; after that core expires it and the slot is free again.
 */
final class PendingBooking
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $bookingId,
        public readonly BookingQuote $quote,
        public readonly PaymentRequirement $requirement,
        public readonly DateTimeImmutable $holdExpiresAt,
    ) {
    }
}
