<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

/**
 * Ask for core permissions for your plugin's roles (SDK 1.130, #2256).
 *
 * A plugin grants its roles only its own permissions. When a role needs a core
 * one, say an HR role that writes approved leave to the calendar and needs
 * `calendar:write` and `calendar:manage`, ask for it here:
 *
 * ```php
 * public function getCorePermissionRequests(): array
 * {
 *     return [
 *         'hr' => ['calendar:write', 'calendar:manage'],
 *         'technician' => ['calendar:write'],
 *     ];
 * }
 * ```
 *
 * Nothing is granted on its own. An operator sees the request under your
 * plugin (in the admin screen, and with `whity-cli plugin core-grants <plugin>`)
 * and approves it per role and per workspace. An approval grants exactly what
 * you asked for that role, or part of it, never more. It is recorded with the
 * operator who approved it, can be revoked, and is removed when your plugin is
 * turned off in that workspace or uninstalled.
 *
 * Keys are role names your plugin declares (or roles the workspace already
 * has); values are core permission slugs. Anything that is not a core
 * permission is ignored: your own permissions are granted the usual way.
 *
 * To check whether a grant is in place, so a screen can say "ask an admin to
 * approve calendar access for HR" instead of failing, resolve
 * {@see CorePermissionGrants} with `\Whity\app(CorePermissionGrants::class)`.
 */
interface CorePermissionRequestsInterface
{
    /**
     * @return array<string, list<string>> Role name => core permission slugs.
     */
    public function getCorePermissionRequests(): array;
}
