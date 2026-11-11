<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

/**
 * Where each permission sits on the role page's grid (design 2.9, A12): an area, the
 * group of areas it belongs to, and which of See, Create, Change, Delete and Approve
 * it answers.
 *
 * Derived from the slug, whose first segment names the resource and whose last names
 * the act, so a plugin's `acme:widgets:read` lands on the grid with no declaration.
 * A slug can fill two cells (`users:write` is what both creating and changing a user
 * need) or none (`documents:publish`); the second kind is listed under its area, off
 * the grid, so that no grantable permission is ever unreachable from the page.
 *
 * Labels are English defaults. The client translates by key: `permissions.area.<key>`.
 *
 * {@see \Tests\Unit\Core\RBAC\PermissionAreasTest} fails when a core slug has no area
 * here, so a new permission cannot quietly land among the plugins.
 */
final class PermissionAreas
{
    public const GROUP_PEOPLE = 'people';
    public const GROUP_DOCUMENTS = 'documents';
    public const GROUP_AI = 'ai';
    public const GROUP_WORKSPACE = 'workspace';
    public const GROUP_APPS = 'apps';

    public const SEE = 'see';
    public const CREATE = 'create';
    public const CHANGE = 'change';
    public const DELETE = 'delete';
    public const APPROVE = 'approve';

    /** First slug segment => [area key, English label, group]. */
    private const AREAS = [
        'users' => ['users', 'Users', self::GROUP_PEOPLE],
        'roles' => ['roles', 'Roles', self::GROUP_PEOPLE],
        'permissions' => ['roles', 'Roles', self::GROUP_PEOPLE],
        'groups' => ['groups', 'User groups', self::GROUP_PEOPLE],
        'ous' => ['units', 'Organisational units', self::GROUP_PEOPLE],
        'delegation' => ['delegations', 'Delegations', self::GROUP_PEOPLE],
        'relations' => ['relations', 'Family relations', self::GROUP_PEOPLE],
        'registrations' => ['approvals', 'Approval gating', self::GROUP_PEOPLE],
        'password_resets' => ['approvals', 'Approval gating', self::GROUP_PEOPLE],
        'two_factor_recovery' => ['approvals', 'Approval gating', self::GROUP_PEOPLE],
        'security' => ['security', 'Security', self::GROUP_PEOPLE],
        'documents' => ['documents', 'Documents', self::GROUP_DOCUMENTS],
        'route_templates' => ['routes', 'Routes', self::GROUP_DOCUMENTS],
        'tags' => ['tags', 'Tags', self::GROUP_DOCUMENTS],
        'forms' => ['forms', 'Forms', self::GROUP_DOCUMENTS],
        'messages' => ['messages', 'Messages', self::GROUP_PEOPLE],
        'convening' => ['convening', 'Meetings and decisions', self::GROUP_DOCUMENTS],
        'calendar' => ['calendar', 'Calendar and bookings', self::GROUP_PEOPLE],
        'time_windows' => ['time_windows', 'Time windows', self::GROUP_DOCUMENTS],
        'agents' => ['agents', 'Agents', self::GROUP_AI],
        'mcp' => ['connected_ai', 'Connected AI apps', self::GROUP_AI],
        'tenants' => ['workspaces', 'Workspaces', self::GROUP_WORKSPACE],
        'settings' => ['settings', 'Settings', self::GROUP_WORKSPACE],
        'public_form_keys' => ['settings', 'Settings', self::GROUP_WORKSPACE],
        'signup' => ['settings', 'Settings', self::GROUP_WORKSPACE],
        'notifications' => ['notifications', 'Notifications', self::GROUP_WORKSPACE],
        'notification_settings' => ['notifications', 'Notifications', self::GROUP_WORKSPACE],
        'audit' => ['audit', 'Audit log', self::GROUP_WORKSPACE],
        'stats' => ['system', 'System', self::GROUP_WORKSPACE],
        'jobs' => ['system', 'System', self::GROUP_WORKSPACE],
        'storage' => ['system', 'System', self::GROUP_WORKSPACE],
        'migrations' => ['system', 'System', self::GROUP_WORKSPACE],
        'languages' => ['languages', 'Languages and translations', self::GROUP_WORKSPACE],
        'translations' => ['languages', 'Languages and translations', self::GROUP_WORKSPACE],
        'auth_providers' => ['sign_in', 'Sign-in', self::GROUP_WORKSPACE],
        'email_domains' => ['sign_in', 'Sign-in', self::GROUP_WORKSPACE],
        'plugins' => ['plugins', 'Plugins', self::GROUP_WORKSPACE],
        'desktop-plugins' => ['plugins', 'Plugins', self::GROUP_WORKSPACE],
        'desktop-app-updates' => ['plugins', 'Plugins', self::GROUP_WORKSPACE],
        'plans' => ['billing', 'Billing and plans', self::GROUP_WORKSPACE],
        'subscriptions' => ['billing', 'Billing and plans', self::GROUP_WORKSPACE],
        'billing' => ['billing', 'Billing and plans', self::GROUP_WORKSPACE],
        'entitlements' => ['billing', 'Billing and plans', self::GROUP_WORKSPACE],
        'licensing' => ['licensing', 'Licensing', self::GROUP_WORKSPACE],
    ];

    /** Last slug segment => the grid cells it fills. */
    private const ACTS = [
        'read' => [self::SEE],
        'view' => [self::SEE],
        'write' => [self::CREATE, self::CHANGE],
        'create' => [self::CREATE],
        'update' => [self::CHANGE],
        'delete' => [self::DELETE],
        'approve' => [self::APPROVE],
        'decide' => [self::APPROVE],
        'review' => [self::APPROVE],
    ];

    private function __construct()
    {
    }

    /**
     * @return array{area: string, areaLabel: string, areaGroup: string, actions: list<string>}
     */
    public static function of(string $slug): array
    {
        $segments = explode(':', $slug);
        $first = $segments[0];
        $last = $segments[count($segments) - 1];

        // Only the plain two-part form fills a cell: `documents:read:all` is a wider
        // kind of seeing, and showing it as See would hide `documents:read` beside it.
        $core = self::AREAS[$first] ?? null;
        $actions = $core !== null && count($segments) > 2 ? [] : (self::ACTS[$last] ?? []);

        if ($core !== null) {
            return ['area' => $core[0], 'areaLabel' => $core[1], 'areaGroup' => $core[2], 'actions' => $actions];
        }

        return [
            'area' => $first,
            'areaLabel' => ucwords(str_replace(['-', '_'], ' ', $first)),
            'areaGroup' => self::GROUP_APPS,
            'actions' => $actions,
        ];
    }

    /**
     * Whether core has placed this slug's resource, rather than treating it as a plugin's.
     */
    public static function isMapped(string $slug): bool
    {
        return isset(self::AREAS[explode(':', $slug)[0]]);
    }
}
