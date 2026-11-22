<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * One tax code (SDK 1.155, WHIT-1318, register AC.17–AC.20).
 *
 * `direction` is `output` (on sales) or `input` (on purchases). `rateBp` is
 * the rate in basis points (1600 = 16%). `treatment` is `standard`,
 * `reduced`, `zero`, `exempt`, `out_of_scope` or `reverse_charge`. An item's
 * tax category (`standard`, `reduced_8`, `zero`, `exempt`...) picks the code
 * through `category`. Rates change by date: a new code with `validFrom`.
 */
final class TaxCode
{
    /** @param array<string, string> $names every language, `{en, ar}` */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly array $names,
        public readonly string $direction,
        public readonly int $rateBp,
        public readonly string $treatment,
        public readonly string $category,
        public readonly ?int $accountId,
        public readonly string $einvoiceCategory,
        public readonly ?string $validFrom,
        public readonly ?string $validTo,
        public readonly bool $archived,
    ) {
    }

    /** @param array<string, mixed> $data the API's `AccountingTaxCode` */
    public static function fromArray(array $data): self
    {
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $names = [];
        foreach (is_array($data['names'] ?? null) ? $data['names'] : [] as $lang => $value) {
            if (is_string($lang) && is_string($value)) {
                $names[$lang] = $value;
            }
        }

        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            code: $text('code'),
            name: $text('name'),
            names: $names,
            direction: $text('direction'),
            rateBp: is_int($data['rate_bp'] ?? null) ? $data['rate_bp'] : 0,
            treatment: $text('treatment'),
            category: $text('category'),
            accountId: is_int($data['account_id'] ?? null) ? $data['account_id'] : null,
            einvoiceCategory: $text('einvoice_category'),
            validFrom: is_string($data['valid_from'] ?? null) ? $data['valid_from'] : null,
            validTo: is_string($data['valid_to'] ?? null) ? $data['valid_to'] : null,
            archived: ($data['archived'] ?? false) === true,
        );
    }
}
