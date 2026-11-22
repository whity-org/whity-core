<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

/**
 * Sends an invoice on the workspace's invoice route before it is issued
 * (WHIT-1318 A2a, design section 32 §5.2, §9.1).
 */
interface InvoiceApprovals
{
    /**
     * Issues the route for an invoice, or answers null when the workspace has
     * no invoice route: the invoice is then issued at once.
     *
     * @param array<string, mixed> $invoice as {@see Invoices::get()}
     */
    public function issue(int $tenantId, ?int $actor, array $invoice): ?int;
}
