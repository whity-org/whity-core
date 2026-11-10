<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\Sdk\Rbac\PermissionResolver;
use Whity\Sdk\Rbac\ResourceReach;
use Whity\Sdk\Rbac\ResourceScope;

/**
 * The host's {@see ResourceScope}: a plugin's narrowing check for its own
 * resource types (#1074).
 *
 * Standing comes from {@see ResourceRoleAssignmentRepository::resourceIdsForProfile()}
 * (profile-addressed grants only, any role), the same source
 * {@see \Whity\Core\Ou\OuReachResolver} uses for units. Each id is then ANDed
 * with the permission check through the SAME {@see PermissionResolver} plugins
 * and the RBAC middleware use, so the reach can never contain a record at which
 * `hasPermission()` would say no. That is what makes it narrow-only.
 *
 * Every failure mode resolves to {@see ResourceReach::none()}: an unknown
 * permission, an unregistered type, or a caller with no active membership (the
 * permission check already fails for them).
 */
final class GrantResourceScope implements ResourceScope
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ResourceRoleAssignmentRepository $grants,
        private readonly ResourceTypeRegistry $resourceTypes,
        private readonly PermissionRegistry $permissionRegistry,
    ) {
    }

    public function reach(
        int $profileId,
        int $tenantId,
        string $permission,
        string $resourceType,
        ?string $unrestrictedPermission = null
    ): ResourceReach {
        if (!$this->permissionRegistry->exists($permission) || !$this->resourceTypes->exists($resourceType)) {
            return ResourceReach::none();
        }

        // Unrestricted needs BOTH permissions tenant-wide. The capability itself
        // is required too, so naming a broad "see everything" permission can
        // never hand out records the caller could not act on.
        if (
            $unrestrictedPermission !== null
            && $this->permissionRegistry->exists($unrestrictedPermission)
            && $this->permissions->hasPermission($profileId, $tenantId, $permission)
            && $this->permissions->hasPermission($profileId, $tenantId, $unrestrictedPermission)
        ) {
            return ResourceReach::unrestricted();
        }

        $ids = [];
        foreach ($this->grants->resourceIdsForProfile($tenantId, $resourceType, $profileId) as $id) {
            if ($this->permissions->hasPermission($profileId, $tenantId, $permission, $resourceType, $id)) {
                $ids[] = $id;
            }
        }

        return ResourceReach::of($ids);
    }
}
