<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

use DateTimeImmutable;

/**
 * A booking about to be made, as a payment step sees it (SDK 1.98, ADR 0017).
 * Times are UTC. The price is the service's shown price in minor units
 * (JOD 12.500 is 12500), or null when the service shows none.
 */
final class BookingQuote
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $serviceId,
        public readonly int $profileId,
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly ?int $priceMinor,
        public readonly string $currency,
        public readonly string $customerEmail,
        /** `public`, `staff` or `agent`. */
        public readonly string $source,
    ) {
    }
}
