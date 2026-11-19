<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * Stock now for one variant in one place (and lot), in the item's base unit
 * (SDK 1.127, WHIT-1232). Available is on hand minus reserved. On hand can be
 * below zero where the workspace allows it (register IV.8).
 */
final class StockLevel
{
    public function __construct(
        public readonly int $variantId,
        public readonly int $locationId,
        public readonly ?int $lotId,
        public readonly Quantity $onHand,
        public readonly Quantity $reserved,
        public readonly string $unit,
    ) {
    }

    public function available(): Quantity
    {
        return new Quantity($this->onHand->steps - $this->reserved->steps, $this->onHand->decimals);
    }

    /** @param array<string, mixed> $data the API's `InventoryLevel` */
    public static function fromArray(array $data): self
    {
        $decimals = is_int($data['decimals'] ?? null) ? $data['decimals'] : 0;

        return new self(
            variantId: is_int($data['variant_id'] ?? null) ? $data['variant_id'] : 0,
            locationId: is_int($data['location_id'] ?? null) ? $data['location_id'] : 0,
            lotId: is_int($data['lot_id'] ?? null) ? $data['lot_id'] : null,
            onHand: Quantity::parse(is_string($data['on_hand'] ?? null) ? $data['on_hand'] : '0', $decimals),
            reserved: Quantity::parse(is_string($data['reserved'] ?? null) ? $data['reserved'] : '0', $decimals),
            unit: is_string($data['unit'] ?? null) ? $data['unit'] : '',
        );
    }
}
