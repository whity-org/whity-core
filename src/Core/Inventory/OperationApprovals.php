<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

/**
 * Sends a stock operation that needs approval on its route (WHIT-1233,
 * register IV.14, IV.15, IV.19). The route's verdict comes back through
 * {@see OperationRouteSubject}.
 */
interface OperationApprovals
{
    /**
     * Issues the route for an adjustment or a count waiting for approval.
     *
     * @param array<string, mixed> $operation as {@see StockOperations::get()} presents it
     * @return int|null the route id, or null when nobody can approve it (the
     *     operation then posts at once, as with no route)
     */
    public function issue(int $tenantId, ?int $actor, array $operation): ?int;
}
