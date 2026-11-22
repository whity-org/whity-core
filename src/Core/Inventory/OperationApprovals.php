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
     * @return int|null the route id, or null when nobody can approve it. For
     *     an adjustment or a count that is a stop, never a pass: the operation
     *     keeps waiting (WHIT-1301). A purchase order with no route chosen is
     *     confirmed by the person sending it (IV.12).
     */
    public function issue(int $tenantId, ?int $actor, array $operation): ?int;

    /**
     * Tells the people who can unblock it that an operation waits with nobody
     * to approve it: everyone with `inventory:settings` and the workspace's
     * owners (WHIT-1301). Never throws.
     *
     * @param array<string, mixed> $operation as {@see StockOperations::get()} presents it
     */
    public function nobodyCanApprove(int $tenantId, ?int $actor, array $operation): void;
}
