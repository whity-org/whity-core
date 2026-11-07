<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * One payment the subject made, in minor units (SDK 1.77).
 *
 * `reference` is the provider's id for what {@see BillingPortal::refund()}
 * refunds. `status` is paid, open, refunded or partially_refunded.
 */
final class Receipt
{
    public function __construct(
        public readonly string $reference,
        public readonly string $number,
        public readonly string $status,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly ?string $paidAt = null,
        public readonly ?string $issuedAt = null,
        public readonly int $refundedMinor = 0,
        public readonly ?string $refundedAt = null,
    ) {
    }
}
