<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

/**
 * How carefully a person must look at an agent's proposal before it runs
 * (AQ.1, register "Approvals list"):
 *
 *  - 1, Ordinary: it can be undone and touches nothing sensitive. Only these
 *    may be approved together from the list.
 *  - 2, Check first: it can't be undone, or it publishes, issues, pays or
 *    closes something. It opens on its own.
 *  - 3, Needs verifying: it changes who can do what or who can sign in (people,
 *    roles, delegations, sign-in and security settings, plugins, billing), or it
 *    deletes. It opens on its own and asks the person to confirm it's them.
 *
 * Worked out from the permissions the call needs and whether it can be undone,
 * both fixed when the agent asked, so the tier can't change between the list
 * and the approval.
 */
final class ProposalTier
{
    public const ORDINARY = 1;
    public const CHECK_FIRST = 2;
    public const NEEDS_VERIFYING = 3;

    /** Resources whose every action changes access, identity or money owed. */
    private const SENSITIVE_RESOURCES = [
        'users', 'roles', 'tenants', 'ous', 'permissions', 'delegation', 'auth_providers',
        'email_domains', 'security', 'password_resets', 'two_factor_recovery', 'registrations',
        'entitlements', 'plans', 'subscriptions', 'licensing', 'migrations', 'storage', 'signup',
        'public_form_keys', 'agents',
    ];

    /** Actions that are sensitive on any resource. */
    private const SENSITIVE_ACTIONS = ['delete', 'install', 'uninstall', 'upload', 'enable', 'disable', 'pay'];

    /** Actions that can't be taken back in practice, so they are looked at one by one. */
    private const CHECK_FIRST_ACTIONS = ['publish', 'issue', 'withdraw', 'close', 'reopen', 'decide', 'route'];

    /**
     * @param list<string> $permissions the `resource:action` permissions the call needs
     */
    public static function of(array $permissions, ?int $undoWindowSeconds): int
    {
        $tier = ($undoWindowSeconds ?? 0) > 0 ? self::ORDINARY : self::CHECK_FIRST;

        foreach ($permissions as $permission) {
            [$resource, $action] = array_pad(explode(':', (string) $permission, 2), 2, '');
            if (in_array($resource, self::SENSITIVE_RESOURCES, true) || in_array($action, self::SENSITIVE_ACTIONS, true)) {
                return self::NEEDS_VERIFYING;
            }
            if (in_array($action, self::CHECK_FIRST_ACTIONS, true)) {
                $tier = self::CHECK_FIRST;
            }
        }

        return $tier;
    }
}
