<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * An order (SDK 1.129, WHIT-1234): a purchase order now, a sales order later.
 *
 * `status` is `draft`, `waiting_approval`, `approved`, `partly_done`, `done`
 * or `cancelled`. Totals are minor units in `currency` (tax on top of the
 * subtotal). `lines` is empty when the order came from {@see Orders::list()}.
 */
final class Order
{
    public const PURCHASE = 'purchase';

    /** @param list<OrderLine> $lines */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $number,
        public readonly string $status,
        public readonly int $contactId,
        public readonly string $contactName,
        public readonly int $locationId,
        public readonly ?string $expectedOn,
        public readonly string $currency,
        public readonly int $subtotalMinor,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
        public readonly ?string $sourcePlugin,
        public readonly array $lines,
    ) {
    }

    /** @param array<string, mixed> $data the API's `InventoryPurchaseOrder` */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : 0;
        $str = static fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        return new self(
            id: $int('id'),
            kind: $str('kind'),
            number: $str('number'),
            status: $str('status'),
            contactId: $int('contact_id'),
            contactName: $str('contact_name'),
            locationId: $int('location_id'),
            expectedOn: is_string($data['expected_on'] ?? null) ? $data['expected_on'] : null,
            currency: $str('currency'),
            subtotalMinor: $int('subtotal_minor'),
            taxMinor: $int('tax_minor'),
            totalMinor: $int('total_minor'),
            sourcePlugin: is_string($data['source_plugin'] ?? null) ? $data['source_plugin'] : null,
            lines: array_values(array_map(static fn (mixed $l): OrderLine => OrderLine::fromArray(is_array($l) ? $l : []), $lines)),
        );
    }
}
