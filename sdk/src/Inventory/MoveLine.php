<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * One line of a {@see MoveRequest} (SDK 1.127, WHIT-1232): this much of a
 * variant, from one place to another. `quantity` is a positive decimal in
 * `unit` (a unit code such as `kg` or `box`, or null for the item's base
 * unit); the host converts it exactly to the base unit.
 *
 * For an item that keeps batches or serial numbers (SDK 1.137, WHIT-1235):
 * stock coming in names its lot by `lotId` or `lotCode` (a new code makes the
 * lot, with `expiresOn` and `madeOn` as `YYYY-MM-DD`; `expiresOn` is needed
 * when the item expires and has no shelf life); stock leaving may name one,
 * or the lots that expire first are taken. An item without batches refuses a
 * lot.
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
        public readonly ?int $lotId = null,
        public readonly ?string $lotCode = null,
        public readonly ?string $expiresOn = null,
        public readonly ?string $madeOn = null,
    ) {
    }
}
