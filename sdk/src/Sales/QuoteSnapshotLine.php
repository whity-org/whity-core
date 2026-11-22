<?php

declare(strict_types=1);

namespace Whity\Sdk\Sales;

/**
 * One line of a {@see QuoteSnapshot}. An item line has `variantId` and
 * `unitId`, and `baseQuantity` in the item's base unit (whole steps, IV.3); a
 * free line has neither, only its description. `quantity` is a decimal string
 * in the line's own unit ("2.5").
 */
final class QuoteSnapshotLine
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $variantId,
        public readonly ?int $unitId,
        public readonly string $description,
        public readonly string $quantity,
        public readonly ?string $baseQuantity,
        public readonly int $priceMinor,
        public readonly int $discountBp,
        public readonly string $taxCategory,
        public readonly int $taxBp,
        public readonly int $lineTotalMinor,
    ) {
    }

    public function isItem(): bool
    {
        return $this->variantId !== null;
    }
}
