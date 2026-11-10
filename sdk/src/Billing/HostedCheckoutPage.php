<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * For a provider whose checkout must run on a page of the seller's own
 * (SDK 1.77). Paddle is one: its checkout opens inside a page that loads its
 * script, and the payer reaches that page from the provider's payment link.
 *
 * A {@see BillingPortal} that also implements this gets a public page at
 * `GET /api/billing/checkout-page` on the host. The host answers with what
 * {@see self::checkoutPage()} returns and adds no-store caching; the portal
 * decides the HTML and its Content-Security-Policy.
 *
 * THE PAGE ANSWERS STRANGERS. It is unauthenticated by necessity (the payer
 * arrives from the provider with nothing but the query string), so it may show
 * only what the provider's checkout itself shows, must escape everything it
 * echoes, and must check any reference in the query with the provider before
 * using it. Null answers 404.
 */
interface HostedCheckoutPage
{
    /** @param array<string, string> $query The page's query string. */
    public function checkoutPage(array $query): ?CheckoutPage;
}
