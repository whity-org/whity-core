<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * The trial balance for a date range (SDK 1.131, WHIT-1318, register AC.23):
 * every account with an opening balance or lines, in the base currency's
 * minor units. `balanced` is true when debits equal credits, as they always
 * should.
 */
final class TrialBalance
{
    /** @param list<TrialBalanceRow> $rows */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $currency,
        public readonly array $rows,
        public readonly int $debitMinor,
        public readonly int $creditMinor,
        public readonly bool $balanced,
    ) {
    }

    /** @param array<string, mixed> $data the API's `AccountingTrialBalance` */
    public static function fromArray(array $data): self
    {
        $rows = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            if (is_array($row)) {
                $rows[] = TrialBalanceRow::fromArray($row);
            }
        }
        $totals = is_array($data['totals'] ?? null) ? $data['totals'] : [];

        return new self(
            from: is_string($data['from'] ?? null) ? $data['from'] : '',
            to: is_string($data['to'] ?? null) ? $data['to'] : '',
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : '',
            rows: $rows,
            debitMinor: is_int($totals['debit_minor'] ?? null) ? $totals['debit_minor'] : 0,
            creditMinor: is_int($totals['credit_minor'] ?? null) ? $totals['credit_minor'] : 0,
            balanced: ($data['balanced'] ?? false) === true,
        );
    }
}
