<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/** What the configured provider can do (SDK 1.77). */
final class BillingCapabilities
{
    /**
     * @param list<string> $currencies Upper-case ISO 4217 codes the provider takes; empty means any.
     */
    public function __construct(
        public readonly bool $changesQuantity,
        public readonly bool $changesPlan,
        public readonly bool $refunds,
        public readonly bool $customerPortal,
        public readonly bool $testMode,
        public readonly array $currencies = [],
    ) {
    }

    public function takesCurrency(string $currency): bool
    {
        return $this->currencies === [] || in_array(strtoupper($currency), $this->currencies, true);
    }
}
