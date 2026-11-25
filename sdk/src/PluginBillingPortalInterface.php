<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Billing\BillingPortal;

/**
 * A plugin that sells the deployment's subscriptions (SDK 1.77).
 *
 * The host asks once, when the plugin loads, and routes its billing surface
 * through the returned portal whenever no hosted billing service is set in the
 * environment. One deployment has one portal: a second plugin offering one is
 * refused with a warning.
 *
 * The provider notification URL is `POST /api/billing/provider-webhook` on the
 * host. It is public and verified by {@see BillingPortal::verifyNotification()}.
 *
 * @deprecated 1.161 Use {@see \Whity\Sdk\Contribute\PluginBillingPortalInterface}, the same type under the name it keeps in 2.0 (D6).
 *             This name keeps working through 2.0.x.
 */
interface PluginBillingPortalInterface
{
    public function getBillingPortal(): BillingPortal;
}
