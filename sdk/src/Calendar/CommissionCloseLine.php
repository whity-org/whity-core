<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * One member of staff's commission in one currency, in a closed period (SDK
 * 1.141, WHIT-1334). Amounts are minor units of `currency` (CU.1).
 */
final class CommissionCloseLine
{
    public function __construct(
        public readonly int $profileId,
        public readonly string $currency,
        public readonly int $bookings,
        public readonly int $salesMinor,
        public readonly int $commissionMinor,
    ) {
    }
}
