<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * One line of an order (SDK 1.129, WHIT-1234): what is ordered and received,
 * in the unit it is bought in, at that unit's price in the order's currency.
 */
final class OrderLine
{
    public function __construct(
        public readonly int $id,
        public readonly int $variantId,
        public readonly int $itemId,
        public readonly string $itemName,
        public readonly string $unit,
        public readonly Quantity $quantity,
        public readonly Quantity $received,
        public readonly int $priceMinor,
        public readonly int $lineTotalMinor,
        public readonly bool $isClosed,
    ) {
    }

    /** @param array<string, mixed> $data the API's `InventoryPurchaseOrderLine` */
    public static function fromArray(array $data): self
    {
        $quantity = is_string($data['quantity'] ?? null) ? $data['quantity'] : '0';
        $received = is_string($data['received'] ?? null) ? $data['received'] : '0';
        $decimals = max(self::decimals($quantity), self::decimals($received));

        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            variantId: is_int($data['variant_id'] ?? null) ? $data['variant_id'] : 0,
            itemId: is_int($data['item_id'] ?? null) ? $data['item_id'] : 0,
            itemName: is_string($data['item_name'] ?? null) ? $data['item_name'] : '',
            unit: is_string($data['unit'] ?? null) ? $data['unit'] : '',
            quantity: Quantity::parse($quantity, $decimals),
            received: Quantity::parse($received, $decimals),
            priceMinor: is_int($data['price_minor'] ?? null) ? $data['price_minor'] : 0,
            lineTotalMinor: is_int($data['line_total_minor'] ?? null) ? $data['line_total_minor'] : 0,
            isClosed: ($data['is_closed'] ?? false) === true,
        );
    }

    private static function decimals(string $decimal): int
    {
        $dot = strpos($decimal, '.');

        return $dot === false ? 0 : strlen($decimal) - $dot - 1;
    }
}
