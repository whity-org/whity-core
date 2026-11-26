<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * Tells someone on the waitlist that a time they wanted is free (WHIT-1442,
 * design section 39 §8, AP.23). Called after the offer is saved; it must
 * never throw for a delivery failure, only log it.
 */
interface WaitlistMessenger
{
    /**
     * @param array{
     *   to: string, name: string, language: string, service: array<string, string>,
     *   staff_name: string, starts_at: string, time_zone: ?string, offer_url: string, expires_at: string
     * } $offer
     * @return string a {@see BookingRepository} REMINDER_* outcome
     */
    public function waitlistOffer(int $tenantId, array $offer): string;
}
