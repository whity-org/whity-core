<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use DateTimeImmutable;
use Whity\Core\Usage\InvoiceUsageCounter;

/**
 * The invoices meter (`invoices.per_month`, BM.3, AC.29) reads accounting's
 * issued sales invoices through this (WHIT-1318 A2a). Drafts, cancelled
 * drafts and credit notes don't count.
 */
final class InvoiceCounter implements InvoiceUsageCounter
{
    public function __construct(private readonly Invoices $invoices)
    {
    }

    public function issuedBetween(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $until): int
    {
        return $this->invoices->issuedBetween($tenantId, $from, $until);
    }
}
