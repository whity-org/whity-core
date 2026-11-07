<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * What the routing engine asks of whoever makes final documents (WHIT-921,
 * #1725). {@see RouteFinaliser} is core's; the engine depends on this so it can
 * run, and be tested, without the render service, storage and issuing stack
 * behind it.
 */
interface RouteFinaliseService
{
    /**
     * Whether final documents can be made in this workspace on this install: a
     * render service is configured and rendering is on. A route with a finalise
     * step cannot be designed or issued without it.
     */
    public function isAvailable(int $tenantId): bool;

    /**
     * Make the route's final document now, if it has reached its finalise step
     * and nothing on it is still open. Never throws for a render or storage
     * failure: the step goes on waiting and a retry is scheduled.
     *
     * @param bool $scheduleRetry false when the caller is the queued retry
     *        itself, which the queue retries on its own.
     * @return string One of {@see RouteFinaliser}'s outcomes.
     */
    public function finalise(int $tenantId, int $routeId, bool $scheduleRetry = true): string;

    /**
     * Leave it to the queue: for a caller still inside a transaction, so the
     * request to make it commits or rolls back with that transaction and no PDF
     * is ever made for an act that was rolled back.
     */
    public function defer(int $tenantId, int $routeId): void;

    /**
     * Another try at a failed or waiting final document, asked for by a person
     * who may manage the route. False when there is nothing to try again.
     */
    public function retry(int $tenantId, int $routeId): bool;
}
