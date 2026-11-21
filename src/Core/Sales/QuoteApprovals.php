<?php

declare(strict_types=1);

namespace Whity\Core\Sales;

/**
 * Sends a quote on the workspace's quote route (WHIT-1321, QT.4).
 */
interface QuoteApprovals
{
    /**
     * Issues the route for a quote, or answers null when the workspace has no
     * quote route: the quote is then ready at once.
     *
     * @param array{id: int, number: ?string, contact_name: string} $quote
     */
    public function issue(int $tenantId, ?int $actor, array $quote): ?int;
}
