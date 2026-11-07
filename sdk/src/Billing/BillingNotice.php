<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * A verified notification, reduced to what the host may use (SDK 1.77): which
 * delivery it was, so a redelivery is acted on once, and which subject to go
 * and ask about. Nothing else in the payload is trusted.
 */
final class BillingNotice
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $subjectRef,
    ) {
        if ($eventId === '' || $subjectRef === '') {
            throw BillingPortalException::unreadable('a notice needs an event id and a subject');
        }
    }
}
