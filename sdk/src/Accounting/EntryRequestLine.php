<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * One line of an entry to post (SDK 1.154, WHIT-1318): an account by id, and
 * a debit or a credit in the base currency's minor units (one of them above
 * 0, the other 0). `contactId` ties it to a customer or supplier, `ouId` to a
 * branch.
 */
final class EntryRequestLine
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $debitMinor = 0,
        public readonly int $creditMinor = 0,
        public readonly ?int $contactId = null,
        public readonly ?int $ouId = null,
        public readonly string $description = '',
    ) {
    }

    public static function debit(int $accountId, int $minor, string $description = ''): self
    {
        return new self($accountId, $minor, 0, null, null, $description);
    }

    public static function credit(int $accountId, int $minor, string $description = ''): self
    {
        return new self($accountId, 0, $minor, null, null, $description);
    }
}
