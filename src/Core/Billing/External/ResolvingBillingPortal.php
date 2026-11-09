<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use Whity\Sdk\Billing\BillingPortal as SdkBillingPortal;

/**
 * The billing portal this deployment actually uses, decided per call (WHIT-1010).
 *
 * ORDER: the hosted billing service named in the environment (PAY_BASE_URL and
 * PAY_API_KEY) wins when it is configured, because an operator who set it did
 * so on purpose and deliberately. Otherwise a plugin's portal when one is
 * installed and configured. Otherwise the environment portal as it is, which
 * on a self-hosted install is {@see NullBillingPortal}: it bills nobody and
 * walls nobody.
 *
 * DECIDED AT CALL TIME because plugins load after the billing handler is
 * built, and because an operator who enters a provider's keys in the plugin's
 * setup screen should not have to restart anything for checkout to appear.
 */
final class ResolvingBillingPortal implements BillingPortal
{
    public function __construct(
        private readonly BillingPortal $environment,
        private readonly PluginBillingPortals $plugins,
    ) {
    }

    /** The plugin's own portal, only when it is the one in use. */
    public function pluginPortal(): ?SdkBillingPortal
    {
        $active = $this->active();

        return $active instanceof SdkBillingPortalBridge ? $active->sdk() : null;
    }

    /** The bridge, for the routes only a plugin portal answers (portal link, refund, notifications). */
    public function pluginBridge(): ?SdkBillingPortalBridge
    {
        $active = $this->active();

        return $active instanceof SdkBillingPortalBridge ? $active : null;
    }

    public function active(): BillingPortal
    {
        if ($this->environment->isConfigured()) {
            return $this->environment;
        }

        $plugin = $this->plugins->portal();
        if ($plugin !== null) {
            $bridge = new SdkBillingPortalBridge($plugin);
            if ($bridge->isConfigured()) {
                return $bridge;
            }
        }

        return $this->environment;
    }

    public function isConfigured(): bool
    {
        return $this->active()->isConfigured();
    }

    public function startCheckout(
        BillingActor $actor,
        string $subjectRef,
        string $priceRef,
        string $returnUrl,
        ?string $cancelUrl = null,
        array $payer = [],
    ): CheckoutHandoff {
        return $this->active()->startCheckout($actor, $subjectRef, $priceRef, $returnUrl, $cancelUrl, $payer);
    }

    public function accessFor(string $subjectRef): AccessSnapshot
    {
        return $this->active()->accessFor($subjectRef);
    }

    public function checkoutStatus(string $reference): string
    {
        return $this->active()->checkoutStatus($reference);
    }

    public function receiptsFor(string $subjectRef): array
    {
        return $this->active()->receiptsFor($subjectRef);
    }

    public function subscriptionsFor(string $subjectRef): array
    {
        return $this->active()->subscriptionsFor($subjectRef);
    }

    public function changeQuantity(BillingActor $actor, string $subscriptionRef, int $quantity): void
    {
        $this->active()->changeQuantity($actor, $subscriptionRef, $quantity);
    }

    public function changePlan(
        BillingActor $actor,
        string $subscriptionRef,
        string $priceRef,
        string $proration = self::PRORATION_IMMEDIATE,
        bool $invoice = true,
        ?string $idempotencyKey = null,
    ): void {
        $this->active()->changePlan($actor, $subscriptionRef, $priceRef, $proration, $invoice, $idempotencyKey);
    }
}
