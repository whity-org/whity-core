<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * One line of a posted entry (SDK 1.154, WHIT-1318): an account and a debit
 * or a credit in minor units, never both.
 */
final class EntryLine
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountCode,
        public readonly int $debitMinor,
        public readonly int $creditMinor,
        public readonly ?int $contactId = null,
        public readonly ?int $ouId = null,
        public readonly string $description = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            accountId: $int('account_id') ?? 0,
            accountCode: is_scalar($data['account_code'] ?? null) ? (string) $data['account_code'] : '',
            debitMinor: $int('debit_minor') ?? 0,
            creditMinor: $int('credit_minor') ?? 0,
            contactId: $int('contact_id'),
            ouId: $int('ou_id'),
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
        );
    }
}
