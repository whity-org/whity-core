<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * One line of an invoice or credit note (SDK 1.155, WHIT-1318).
 *
 * `quantity` is in steps of `quantityDecimals` (2.5 with one decimal is 25).
 * Amounts are minor units of the invoice's currency: `netMinor` before tax,
 * `taxMinor` at the line's tax code, `totalMinor` the two together.
 */
final class InvoiceLine
{
    public function __construct(
        public readonly int $id,
        public readonly int $lineNo,
        public readonly ?int $variantId,
        public readonly string $description,
        public readonly int $quantity,
        public readonly int $quantityDecimals,
        public readonly int $unitPriceMinor,
        public readonly int $discountBp,
        public readonly int $netMinor,
        public readonly int $taxCodeId,
        public readonly string $taxCode,
        public readonly int $taxRateBp,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
        public readonly int $accountId,
        public readonly ?int $creditsLineId = null,
    ) {
    }

    /** @param array<string, mixed> $data the API's `AccountingInvoiceLine` */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : 0;

        return new self(
            id: $int('id'),
            lineNo: $int('line_no'),
            variantId: is_int($data['variant_id'] ?? null) ? $data['variant_id'] : null,
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            quantity: $int('quantity'),
            quantityDecimals: $int('quantity_decimals'),
            unitPriceMinor: $int('unit_price_minor'),
            discountBp: $int('discount_bp'),
            netMinor: $int('net_minor'),
            taxCodeId: $int('tax_code_id'),
            taxCode: is_string($data['tax_code'] ?? null) ? $data['tax_code'] : '',
            taxRateBp: $int('tax_rate_bp'),
            taxMinor: $int('tax_minor'),
            totalMinor: $int('total_minor'),
            accountId: $int('account_id'),
            creditsLineId: is_int($data['credits_line_id'] ?? null) ? $data['credits_line_id'] : null,
        );
    }
}
