<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * One line of an adjustment, count or transfer (SDK 1.128, WHIT-1233), in the
 * item's base unit. Which quantities are set depends on the kind:
 * an adjustment has `change` (signed); a transfer `quantity` and, once
 * received, `received`; a count `counted`, `expected` and `difference` once
 * the line is counted (expected is null in a blind count for the people
 * counting).
 */
final class OperationLine
{
    public function __construct(
        public readonly int $id,
        public readonly int $variantId,
        public readonly int $itemId,
        public readonly string $itemName,
        public readonly string $sku,
        public readonly string $unit,
        public readonly ?Quantity $change,
        public readonly ?Quantity $quantity,
        public readonly ?Quantity $received,
        public readonly ?Quantity $counted,
        public readonly ?Quantity $expected,
        public readonly string $reasonCode,
        public readonly string $note,
        public readonly ?int $valueMinor,
        public readonly ?string $currency,
    ) {
    }

    /** @param array<string, mixed> $data the API's `InventoryOperationLine` */
    public static function fromArray(array $data): self
    {
        $decimals = is_int($data['decimals'] ?? null) ? $data['decimals'] : 0;
        $q = static fn (string $key): ?Quantity => is_string($data[$key] ?? null) ? Quantity::parse($data[$key], $decimals) : null;

        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            variantId: is_int($data['variant_id'] ?? null) ? $data['variant_id'] : 0,
            itemId: is_int($data['item_id'] ?? null) ? $data['item_id'] : 0,
            itemName: is_string($data['item_name'] ?? null) ? $data['item_name'] : '',
            sku: is_string($data['sku'] ?? null) ? $data['sku'] : '',
            unit: is_string($data['unit'] ?? null) ? $data['unit'] : '',
            change: $q('change'),
            quantity: $q('quantity'),
            received: $q('received'),
            counted: $q('counted'),
            expected: $q('expected'),
            reasonCode: is_string($data['reason_code'] ?? null) ? $data['reason_code'] : '',
            note: is_string($data['note'] ?? null) ? $data['note'] : '',
            valueMinor: is_int($data['value_minor'] ?? null) ? $data['value_minor'] : null,
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : null,
        );
    }
}
