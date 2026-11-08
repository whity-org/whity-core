<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * Selling store plugin licences through the deployment's billing plugin (SDK 1.86).
 *
 * On the deployment that runs the plugin store (app.whity.dev), the store sells
 * a paid plugin's licence to an INSTALLATION, and Whity Pay takes the money on
 * the operator's own provider account. The store calls this; Whity Pay
 * provides it through {@see \Whity\Sdk\PluginLicenceSalesInterface}.
 *
 * Resolve it from the container:
 *
 *     $sales = \Whity\app(\Whity\Sdk\Billing\LicenceSales::class);
 *     if ($sales instanceof ProvidedBy && $sales->providerName() === 'WhityPay') { ... }
 *
 * ONE PROVIDER. The host keeps a single slot, stamped with the providing
 * plugin's own name, and refuses a second one. So no other plugin can sit
 * between the store and the provider, or answer in its place. That is why this
 * is a typed slot and not a filter event: any plugin could listen to an event
 * and rewrite the answer.
 *
 * THE TRUTH IS {@see self::status()}. A provider may also announce changes as
 * a hook event, but any plugin can dispatch an event with that name, so an
 * announcement is only a reason to ask.
 *
 * A licence is an ADD-ON product keyed `plugin.<slug>` and priced by the host's
 * add-on plan `plugin.<slug>.<plan>`. The customer is the installation, never a
 * workspace, so a licence never changes a workspace's plan or entitlements.
 */
interface LicenceSales
{
    /** Somewhere to send the buyer, or why not. Never throws for a refusal. */
    public function checkout(LicenceCheckout $checkout): LicenceCheckoutResult;

    /** What is true for a licence now, read from the provider, or null when it is unknown. */
    public function status(string $reference): ?LicenceSaleStatus;
}
