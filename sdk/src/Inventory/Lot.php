<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * A batch of one variant, or a serial number (SDK 1.134, WHIT-1235): its code,
 * the dates it was made and expires, and how much of it is in stock now, in
 * the item's base unit. A serial number is a lot that holds exactly one piece.
 */
final class Lot
{
    /** @param array<int, string> $onHandByPlace location id => on hand there, decimal */
    public function __construct(
        public readonly int $id,
        public readonly int $variantId,
        public readonly int $itemId,
        public readonly string $code,
        public readonly bool $isSerial,
        public readonly ?string $madeOn,
        public readonly ?string $expiresOn,
        public readonly ?int $supplierContactId,
        public readonly string $onHand,
        public readonly string $unit,
        public readonly array $onHandByPlace = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $places = [];
        foreach (is_array($data['places'] ?? null) ? $data['places'] : [] as $place) {
            if (is_array($place) && is_int($place['location_id'] ?? null)) {
                $places[$place['location_id']] = (string) ($place['on_hand'] ?? '0');
            }
        }

        return new self(
            id: (int) ($data['id'] ?? 0),
            variantId: (int) ($data['variant_id'] ?? 0),
            itemId: (int) ($data['item_id'] ?? 0),
            code: (string) ($data['code'] ?? ''),
            isSerial: ($data['is_serial'] ?? false) === true,
            madeOn: is_string($data['made_on'] ?? null) ? $data['made_on'] : null,
            expiresOn: is_string($data['expires_on'] ?? null) ? $data['expires_on'] : null,
            supplierContactId: is_int($data['supplier_contact_id'] ?? null) ? $data['supplier_contact_id'] : null,
            onHand: (string) ($data['on_hand'] ?? '0'),
            unit: (string) ($data['unit'] ?? ''),
            onHandByPlace: $places,
        );
    }
}
