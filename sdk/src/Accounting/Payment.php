<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * A payment (SDK 1.156, WHIT-1318, design section 32 §5.4, register AC.13).
 *
 * `direction` is `in` (from a customer) or `transfer` (between the
 * workspace's own accounts); payments out arrive with purchases (A3).
 * `status` is `posted` or `bounced`. `method` is `cash`, `bank_transfer`,
 * `cliq`, `card`, `cheque`, `whity_pay` or `other`. A cheque's
 * `chequeStatus` is `waiting` (on Cheques to collect), `cleared` or
 * `bounced`. Amounts are minor units of `currency`; `unallocatedMinor` is
 * what it hasn't settled yet, the customer's credit.
 */
final class Payment
{
    public function __construct(
        public readonly int $id,
        public readonly string $direction,
        public readonly string $number,
        public readonly ?int $contactId,
        public readonly string $paidOn,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $method,
        public readonly int $accountId,
        public readonly string $status,
        public readonly ?string $chequeStatus,
        public readonly int $feeMinor,
        public readonly int $allocatedMinor,
        public readonly int $unallocatedMinor,
        public readonly ?int $entryId,
        public readonly ?string $externalRef,
    ) {
    }

    /** @param array<string, mixed> $data the API's `AccountingPayment` */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : 0;
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self(
            id: $int('id'),
            direction: $text('direction'),
            number: $text('number'),
            contactId: is_int($data['contact_id'] ?? null) ? $data['contact_id'] : null,
            paidOn: $text('paid_on'),
            amountMinor: $int('amount_minor'),
            currency: $text('currency'),
            method: $text('method'),
            accountId: $int('account_id'),
            status: $text('status'),
            chequeStatus: is_string($data['cheque_status'] ?? null) ? $data['cheque_status'] : null,
            feeMinor: $int('fee_minor'),
            allocatedMinor: $int('allocated_minor'),
            unallocatedMinor: $int('unallocated_minor'),
            entryId: is_int($data['entry_id'] ?? null) ? $data['entry_id'] : null,
            externalRef: is_string($data['external_ref'] ?? null) ? $data['external_ref'] : null,
        );
    }
}
