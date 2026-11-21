<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * A journal entry to post, in the workspace the call runs in (SDK 1.132,
 * WHIT-1318).
 *
 * `journal` is a journal's code (`GEN`, or one your plugin added). `date` is
 * the accounting date, `YYYY-MM-DD`, in an open month. The lines' debits must
 * equal their credits.
 *
 * `sourceType` says what caused it, in your plugin's words: the host prefixes
 * it with your plugin's name (`payroll_run` from `hr` is stored as
 * `hr.payroll_run`), so no plugin posts as core or as another. `sourceId` is
 * your own record.
 *
 * `idempotencyKey`, when set, makes the entry post once: posting the same key
 * again returns the first entry and changes nothing.
 *
 * @see Ledger::post()
 */
final class EntryRequest
{
    /** @param list<EntryRequestLine> $lines */
    public function __construct(
        public readonly int $actingProfileId,
        public readonly string $journal,
        public readonly string $date,
        public readonly array $lines,
        public readonly string $sourceType,
        public readonly ?int $sourceId = null,
        public readonly string $memo = '',
        public readonly ?string $idempotencyKey = null,
    ) {
    }
}
