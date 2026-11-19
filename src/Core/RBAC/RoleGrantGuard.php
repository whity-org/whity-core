<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\Auth\RoleChecker;
use Whity\Core\Tenant\WorkspaceOwners;
use Whity\Core\Response;

/**
 * The one rule every grant path follows: a caller may only grant what they
 * already hold, at the scope they are granting at.
 *
 * "Grant" covers every way authority reaches somebody — giving a person a role,
 * attaching a role to an OU, granting a role at one resource, naming the role a
 * new sign-up lands in, and putting permissions into a role. Without this rule
 * any of those permissions is a way to become admin: `users:write` sets your
 * own role to admin, `roles:manage` adds every permission to a role you hold,
 * `ous:assign` attaches admin to your own OU.
 *
 * Delegations already followed it ({@see \Whity\Core\Delegation\DelegationService::delegate()}),
 * and so did the bulk role change ({@see \Whity\Api\UsersBulkApiHandler::changeRole()}).
 * This is the same rule written once for the rest.
 *
 * WHAT COUNTS AS HELD. The caller's effective permissions in the tenant they
 * act in: their roles, their OU's roles, and live delegations. For a grant at
 * one resource, what they hold AT that resource, which can only be wider.
 *
 * OWNER-ONLY PERMISSIONS ARE NOT COMPARED ({@see WorkspaceOwners::OWNER_ONLY_PERMISSIONS}).
 * They are dropped at resolution for anybody who is not an owner, whatever
 * role they come from, so granting them to a non-owner gives nothing; and an
 * admin who is not an owner must still be able to make somebody else admin,
 * which carries them.
 *
 * Callers check that a role is visible to the tenant FIRST, so somebody else's
 * private role reads as "not found" and never as "has permissions you do not
 * hold".
 */
final class RoleGrantGuard
{
    /** The machine reason on every refusal, shared with the bulk role change. */
    public const REASON = 'exceeds_your_access';

    public function __construct(private readonly RoleChecker $roleChecker)
    {
    }

    /**
     * The permissions in `$permissions` the caller does not hold. Empty means
     * the grant is allowed.
     *
     * @param list<string> $permissions `resource:action` slugs to grant.
     * @return list<string> The ones the caller lacks, sorted.
     */
    public function notHeld(
        int $actorProfileId,
        int $tenantId,
        array $permissions,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): array {
        if ($permissions === []) {
            return [];
        }

        $held = array_fill_keys(
            $this->roleChecker->getEffectivePermissionsForProfile($actorProfileId, $tenantId, $resourceType, $resourceId),
            true
        );

        $missing = [];
        foreach ($permissions as $permission) {
            if (isset($held[$permission]) || in_array($permission, WorkspaceOwners::OWNER_ONLY_PERMISSIONS, true)) {
                continue;
            }
            $missing[$permission] = true;
        }

        $missing = array_keys($missing);
        sort($missing);

        return $missing;
    }

    /**
     * The permissions of role `$roleId` (with everything it inherits) that the
     * caller does not hold. Empty means the caller may grant the role.
     *
     * @return list<string>
     */
    public function roleNotHeld(
        int $actorProfileId,
        int $tenantId,
        int $roleId,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): array {
        return $this->notHeld(
            $actorProfileId,
            $tenantId,
            $this->roleChecker->getEffectivePermissionsForRole($roleId, $tenantId),
            $resourceType,
            $resourceId
        );
    }

    /**
     * The 403 every grant path answers with. Names the permissions, which the
     * caller can already see in the permission catalogue, so they know what to
     * ask for.
     *
     * @param list<string> $missing
     */
    public static function refusal(array $missing, string $message = 'This role has permissions you do not hold'): Response
    {
        return Response::error($message, 403, [
            'reason' => self::REASON,
            'permissions' => $missing,
        ]);
    }
}
