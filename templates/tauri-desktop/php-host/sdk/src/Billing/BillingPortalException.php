<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

use RuntimeException;

/**
 * Why a {@see BillingPortal} call did not happen (SDK 1.77).
 *
 * The REASON is what the host acts on; the message is for logs only and must
 * not carry keys, card data or a provider's raw response.
 */
final class BillingPortalException extends RuntimeException
{
    /** The provider could not be reached. Worth retrying. */
    public const REASON_UNREACHABLE = 'unreachable';
    /** The provider asked us to slow down. Worth retrying. */
    public const REASON_RATE_LIMITED = 'rate_limited';
    /** The provider answered with something we could not read. */
    public const REASON_UNREADABLE = 'unreadable';
    /** The provider said no (bad request, unknown customer, a currency it does not take). */
    public const REASON_REFUSED = 'refused';
    /** No provider is chosen, or its keys are missing. */
    public const REASON_NOT_CONFIGURED = 'not_configured';
    /** The provider has no such operation (a refund, a quantity). */
    public const REASON_UNSUPPORTED = 'unsupported';
    /** A notification whose signature did not verify. */
    public const REASON_UNVERIFIED = 'unverified';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unreachable(string $detail): self
    {
        return new self(self::REASON_UNREACHABLE, $detail);
    }

    public static function rateLimited(string $detail): self
    {
        return new self(self::REASON_RATE_LIMITED, $detail);
    }

    public static function unreadable(string $detail): self
    {
        return new self(self::REASON_UNREADABLE, $detail);
    }

    public static function refused(string $detail): self
    {
        return new self(self::REASON_REFUSED, $detail);
    }

    public static function notConfigured(string $detail = 'no payment provider is configured'): self
    {
        return new self(self::REASON_NOT_CONFIGURED, $detail);
    }

    public static function unsupported(string $detail): self
    {
        return new self(self::REASON_UNSUPPORTED, $detail);
    }

    public static function unverified(string $detail): self
    {
        return new self(self::REASON_UNVERIFIED, $detail);
    }

    public function isTransient(): bool
    {
        return $this->reason === self::REASON_UNREACHABLE || $this->reason === self::REASON_RATE_LIMITED;
    }
}
