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
 *     $workspaces = $people->workspacesFor($call, 'billing:view');
 *
 * The person is the call's {@see ServiceCall::$onBehalfOf}: the `sub_on_behalf`
 * the issuer's own client put in its signed token. A plugin cannot name a
 * different person, and a call acting for nobody gets an empty list. The person
 * is looked up through the global sign-in link of the issuer that signed the
 * call, and never any other issuer.
 *
 * Only ACTIVE memberships are returned, never the system workspace, and only
 * workspaces where the linked person holds `$permission`. An unlinked subject
 * gets an empty list, the same answer as a person with no such workspace, so a
 * caller cannot use it to tell whether somebody has an account here.
 */
interface LinkedPeople
{
    /** @return list<LinkedWorkspace> In workspace id order. */
    public function workspacesFor(ServiceCall $call, string $permission): array;
}
