<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * One move of a stock posting (SDK 1.159, WHIT-1318, design section 31
 * §6.3): from a place to a place, with what it was worth.
 *
 * `fromRole` and `toRole` are the virtual place's role (`supplier`,
 * `customer`, `loss`, `found`, `production`, `consumed`, `opening`,
 * `transit`), or null for a real place (a warehouse, a shop). `quantity` is in
 * the variant's base steps. `valueMinor` is in minor units of the posting's
 * currency, null when the cost is unknown. `fromOuId` and `toOuId` are the
 * places' branches.
 */
final class StockPostingMove
{
    public function __construct(
        public readonly int $moveId,
        public readonly int $variantId,
        public readonly int $itemId,
        public readonly ?int $lotId,
        public readonly int $fromLocationId,
        public readonly int $toLocationId,
        public readonly ?string $fromRole,
        public readonly ?string $toRole,
        public readonly ?int $fromOuId,
        public readonly ?int $toOuId,
        public readonly int $quantity,
        public readonly ?int $valueMinor,
        public readonly ?int $reversesMoveId = null,
    ) {
    }

    /** Into stock from outside it: a receipt, opening stock, found stock, a return. */
    public function isIn(): bool
    {
        return $this->fromRole !== null && $this->toRole === null;
    }

    /** Out of stock: a delivery, waste, use in a service. */
    public function isOut(): bool
    {
        return $this->fromRole === null && $this->toRole !== null;
    }
}
