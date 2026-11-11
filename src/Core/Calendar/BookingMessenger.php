<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * Tells a customer about their booking (ADR 0017, section 5). Called after
 * the booking's transaction commits; an implementation must never throw for
 * a delivery failure, only log it.
 *
 * The first implementation sends email through the Mailer; when the core
 * messages API lands (ADR 0016) another implementation sends through it, with
 * no change to the booking code.
 */
interface BookingMessenger
{
    /** @param array<string, mixed> $booking {@see BookingRepository::toApi()} plus `service` */
    public function confirmed(int $tenantId, array $booking): void;

    /** @param array<string, mixed> $booking */
    public function rescheduled(int $tenantId, array $booking): void;

    /** @param array<string, mixed> $booking */
    public function cancelled(int $tenantId, array $booking): void;
}
