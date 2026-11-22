<?php

declare(strict_types=1);

namespace Whity\Sdk\Sales;

/**
 * An accepted quote, read-only, as a conversion target sees it (SDK 1.132,
 * WHIT-1321). Amounts are minor units of `currency` (CU.1-CU.4).
 */
final class QuoteSnapshot
{
    /** @param list<QuoteSnapshotLine> $lines */
    public function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly int $revision,
        public readonly int $contactId,
        public readonly string $contactName,
        public readonly string $currency,
        public readonly bool $pricesIncludeTax,
        public readonly int $subtotalMinor,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
        public readonly string $validUntil,
        public readonly string $notes,
        public readonly string $terms,
        public readonly array $lines,
    ) {
    }
}
