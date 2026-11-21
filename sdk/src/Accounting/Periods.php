<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * The months of the books, read-only, for a plugin (SDK 1.131, WHIT-1318,
 * register AC.7). Closing and reopening a month is a person's act on the
 * Money screens or the API, never a plugin's.
 */
interface Periods
{
    /**
     * The month a date falls in.
     *
     * @throws AccountingRejected
     */
    public function periodFor(string $date): Period;

    /**
     * Whether an entry dated `$date` would be accepted: its month is open and
     * it is after the lock date.
     *
     * @throws AccountingRejected
     */
    public function isOpen(string $date): bool;

    /**
     * The date up to which the books are locked, `YYYY-MM-DD`, or null.
     *
     * @throws AccountingRejected
     */
    public function lockDate(): ?string;
}
