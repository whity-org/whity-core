<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * One line of a {@see MoveRequest} (SDK 1.126, WHIT-1232): this much of a
 * variant, from one place to another. `quantity` is a positive decimal in
 * `unit` (a unit code such as `kg` or `box`, or null for the item's base
 * unit); the host converts it exactly to the base unit.
 */
final class MoveLine
{
    public function __construct(
        public readonly int $variantId,
        public readonly string $quantity,
        public readonly int $fromLocationId,
        public readonly int $toLocationId,
        public readonly ?string $unit = null,
        public readonly string $note = '',
        public readonly ?int $unitCostMinor = null,
        public readonly ?string $currency = null,
    ) {
    }
}
