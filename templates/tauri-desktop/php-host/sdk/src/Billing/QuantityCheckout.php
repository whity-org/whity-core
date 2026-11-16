<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * A billing portal that can sell several of one price in one checkout
 * (SDK 1.110, WHIT-1152).
 *
 * Optional, beside {@see BillingPortal}. The host uses it for a paid plugin
 * sold per seat, such as Whity for Salons per chair: one subscription whose
 * quantity is the number of seats. A portal without it can only sell one, and
 * the host refuses a checkout for more rather than charging the wrong amount.
 * Changing the quantity later goes through {@see BillingPortal::changeQuantity()}.
 */
interface QuantityCheckout
{
    /**
     * @param int                  $quantity at least 1
     * @param array<string, mixed> $payer    as for {@see BillingPortal::startCheckout()}
     * @throws BillingPortalException REASON_UNSUPPORTED when this provider can't sell a quantity
     */
    public function startCheckoutForQuantity(
        string $actor,
        string $subjectRef,
        string $priceRef,
        int $quantity,
        string $returnUrl,
        ?string $cancelUrl = null,
        array $payer = [],
    ): CheckoutHandoff;
}
