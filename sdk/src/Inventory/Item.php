<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * Something a workspace buys, sells, makes or uses (SDK 1.126, WHIT-1232,
 * register IV.4–IV.6).
 *
 * `name` is the text as it was first written; `names` has it in every
 * language the workspace gave (`['en' => 'Flour, 25 kg bag', 'ar' => 'طحين']`).
 * `kind` is `stocked`, `consumable`, `service` or `made`. `baseUnit` is the
 * code of the unit its stock is counted in. Prices are in the currency's
 * minor units.
 */
final class Item
{
    /**
     * @param array<string, string> $names
     * @param list<Variant> $variants
     * @param array<string, mixed> $fields the workspace's own fields, by key
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $names,
        public readonly string $kind,
        public readonly string $baseUnit,
        public readonly int $baseUnitDecimals,
        public readonly array $variants,
        public readonly string $description = '',
        public readonly ?int $categoryId = null,
        public readonly string $tracking = 'none',
        public readonly bool $expires = false,
        public readonly ?int $salePriceMinor = null,
        public readonly ?int $purchasePriceMinor = null,
        public readonly ?string $currency = null,
        public readonly string $taxCategory = '',
        public readonly array $fields = [],
        public readonly bool $archived = false,
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
    ) {
    }

    /** The one variant of an item without sizes or colours, or the first. */
    public function mainVariant(): ?Variant
    {
        return $this->variants[0] ?? null;
    }

    /** @param array<string, mixed> $data the API's `InventoryItem` */
    public static function fromArray(array $data): self
    {
        $variants = [];
        foreach (is_array($data['variants'] ?? null) ? $data['variants'] : [] as $variant) {
            if (is_array($variant)) {
                $variants[] = Variant::fromArray($variant);
            }
        }
        $unit = is_array($data['base_unit'] ?? null) ? $data['base_unit'] : [];
        $fields = $data['fields'] ?? [];
        if (is_object($fields)) {
            $fields = get_object_vars($fields);
        }
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $int = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            id: (int) ($data['id'] ?? 0),
            name: $text('name'),
            names: self::stringMap($data['names'] ?? []),
            kind: $text('kind') !== '' ? $text('kind') : 'stocked',
            baseUnit: is_string($unit['code'] ?? null) ? $unit['code'] : '',
            baseUnitDecimals: is_int($unit['decimals'] ?? null) ? $unit['decimals'] : 0,
            variants: $variants,
            description: $text('description'),
            categoryId: $int('category_id'),
            tracking: $text('tracking') !== '' ? $text('tracking') : 'none',
            expires: (bool) ($data['expires'] ?? false),
            salePriceMinor: $int('sale_price_minor'),
            purchasePriceMinor: $int('purchase_price_minor'),
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : null,
            taxCategory: $text('tax_category'),
            fields: is_array($fields) ? $fields : [],
            archived: (bool) ($data['archived'] ?? false),
            createdAt: $text('created_at'),
            updatedAt: $text('updated_at'),
        );
    }

    /**
     * @internal
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $text) {
            if (is_string($key) && is_scalar($text)) {
                $out[$key] = (string) $text;
            }
        }

        return $out;
    }
}
