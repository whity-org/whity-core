<?php

declare(strict_types=1);

namespace Whity\Sdk\Rbac;

/**
 * Which records of a plugin's resource type a caller may act on (SDK 1.74).
 *
 * Why this is not {@see PermissionResolver}
 * -----------------------------------------
 * Resource-scoped permission checks only ever ADD: a grant at a record widens
 * the caller's authority there and never removes what they hold tenant-wide.
 * So `hasPermission($p, $t, 'acme:records_read', 'acme:record', $anyId)` is
 * true for EVERY id once the caller holds `acme:records_read` tenant-wide. A
 * plugin that filters a list that way gets a check that looks selective and
 * lets everything through.
 *
 * "This person sees these records and not those" is a restriction, and it needs
 * a second check that is ANDed with the permission. This is that check, for a
 * plugin's own types; core uses the same shape for its units.
 *
 * Declaring a scoped type
 * -----------------------
 * No new declaration. Every type the plugin declares through
 * {@see PluginResourceTypesInterface} can be scoped. A person stands at a record
 * when a role is granted at that record to them by name (a
 * `resource_role_assignments` row with their profile id). Grants to everyone at
 * a record (no profile) do not count: they change what people who already
 * reach the record may do, and are not access in themselves. Any role counts.
 *
 * What the host guarantees
 * ------------------------
 * The result can only narrow, never widen:
 *  - it is always a subset of what {@see PermissionResolver::hasPermission()}
 *    allows for the same permission at each record;
 *  - unknown permission, unregistered type, or no active membership in the
 *    tenant: no records;
 *  - unrestricted only when the caller holds BOTH `$permission` and
 *    `$unrestrictedPermission` tenant-wide. Without `$unrestrictedPermission`
 *    the reach is never unrestricted, however much the caller holds.
 *
 * Obtaining an implementation
 * ---------------------------
 *     $scope = \Whity\app(\Whity\Sdk\Rbac\ResourceScope::class);
 *
 * A host that has not wired one fails closed: the lookup throws.
 *
 * READ ONLY. It answers one question and grants nothing.
 */
interface ResourceScope
{
    /**
     * The records of `$resourceType` this profile may act on with `$permission`.
     *
     * @param int         $profileId              The caller.
     * @param int         $tenantId               The tenant resolved for the request (0 = system).
     * @param string      $permission             The capability being exercised, e.g. `acme:records_read`.
     * @param string      $resourceType           The namespaced type, e.g. `acme:record`.
     * @param string|null $unrestrictedPermission A permission that lets its holder act on every
     *                                            record, e.g. `acme:records_all`. Null: never.
     */
    public function reach(
        int $profileId,
        int $tenantId,
        string $permission,
        string $resourceType,
        ?string $unrestrictedPermission = null
    ): ResourceReach;
}
