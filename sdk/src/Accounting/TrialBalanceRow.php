<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/** One account on the trial balance (SDK 1.154, WHIT-1318), in minor units. */
final class TrialBalanceRow
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $type,
        public readonly int $openingDebitMinor,
        public readonly int $openingCreditMinor,
        public readonly int $debitMinor,
        public readonly int $creditMinor,
        public readonly int $closingDebitMinor,
        public readonly int $closingCreditMinor,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : 0;
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self(
            $int('account_id'),
            $text('code'),
            $text('name'),
            $text('type'),
            $int('opening_debit_minor'),
            $int('opening_credit_minor'),
            $int('debit_minor'),
            $int('credit_minor'),
            $int('closing_debit_minor'),
            $int('closing_credit_minor'),
        );
    }
}
