<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Which workspace may reset a person's sign-in credentials (#2226).
 *
 * A password and two-step sign-in belong to the PERSON, not to a workspace:
 * one profile signs in to every workspace it belongs to, the platform
 * workspace (Tenant 0) included. So a workspace's admins may approve a password
 * reset or clear two-step sign-in only for someone that workspace alone
 * answers for:
 *
 *  - staff there (an active membership that is not a customer's), and
 *  - with no active membership in any other workspace.
 *
 * A customer, or somebody who also belongs elsewhere, is left to the platform
 * workspace's break-glass, which already sees every request. Otherwise the
 * admins of any workspace a person joined (or merely shops at) could take over
 * the account they use everywhere else.
 */
final class CredentialRecoveryScope
{
    /**
     * The membership JOIN for a workspace's own queue: the person behind
     * `$profileColumn` must be staff in `:tenant_id` and nowhere else.
     * Binds `:tenant_id` only, once.
     */
    public static function membershipJoin(string $profileColumn): string
    {
        return "JOIN memberships m ON m.profile_id = {$profileColumn} AND m.tenant_id = :tenant_id AND m.status = 'active'
                  AND m.kind <> '" . MembershipRepository::KIND_CUSTOMER . "'
                  AND NOT EXISTS (
                      SELECT 1 FROM memberships om
                       WHERE om.profile_id = m.profile_id AND om.tenant_id <> m.tenant_id AND om.status = 'active'
                  )";
    }

    /**
     * The same rule as a condition on `$profileColumn`, for UPDATE guards.
     * Binds `:tenant_id` only, once.
     */
    public static function governs(string $profileColumn): string
    {
        return "EXISTS (
                    SELECT 1 FROM memberships m
                     WHERE m.profile_id = {$profileColumn} AND m.tenant_id = :tenant_id AND m.status = 'active'
                       AND m.kind <> '" . MembershipRepository::KIND_CUSTOMER . "'
                       AND NOT EXISTS (
                           SELECT 1 FROM memberships om
                            WHERE om.profile_id = m.profile_id AND om.tenant_id <> m.tenant_id AND om.status = 'active'
                       )
                )";
    }
}
