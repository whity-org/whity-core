<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * One of the host's plan prices, as a portal needs it to create or find the
 * matching provider price (SDK 1.77).
 *
 * `priceId` is the host's own id and never changes for a price; a new amount
 * is a new price. `billingPeriod` is month, quarter, year or once.
 */
final class PlanPriceTerms
{
    public function __construct(
        public readonly int $priceId,
        public readonly string $planKey,
        public readonly string $planName,
        public readonly string $currency,
        public readonly int $unitAmountMinor,
        public readonly string $billingPeriod,
        public readonly bool $isPerSeat = false,
        public readonly bool $isPerDevice = false,
    ) {
    }

    public function isRecurring(): bool
    {
        return $this->billingPeriod !== 'once';
    }
}
