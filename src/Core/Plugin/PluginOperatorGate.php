<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;

/**
 * Who may change the plugins installed on this deployment (WHIT-869).
 *
 * A code plugin is install-wide: its files sit in `plugins/`, and once enabled
 * its PHP runs inside the shared worker with the shared database connection, for
 * every workspace. So uploading, installing, enabling, disabling, removing and
 * reloading are operator actions, whatever a role's permission slugs say.
 *
 * The slug alone can't express that. The seeded `admin` role is global, and it
 * is also what a new workspace's first administrator holds by default, so a
 * `plugins:upload` check alone lets a workspace admin run code across every
 * other workspace. Operators are the system tenant, the same rule the other
 * instance-wide surfaces use (settings, email, languages).
 *
 * Enforced in the handlers rather than at route registration, so every way in
 * (HTTP, the CLI's kernel, MCP tools derived from the routes) meets it. No
 * tenant at all is refused too: a missing context must not read as an operator.
 */
final class PluginOperatorGate
{
    public static function isOperator(): bool
    {
        return TenantContext::getTenantId() === SettingsService::SYSTEM_TENANT_ID;
    }

    /** The 403 to return, or null when the caller is an operator. */
    public static function refusal(): ?Response
    {
        if (self::isOperator()) {
            return null;
        }

        return Response::error('Plugins are installed and changed by the platform operator.', 403);
    }

    /** The profile acting on this request, for the deployment's plugin records. */
    public static function actorProfile(Request $request): ?int
    {
        $user = $request->user;

        return is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
    }
}
