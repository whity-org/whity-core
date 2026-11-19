<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

/**
 * Whether an operator approved a core permission your plugin asked for
 * (SDK 1.130, #2256). A host service: resolve it with
 * `\Whity\app(\Whity\Sdk\Contribute\CorePermissionGrants::class)`; null on a
 * host older than 1.130.
 *
 * Read only. Approving is the operator's, never the plugin's; see
 * {@see CorePermissionRequestsInterface}.
 *
 * WHICH WORKSPACE. Pass the workspace the caller is already authorized in (a
 * route handler's `Request::getWorkspaceId()`), never one from a request body.
 */
interface CorePermissionGrants
{
    /**
     * Whether `$permission` is approved for your plugin's `$role` in the
     * workspace, and so granted to that role there.
     */
    public function isGranted(string $pluginName, string $role, string $permission, int $tenantId): bool;

    /**
     * The core permissions your plugin asked for `$role` that are not approved
     * in the workspace yet, sorted. Empty when everything is in place, or when
     * nothing was asked for that role.
     *
     * @return list<string>
     */
    public function missing(string $pluginName, string $role, int $tenantId): array;
}
