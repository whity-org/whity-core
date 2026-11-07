<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/** One subscription a subject holds at the provider (SDK 1.77). */
final class SubscriptionLine
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $priceRef,
        public readonly string $status,
        public readonly bool $grantsAccess,
        public readonly ?int $quantity = null,
    ) {
    }
}
