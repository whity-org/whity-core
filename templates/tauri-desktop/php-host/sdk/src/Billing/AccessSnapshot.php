<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * What is true for one subject now, as the provider says (SDK 1.77).
 *
 * `planCode` is the HOST's plan key (the one in {@see PlanPriceTerms}), not a
 * provider product id: the host applies that plan, and with it the plan's
 * entitlements. `status` uses the host's words: trialing, active, past_due,
 * canceled or expired. `accessUntil` is an ISO 8601 date-time.
 */
final class AccessSnapshot
{
    public function __construct(
        public readonly string $subjectRef,
        public readonly bool $hasAccess,
        public readonly ?string $planCode = null,
        public readonly ?string $status = null,
        public readonly ?string $accessUntil = null,
        public readonly bool $cancelAtPeriodEnd = false,
        public readonly ?string $subscriptionRef = null,
        public readonly ?string $planCodeAtPeriodEnd = null,
    ) {
    }

    /** No relationship with this subject at all. */
    public static function none(string $subjectRef): self
    {
        return new self($subjectRef, false);
    }
}
