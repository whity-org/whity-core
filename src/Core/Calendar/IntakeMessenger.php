<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * Sends a customer the link to an intake form for their booking (WHIT-1443,
 * design section 39 §7.2, AP.20): Send again at the desk. It must never throw
 * for a delivery failure, only log it.
 */
interface IntakeMessenger
{
    /**
     * @param array{to: string, name: string, form: string, url: string, service: array<string, string>, starts_at: string, time_zone: ?string} $intake
     * @return string a {@see BookingRepository} REMINDER_* outcome
     */
    public function intakeForm(int $tenantId, array $intake): string;
}
