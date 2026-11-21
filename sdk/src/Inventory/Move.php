<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * One row of the stock ledger (SDK 1.127, WHIT-1232, register IV.2): a
 * quantity of a variant going from one place to another. Never changed and
 * never deleted; a correction is another move the other way, with
 * `reversesMoveId` set. `quantity` is in the item's base unit and always
 * positive. `batchKey` groups the moves posted together.
 */
final class Move
{
    public function __construct(
        public readonly int $id,
        public readonly int $variantId,
        public readonly int $fromLocationId,
        public readonly int $toLocationId,
        public readonly Quantity $quantity,
        public readonly string $sourceType,
        public readonly ?int $sourceId,
        public readonly string $batchKey,
        public readonly string $occurredAt,
        public readonly string $postedAt,
        public readonly ?int $postedBy = null,
        public readonly string $reasonCode = '',
        public readonly string $note = '',
        public readonly ?int $reversesMoveId = null,
        public readonly ?int $unitCostMinor = null,
        public readonly ?string $currency = null,
        /** The batch or serial number it moved (SDK 1.134), or null for an item without batches. */
        public readonly ?int $lotId = null,
    ) {
    }

    /** @param array<string, mixed> $data the API's `InventoryMove` */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self(
            id: $int('id') ?? 0,
            variantId: $int('variant_id') ?? 0,
            fromLocationId: $int('from_location_id') ?? 0,
            toLocationId: $int('to_location_id') ?? 0,
            quantity: Quantity::parse($text('quantity') !== '' ? $text('quantity') : '0', $int('decimals') ?? 0),
            sourceType: $text('source_type'),
            sourceId: $int('source_id'),
            batchKey: $text('batch_key'),
            occurredAt: $text('occurred_at'),
            postedAt: $text('posted_at'),
            postedBy: $int('posted_by_profile_id'),
            reasonCode: $text('reason_code'),
            note: $text('note'),
            reversesMoveId: $int('reverses_move_id'),
            unitCostMinor: $int('unit_cost_minor'),
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : null,
            lotId: $int('lot_id'),
        );
    }
}
