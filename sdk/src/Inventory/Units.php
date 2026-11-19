<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * The workspace's units of measure, and exact conversions between them (SDK
 * 1.126, WHIT-1232, register IV.3, IV.5).
 *
 * A host service: `\Whity\app(\Whity\Sdk\Inventory\Units::class)`, null on a
 * host older than 1.126. It works in the workspace the call runs in, like
 * {@see Items}. Every workspace starts with piece (pc), kg, g, l, ml, m, box,
 * pack and bag.
 */
interface Units
{
    /**
     * @return list<Unit>
     * @throws InventoryRejected
     */
    public function list(): array;

    /**
     * Converts a decimal quantity between two unit codes, exactly, rounding
     * once (half away from zero) to the target unit's decimals. Units of one
     * dimension convert through it (g to kg); with `$itemId`, the item's own
     * units count too (a 25 kg bag of that item).
     *
     * @return string a decimal in the target unit, such as "25.000"
     * @throws InventoryRejected when the units don't convert
     */
    public function convert(string $quantity, string $fromUnit, string $toUnit, ?int $itemId = null): string;
}
