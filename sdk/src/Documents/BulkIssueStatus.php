<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * Where a bulk issue is (SDK 1.83), from {@see Documents::issueMany()},
 * {@see Documents::batch()} and {@see Documents::cancelBatch()}.
 *
 * `status` is `queued` until a worker picks it up, `running` while items are
 * issued, then `done` when every item has a result, or `cancelled` when it was
 * cancelled first (the items already issued stay issued). `done` does not mean
 * every item issued: count `refused` and `failed`, or read each item's result.
 * `resubmitted` is true when the batch key already named this batch, so
 * nothing new was started.
 */
final class BulkIssueStatus
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';

    /** @param list<BulkIssueItemResult> $items */
    public function __construct(
        public readonly int $batchId,
        public readonly string $batchKey,
        public readonly string $status,
        public readonly int $total,
        public readonly int $issued,
        public readonly int $replayed,
        public readonly int $refused,
        public readonly int $failed,
        public readonly array $items = [],
        public readonly bool $resubmitted = false,
        public readonly int $cancelled = 0,
    ) {
    }

    /** Items with a result so far. */
    public function finished(): int
    {
        return $this->issued + $this->replayed + $this->refused + $this->failed + $this->cancelled;
    }

    /** Whether nothing more will happen: done or cancelled. */
    public function isDone(): bool
    {
        return $this->status === self::DONE || $this->status === self::CANCELLED;
    }
}
