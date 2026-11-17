<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * A subject that acts when its route finishes not approved (merge map A4): a
 * customer's join request is turned away. Called inside the act's transaction,
 * once nothing is left open on the route and its last verdict is a rejection.
 * Throwing {@see RoutingRejectedException} refuses the act.
 */
interface RouteSubjectRejectedInterface
{
    public function rejected(int $tenantId, int $subjectId, int $routeId): void;
}
