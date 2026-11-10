<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use Whity\Core\Container\HostWiredService;
use Whity\Sdk\Billing\BillingPortal as SdkBillingPortal;

/**
 * The one slot a plugin's billing portal goes into (SDK 1.77, WHIT-1010).
 *
 * Built before plugins load and filled by {@see \Whity\Core\PluginLoader} when
 * a plugin implementing {@see \Whity\Sdk\PluginBillingPortalInterface} loads.
 * {@see ResolvingBillingPortal} reads it at call time, which is why the billing
 * handler can be constructed long before the plugin exists.
 *
 * ONE PORTAL. A second offer is refused, never merged: two plugins selling the
 * same subscriptions would each hold some customers, and a notification would
 * be verified by whichever was asked first.
 */
final class PluginBillingPortals implements HostWiredService
{
    private ?string $owner = null;
    private ?SdkBillingPortal $portal = null;

    /** @return bool False when another plugin already holds the slot. */
    public function offer(string $pluginName, SdkBillingPortal $portal): bool
    {
        if ($this->portal !== null && $this->owner !== $pluginName) {
            return false;
        }

        $this->owner = $pluginName;
        $this->portal = $portal;

        return true;
    }

    public function portal(): ?SdkBillingPortal
    {
        return $this->portal;
    }

    public function ownerName(): ?string
    {
        return $this->owner;
    }
}
