<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/** A checkout page and the Content-Security-Policy it needs (SDK 1.77). */
final class CheckoutPage
{
    public function __construct(
        public readonly string $html,
        public readonly string $contentSecurityPolicy,
    ) {
        if ($html === '' || $contentSecurityPolicy === '') {
            throw BillingPortalException::unreadable('a checkout page needs HTML and a Content-Security-Policy');
        }
    }
}
