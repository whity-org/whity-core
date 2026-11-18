<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * A checkout that takes the card now and charges at the end of a free trial
 * (SDK 1.118, WHIT-1207).
 *
 * Optional: a billing plugin implements it beside {@see BillingPortal} when its
 * provider can start a subscription with a trial (Stripe, Paddle). The host uses
 * it for a workspace that is already in a trial of the plan being bought, so
 * adding a card never cuts the trial short: the subscription starts
 * `trialing` and the first charge is `$trialDays` from now. A portal without
 * it is never asked to sell a trial, and the host then hides the "add a card
 * now" step rather than charging early.
 *
 * The host works out `$trialDays` from its own record of the trial, never from
 * anything the payer sent; it is always at least 1.
 */
interface TrialCheckout
{
    /**
     * Whether the provider configured now can start a trial. A plugin that
     * speaks to several providers answers for the one in use, so the host
     * offers the card step only where it works.
     */
    public function takesTrials(): bool;

    /**
     * @param string                $actor Opaque id of who asked (`profile:12`), never an email.
     * @param array<string, string> $payer Optional `email`, `name`, `locale` to prefill.
     *
     * @throws BillingPortalException REASON_UNSUPPORTED when this provider or price cannot take a trial.
     */
    public function startCheckoutWithTrial(
        string $actor,
        string $subjectRef,
        string $priceRef,
        int $trialDays,
        string $returnUrl,
        ?string $cancelUrl = null,
        array $payer = [],
    ): CheckoutHandoff;
}
