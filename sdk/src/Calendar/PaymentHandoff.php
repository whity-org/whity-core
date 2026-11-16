<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/** Where to send the customer to pay (SDK 1.98): an absolute https URL. */
final class PaymentHandoff
{
    public function __construct(public readonly string $url)
    {
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            throw new \InvalidArgumentException('A payment handoff needs an absolute URL.');
        }
    }
}
