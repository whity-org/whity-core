<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use DateTimeImmutable;

/** The invoices meter before accounting issues invoices: none. */
final class NoInvoicesYet implements InvoiceUsageCounter
{
    public function issuedBetween(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $until): int
    {
        return 0;
    }
}
