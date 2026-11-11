<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Psr\Log\LoggerInterface;
use Whity\Sdk\Calendar\BookingPaymentStep;
use Whity\Sdk\PluginCalendarBusyInterface;

/**
 * What plugins add to the calendar (SDK 1.98, ADR 0017 section 4): at most
 * one payment step for the deployment, and any number of busy-time sources.
 * Filled by the plugin loader, read by the booking service at call time.
 */
final class CalendarPlugins
{
    private ?BookingPaymentStep $paymentStep = null;
    private ?string $paymentPlugin = null;

    /** @var list<ExternalBusySource> */
    private array $busy = [];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    /** Offer a payment step; a second one is refused. Returns whether it was taken. */
    public function offerPaymentStep(string $plugin, BookingPaymentStep $step): bool
    {
        if ($this->paymentStep !== null) {
            $this->logger?->warning('[calendar] a second booking payment step was refused', ['plugin' => $plugin, 'kept' => $this->paymentPlugin]);

            return false;
        }
        $this->paymentStep = $step;
        $this->paymentPlugin = $plugin;

        return true;
    }

    public function addBusyPlugin(string $plugin, PluginCalendarBusyInterface $source): void
    {
        $this->busy[] = new PluginBusySource($plugin, $source, $this->logger);
    }

    public function paymentStep(): ?BookingPaymentStep
    {
        return $this->paymentStep;
    }

    /** @return list<ExternalBusySource> */
    public function busySources(): array
    {
        return $this->busy;
    }
}
