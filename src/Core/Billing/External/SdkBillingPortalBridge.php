<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use Whity\Sdk\Billing\BillingPortal as SdkBillingPortal;
use Whity\Sdk\Billing\BillingPortalException as SdkBillingPortalException;
use Whity\Sdk\Billing\Receipt as SdkReceipt;
use Whity\Sdk\Billing\SubscriptionLine as SdkSubscriptionLine;

/**
 * Core's {@see BillingPortal}, answered by a plugin's SDK portal (WHIT-1010).
 *
 * Translation only. Every SDK value becomes the core value the billing handler
 * and {@see AccessRecorder} already read, and every SDK failure becomes a core
 * {@see BillingPortalException} with the same reason, so nothing above this
 * line can tell a plugin from the hosted service.
 *
 * ANY OTHER THROWABLE BECOMES "unreadable". A plugin's bug must not surface as
 * a 500 carrying its message, which could name a provider endpoint or a key
 * prefix; the reason is what the handler answers from.
 */
final class SdkBillingPortalBridge implements BillingPortal
{
    public function __construct(private readonly SdkBillingPortal $portal)
    {
    }

    public function sdk(): SdkBillingPortal
    {
        return $this->portal;
    }

    public function isConfigured(): bool
    {
        try {
            return $this->portal->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    public function startCheckout(
        BillingActor $actor,
        string $subjectRef,
        string $priceRef,
        string $returnUrl,
        ?string $cancelUrl = null,
        array $payer = [],
    ): CheckoutHandoff {
        $handoff = $this->call(fn () => $this->portal->startCheckout(
            $actor->value,
            $subjectRef,
            $priceRef,
            $returnUrl,
            $cancelUrl,
            $payer,
        ));

        return new CheckoutHandoff($handoff->url, $handoff->reference);
    }

    public function accessFor(string $subjectRef): AccessSnapshot
    {
        $snapshot = $this->call(fn () => $this->portal->accessFor($subjectRef));

        return new AccessSnapshot(
            $subjectRef,
            $snapshot->hasAccess,
            $snapshot->planCode,
            $snapshot->status,
            $snapshot->accessUntil,
            $snapshot->cancelAtPeriodEnd,
            $snapshot->subscriptionRef,
            $snapshot->planCodeAtPeriodEnd,
            // The plugin IS the billing service: a tenant it bills has no
            // local invoice, exactly like one billed by the hosted service.
            $snapshot->subscriptionRef !== null,
        );
    }

    public function checkoutStatus(string $reference): string
    {
        return $this->call(fn () => $this->portal->checkoutStatus($reference));
    }

    public function receiptsFor(string $subjectRef): array
    {
        $receipts = $this->call(fn () => $this->portal->receiptsFor($subjectRef));

        return array_map(static fn (SdkReceipt $r): Receipt => new Receipt(
            $r->number,
            $r->status,
            $r->totalMinor,
            $r->currency,
            $r->paidAt,
            $r->issuedAt,
            $r->totalMinor,
            0,
            $r->status === 'open' ? 0 : $r->totalMinor,
            $r->refundedAt,
            $r->reference,
        ), $receipts);
    }

    public function subscriptionsFor(string $subjectRef): array
    {
        $lines = $this->call(fn () => $this->portal->subscriptionsFor($subjectRef));

        return array_map(static fn (SdkSubscriptionLine $l): SubscriptionLine => new SubscriptionLine(
            $l->id,
            $l->priceRef,
            $l->status,
            $l->grantsAccess,
            $l->quantity,
        ), $lines);
    }

    public function changeQuantity(BillingActor $actor, string $subscriptionRef, int $quantity): void
    {
        $this->call(function () use ($actor, $subscriptionRef, $quantity): null {
            $this->portal->changeQuantity($actor->value, $subscriptionRef, $quantity);

            return null;
        });
    }

    public function changePlan(
        BillingActor $actor,
        string $subscriptionRef,
        string $priceRef,
        string $proration = self::PRORATION_IMMEDIATE,
        bool $invoice = true,
        ?string $idempotencyKey = null,
    ): void {
        $this->call(function () use ($actor, $subscriptionRef, $priceRef, $proration, $invoice, $idempotencyKey): null {
            $this->portal->changePlan($actor->value, $subscriptionRef, $priceRef, $proration, $invoice, $idempotencyKey);

            return null;
        });
    }

    /**
     * Run one SDK call, translating every failure into a core exception.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    public function call(callable $call): mixed
    {
        try {
            return $call();
        } catch (SdkBillingPortalException $e) {
            throw self::translate($e);
        } catch (BillingPortalException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw BillingPortalException::unreadable('the billing plugin failed: ' . $e::class);
        }
    }

    public static function translate(SdkBillingPortalException $e): BillingPortalException
    {
        return match ($e->reason) {
            SdkBillingPortalException::REASON_UNREACHABLE => BillingPortalException::unreachable($e->getMessage()),
            SdkBillingPortalException::REASON_RATE_LIMITED => BillingPortalException::rateLimited($e->getMessage()),
            SdkBillingPortalException::REASON_NOT_CONFIGURED => BillingPortalException::notConfigured(),
            SdkBillingPortalException::REASON_UNSUPPORTED => BillingPortalException::unsupported($e->getMessage()),
            SdkBillingPortalException::REASON_UNVERIFIED => BillingPortalException::unverified($e->getMessage()),
            SdkBillingPortalException::REASON_REFUSED => BillingPortalException::refused($e->getMessage()),
            default => BillingPortalException::unreadable($e->getMessage()),
        };
    }
}
