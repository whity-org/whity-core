<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * One item of a bulk issue (SDK 1.83), by its index in the request.
 *
 * - `pending`: not reached yet.
 * - `issued`: a new document, `documentId`.
 * - `replayed`: its origin key already named `documentId`; nothing new.
 * - `refused`: a check refused it before anything was written. `reason` is one
 *   of {@see DocumentNotIssuedException}'s reasons; `message` says why.
 * - `failed`: something broke while issuing. `message` says what.
 * - `cancelled`: the batch was cancelled before it was reached.
 */
final class BulkIssueItemResult
{
    public const PENDING = 'pending';
    public const ISSUED = 'issued';
    public const REPLAYED = 'replayed';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly int $index,
        public readonly string $status,
        public readonly ?string $originKey = null,
        public readonly ?int $documentId = null,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
    ) {
    }
}
