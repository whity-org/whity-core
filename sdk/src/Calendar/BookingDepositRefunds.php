<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * Refunding a booking's deposit (SDK 1.177, design section 39 §9.3, AP.26).
 *
 * A payment step that takes deposits ({@see BookingPaymentStep}) may also
 * implement this, on the same object. Core calls {@see refund()} only when a
 * person with `calendar:manage` presses Refund on the booking: never on its
 * own, never for a deposit that was kept or applied to the bill. Core records
 * who refunded it and writes it to the audit log.
 *
 * Return the provider's reference for the refund. Throw when the provider
 * refuses it: core then tells the person the refund didn't go through, and
 * the deposit stays as it was. A step that doesn't implement this leaves the
 * refund to be made in the provider's own dashboard and marked as refunded.
 */
interface BookingDepositRefunds
{
    public function refund(DepositRefund $refund): string;
}
