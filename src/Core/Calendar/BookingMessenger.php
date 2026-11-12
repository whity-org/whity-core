<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * Tells a customer about their booking (ADR 0017, section 5). Called after
 * the booking's transaction commits; an implementation must never throw for
 * a delivery failure, only log it. `$booking` is {@see BookingService::bookingOrFail()};
 * after a create or a move it also carries `manage_token`, the raw token of
 * the customer's new link, which exists nowhere else.
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

    /** @param array<string, mixed> $booking */
    public function remind(int $tenantId, array $booking): void;

    /**
     * The booking is held while a payment step runs (SDK 1.98). It carries the
     * manage link; the confirmation that follows payment does not.
     *
     * @param array<string, mixed> $booking
     */
    public function awaitingPayment(int $tenantId, array $booking): void;

    /**
     * The customer asked for a time and staff accept it first (design 3.3).
     * It carries the manage link.
     *
     * @param array<string, mixed> $booking
     */
    public function requested(int $tenantId, array $booking): void;

    /**
     * Staff suggested another time for the customer's request. It carries the
     * manage link, where the customer takes the new time or picks another.
     *
     * @param array<string, mixed> $booking
     */
    public function suggested(int $tenantId, array $booking): void;
}
