<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Calendar\BookingPaymentStep;

/**
 * A plugin that takes payment before a booking is confirmed (SDK 1.98,
 * ADR 0017). One deployment has one payment step: a second plugin offering
 * one is refused with a warning.
 *
 * @deprecated 1.161 Use {@see \Whity\Sdk\Contribute\PluginBookingPaymentInterface}, the same type under the name it keeps in 2.0 (D6).
 *             This name keeps working through 2.0.x.
 */
interface PluginBookingPaymentInterface
{
    public function getBookingPaymentStep(): BookingPaymentStep;
}
