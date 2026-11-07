<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/** Where to send the payer, and the reference the return page asks about (SDK 1.77). */
final class CheckoutHandoff
{
    public function __construct(
        public readonly string $url,
        public readonly string $reference,
    ) {
        if ($url === '' || $reference === '') {
            throw BillingPortalException::unreadable('a checkout needs both a url and a reference');
        }
    }
}
