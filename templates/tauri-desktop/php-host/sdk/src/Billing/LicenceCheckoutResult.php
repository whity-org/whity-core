<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/** Where to send the buyer, or why the purchase cannot start (SDK 1.86). */
final class LicenceCheckoutResult
{
    /** No add-on plan or price for this product, plan, period and currency. */
    public const NOT_SOLD = 'not_sold';
    /** This installation already has a live subscription to this product. */
    public const ALREADY_LICENSED = 'already_licensed';
    /** No billing plugin is selling licences, or it has no provider set up. */
    public const NOT_CONFIGURED = 'not_configured';
    /** The provider could not be reached, or failed. Worth trying again. */
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly ?string $url,
        public readonly ?string $checkoutRef,
        public readonly ?string $error,
    ) {
    }

    public static function ok(string $url, string $checkoutRef): self
    {
        return new self($url, $checkoutRef, null);
    }

    /** @param self::NOT_SOLD|self::ALREADY_LICENSED|self::NOT_CONFIGURED|self::UNAVAILABLE $error */
    public static function refused(string $error): self
    {
        return new self(null, null, $error);
    }

    public function isOk(): bool
    {
        return $this->url !== null;
    }
}
