<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * One licence purchase (SDK 1.86).
 *
 * `product` is `plugin.<slug>`; `plan` and `period` pick the host's add-on
 * price `plugin.<slug>.<plan>`. `customerRef` identifies the INSTALLATION (its
 * store client id), never a workspace. `reference` is the store's own licence
 * id: it rides in the provider's metadata and is what {@see LicenceSales::status()}
 * is asked about.
 */
final class LicenceCheckout
{
    public const PRODUCT_PATTERN = '/^plugin\.[a-z0-9][a-z0-9-]{0,62}$/';

    public function __construct(
        public readonly string $product,
        public readonly string $plan,
        public readonly string $period,
        public readonly string $currency,
        public readonly string $customerRef,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly string $reference,
        public readonly string $returnUrl,
    ) {
        if (preg_match(self::PRODUCT_PATTERN, $product) !== 1) {
            throw new \InvalidArgumentException('A licence product is plugin.<slug>.');
        }
        if ($customerRef === '' || $reference === '' || strlen($reference) > 64 || strlen($customerRef) > 128) {
            throw new \InvalidArgumentException('A licence purchase needs a customer reference and a reference of at most 64 characters.');
        }
        if (preg_match('#^https://#', $returnUrl) !== 1) {
            throw new \InvalidArgumentException('The return URL must be https.');
        }
    }
}
