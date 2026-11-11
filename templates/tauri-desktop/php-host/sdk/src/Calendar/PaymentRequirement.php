<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * What a booking must pay before it is confirmed (SDK 1.98): an amount in
 * minor units, its currency, and a short label the customer reads, such as
 * "Deposit".
 */
final class PaymentRequirement
{
    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $label = '',
    ) {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('A payment requirement must be for more than nothing.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('The currency must be a three-letter code such as JOD.');
        }
    }
}
