<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * A subject that does something of its own when its route finishes approved
 * (WHIT-1017): a form submission takes its reference then, when its form gives
 * references on approval.
 *
 * Called by {@see DocumentRouter} inside the act's transaction, after the act
 * that finished the route is written and before the final document is made,
 * so the approval and what it earned commit together and the final document
 * can print it. A throw rolls the approval back.
 */
interface RouteSubjectApprovedInterface
{
    public function approved(int $tenantId, int $subjectId, int $routeId): void;
}
