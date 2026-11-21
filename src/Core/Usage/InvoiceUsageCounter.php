<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use DateTimeImmutable;

/**
 * How many invoices a workspace issued in a span of time, for the invoices
 * meter (`invoices.per_month`, BM.3, AC.29).
 *
 * Accounting owns invoices, and accounting's invoices (A2) are not built yet,
 * so the meter reads them through this interface: until accounting binds its
 * own counter, {@see NoInvoicesYet} answers 0. Count what was ISSUED (a
 * number given), not drafts, and never a cancelled draft; a credit note does
 * not take one away.
 */
interface InvoiceUsageCounter
{
    /** Invoices the workspace issued from $from (inclusive) to $until (exclusive). */
    public function issuedBetween(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $until): int;
}
