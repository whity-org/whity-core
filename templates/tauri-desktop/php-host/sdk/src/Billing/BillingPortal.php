<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * Where a deployment sells its own subscriptions (SDK 1.77).
 *
 * A plugin that takes payments through a provider (Stripe, Paddle, Polar, or
 * any other) implements this and returns it from
 * {@see \Whity\Sdk\PluginBillingPortalInterface::getBillingPortal()}. The host
 * then routes its existing billing surface through it: checkout, the return
 * page, the signed notification, plan and quantity changes, receipts, the
 * customer portal and refunds. The plugin never writes a subscription, a plan
 * or an entitlement itself. The host does that, from {@see self::accessFor()}.
 *
 * NOTHING A NOTIFICATION SAYS GRANTS ACCESS
 * -----------------------------------------
 * {@see self::verifyNotification()} returns only WHICH subject changed. The
 * host then asks {@see self::accessFor()}, which must read the provider's
 * current state rather than the payload. Out-of-order delivery stops
 * mattering, and a leaked signing secret can only make the host re-read its
 * own state.
 *
 * SUBJECTS ARE OPAQUE
 * -------------------
 * The host names who is paying with a subject reference (today
 * `tenant-<id>`). A portal stores it against the provider's customer and
 * gives it back in {@see BillingNotice}; it never parses it.
 *
 * MONEY IS IN MINOR UNITS, with the currency's own exponent. The dinar has
 * three decimals: 49.000 JOD is 49000.
 *
 * FAILURE is always a {@see BillingPortalException}. The host maps its reason
 * onto an answer and never shows its message to a customer.
 */
interface BillingPortal
{
    public const PRORATION_IMMEDIATE = 'immediate';
    public const PRORATION_AT_PERIOD_END = 'at_period_end';
    public const PRORATION_NONE = 'none';

    /**
     * A stable, lower-case name for the notification ledger and the logs,
     * for example `whity-pay`. It must not change once events have arrived.
     */
    public function name(): string;

    /** Whether this portal can take a payment right now (provider chosen, keys present). */
    public function isConfigured(): bool;

    /** What the configured provider can do, so the host offers only that. */
    public function capabilities(): BillingCapabilities;

    /**
     * The provider's price for one of the host's plan prices, creating it at
     * the provider when it does not exist yet. Must be idempotent: the same
     * terms always give the same reference.
     *
     * @throws BillingPortalException REASON_REFUSED when the provider cannot sell on these terms (an unsupported currency, for example).
     */
    public function priceRefFor(PlanPriceTerms $terms): string;

    /**
     * Somewhere to send the payer. `$returnUrl` is where the provider sends
     * them back; the portal adds `checkout=<reference>` to it.
     *
     * @param string                $actor Opaque id of who asked (`profile:12`), never an email.
     * @param array<string, string> $payer Optional `email`, `name`, `locale` to prefill.
     */
    public function startCheckout(
        string $actor,
        string $subjectRef,
        string $priceRef,
        string $returnUrl,
        ?string $cancelUrl = null,
        array $payer = [],
    ): CheckoutHandoff;

    /** What is true for this subject NOW, read from the provider. */
    public function accessFor(string $subjectRef): AccessSnapshot;

    /** `open`, `complete` or `expired`, for the message on the return page. */
    public function checkoutStatus(string $reference): string;

    /** @return list<Receipt> Newest first. */
    public function receiptsFor(string $subjectRef): array;

    /** @return list<SubscriptionLine> */
    public function subscriptionsFor(string $subjectRef): array;

    /** @throws BillingPortalException REASON_UNSUPPORTED when the provider has no quantities. */
    public function changeQuantity(string $actor, string $subscriptionRef, int $quantity): void;

    /** @param string $proration One of the PRORATION_* constants. */
    public function changePlan(
        string $actor,
        string $subscriptionRef,
        string $priceRef,
        string $proration = self::PRORATION_IMMEDIATE,
        bool $invoice = true,
        ?string $idempotencyKey = null,
    ): void;

    /**
     * A short-lived link to the provider's own self-service page, where the
     * customer updates their card, downloads invoices or cancels.
     *
     * @throws BillingPortalException REASON_REFUSED when the subject has no customer yet.
     */
    public function customerPortalUrl(string $subjectRef, string $returnUrl): string;

    /**
     * Refund one receipt, in full or in part. Only an operator reaches this.
     * Some providers review a refund before it happens; the receipt shows the
     * outcome later.
     *
     * @param string   $receiptRef  {@see Receipt::$reference}.
     * @param int|null $amountMinor Null refunds what is left.
     *
     * @throws BillingPortalException REASON_UNSUPPORTED when the provider cannot.
     */
    public function refund(string $actor, string $subjectRef, string $receiptRef, ?int $amountMinor = null): void;

    /**
     * Verify a provider notification over its RAW bytes and say which subject
     * it is about. Null for an authentic notification the host has no reason
     * to act on.
     *
     * Verification is the first thing this does and cannot be skipped: an
     * unverifiable payload throws, it never returns.
     *
     * @param array<string, string> $headers As received; names in any case.
     *
     * @throws BillingPortalException REASON_UNVERIFIED for a bad or missing signature.
     */
    public function verifyNotification(string $rawBody, array $headers): ?BillingNotice;
}
