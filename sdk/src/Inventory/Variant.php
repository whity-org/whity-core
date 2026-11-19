<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * One stock-keeping unit of an item (SDK 1.126, WHIT-1232, register IV.4).
 * Stock, moves and order lines point at the variant. An item without sizes or
 * colours has exactly one, with empty `options`.
 */
final class Variant
{
    /**
     * @param array<string, string> $options such as `['colour' => 'red', 'size' => 'M']`
     * @param list<array{code: string, unit: ?string}> $barcodes
     */
    public function __construct(
        public readonly int $id,
        public readonly int $itemId,
        public readonly string $sku,
        public readonly array $options = [],
        public readonly array $barcodes = [],
        public readonly ?int $salePriceMinor = null,
        public readonly ?int $purchasePriceMinor = null,
        public readonly bool $archived = false,
    ) {
    }

    /** @param array<string, mixed> $data the API's `InventoryVariant` */
    public static function fromArray(array $data): self
    {
        $barcodes = [];
        foreach (is_array($data['barcodes'] ?? null) ? $data['barcodes'] : [] as $barcode) {
            if (is_array($barcode) && is_string($barcode['code'] ?? null)) {
                $barcodes[] = ['code' => $barcode['code'], 'unit' => is_string($barcode['unit'] ?? null) ? $barcode['unit'] : null];
            }
        }

        return new self(
            id: (int) ($data['id'] ?? 0),
            itemId: (int) ($data['item_id'] ?? 0),
            sku: (string) ($data['sku'] ?? ''),
            options: Item::stringMap($data['options'] ?? []),
            barcodes: $barcodes,
            salePriceMinor: is_int($data['sale_price_minor'] ?? null) ? $data['sale_price_minor'] : null,
            purchasePriceMinor: is_int($data['purchase_price_minor'] ?? null) ? $data['purchase_price_minor'] : null,
            archived: (bool) ($data['archived'] ?? false),
        );
    }
}
