<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * An adjustment, count or transfer (SDK 1.128, WHIT-1233).
 *
 * `status` is `draft`, `waiting_approval`, `in_transit` (a transfer sent and
 * not yet received), `done` or `cancelled`. Once done, `batchKey` names the
 * moves it posted on the ledger, which {@see Stock::reverse()} undoes. An
 * adjustment and a count are at `locationId`; a transfer goes from
 * `fromLocationId` to `toLocationId`. `lines` is empty when the operation came
 * from {@see Operations::list()}.
 */
final class Operation
{
    public const ADJUSTMENT = 'adjustment';
    public const COUNT = 'count';
    public const TRANSFER = 'transfer';

    /** @param list<OperationLine> $lines */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $number,
        public readonly string $status,
        public readonly ?int $locationId,
        public readonly ?int $fromLocationId,
        public readonly ?int $toLocationId,
        public readonly string $reasonCode,
        public readonly string $note,
        public readonly bool $isBlind,
        public readonly ?int $valueMinor,
        public readonly ?string $currency,
        public readonly ?string $batchKey,
        public readonly ?string $sourcePlugin,
        public readonly ?int $createdByProfileId,
        public readonly ?string $doneAt,
        public readonly array $lines,
    ) {
    }

    /** @param array<string, mixed> $data the API's `InventoryOperation` */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;
        $str = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        return new self(
            id: $int('id') ?? 0,
            kind: $str('kind') ?? '',
            number: $str('number') ?? '',
            status: $str('status') ?? '',
            locationId: $int('location_id'),
            fromLocationId: $int('from_location_id'),
            toLocationId: $int('to_location_id'),
            reasonCode: $str('reason_code') ?? '',
            note: $str('note') ?? '',
            isBlind: ($data['is_blind'] ?? false) === true,
            valueMinor: $int('value_minor'),
            currency: $str('currency'),
            batchKey: $str('batch_key'),
            sourcePlugin: $str('source_plugin'),
            createdByProfileId: $int('created_by_profile_id'),
            doneAt: $str('done_at'),
            lines: array_values(array_map(
                static fn (mixed $line): OperationLine => OperationLine::fromArray(is_array($line) ? $line : []),
                $lines
            )),
        );
    }
}
