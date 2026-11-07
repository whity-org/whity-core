<?php

declare(strict_types=1);

namespace Whity\Sdk\Identity;

use Whity\Sdk\Http\ServiceCall;

/**
 * Who a subject at the trusted issuer is on this deployment (SDK 1.84).
 *
 * Resolve it from the container:
 *
 *     $people = \Whity\app(\Whity\Sdk\Identity\LinkedPeople::class);
 *     $workspaces = $people->workspacesFor($call, $sub, 'billing:view');
 *
 * The subject is looked up through the global sign-in link of the issuer that
 * signed `$call`, and never any other issuer. A plugin cannot ask about a
 * subject at an issuer the deployment does not trust.
 *
 * Only ACTIVE memberships are returned, never the system workspace, and only
 * workspaces where the linked person holds `$permission`. An unlinked subject
 * gets an empty list, the same answer as a person with no such workspace, so a
 * caller cannot use it to tell whether somebody has an account here.
 */
interface LinkedPeople
{
    /** @return list<LinkedWorkspace> In workspace id order. */
    public function workspacesFor(ServiceCall $call, string $subject, string $permission): array;
}
