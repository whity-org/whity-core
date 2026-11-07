<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * Whether a role may be handed to a customer (WHIT-915).
 *
 * A workspace's customer sign-up gives strangers a role. That role must not be
 * able to run the workspace, so it is customer-safe only when, through its whole
 * parent chain, it holds no privileged permission. The answer is asked twice:
 * when an admin saves the sign-up settings, and again when somebody signs up,
 * because the role's permissions may have changed in between.
 *
 * The same test caps what a customer membership can do at all: RoleChecker drops
 * privileged permissions from a profile whose memberships in a workspace are all
 * `kind = customer`, so giving a customer the admin role later does not make
 * them an admin. Promoting a customer to staff is a membership change, not a
 * role change.
 *
 * Like the plugin role seeder's guard (GHSA-r4hx-wj99-jw8m), the decision is
 * made on the permission set, never on what the role is called: a role named
 * "customer" that someone gave `roles:write` is not customer-safe.
 */
final class CustomerSafeRole
{
    /**
     * Actions that change who can do what, decide on someone's behalf, or
     * remove things. Any resource, core or plugin.
     */
    private const PRIVILEGED_ACTIONS = [
        '*', 'manage', 'admin', 'approve', 'assign', 'delete', 'grant', 'impersonate',
        'issue', 'withdraw', 'publish', 'decide', 'review', 'route', 'close', 'reopen',
        'enable', 'disable', 'upload', 'install', 'uninstall', 'reload', 'build', 'pay',
    ];

    /**
     * Core resources that ARE the administration of a workspace or the
     * installation. Any action on them is privileged, reading included: the
     * user list, the audit log and the settings are staff information.
     */
    private const ADMIN_RESOURCES = [
        'users', 'roles', 'tenants', 'ous', 'permissions', 'audit', 'plugins',
        'desktop-plugins', 'desktop-app-updates', 'delegation', 'relations',
        'settings', 'agents', 'registrations', 'auth_providers', 'email_domains',
        'entitlements', 'storage', 'plans', 'subscriptions', 'billing', 'licensing',
        'security', 'notifications', 'notification_settings', 'password_resets',
        'two_factor_recovery', 'languages', 'translations', 'stats', 'migrations',
        'public_form_keys', 'signup', 'groups', 'route_templates', 'time_windows',
        'jobs', 'mcp',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly AssignableRole $assignable,
    ) {
    }

    public static function make(PDO $db): self
    {
        return new self($db, new AssignableRole($db));
    }

    /** Whether one permission slug is privileged for a customer. */
    public static function isPrivilegedPermission(string $permission): bool
    {
        $permission = strtolower(trim($permission));
        if ($permission === '' || $permission === '*') {
            return true;
        }

        $colon = strpos($permission, ':');
        if ($colon === false) {
            // Not resource:action. Treat what cannot be read as privileged.
            return true;
        }

        $resource = substr($permission, 0, $colon);
        $action = substr($permission, $colon + 1);

        if ($resource === '*' || in_array($resource, self::ADMIN_RESOURCES, true)) {
            return true;
        }

        return in_array($action, self::PRIVILEGED_ACTIONS, true);
    }

    /**
     * Whether this workspace may give this role to customers: it must be the
     * workspace's own role or a global one, and hold nothing privileged.
     *
     * Says nothing about WHY not, for the reason {@see AssignableRole} gives.
     */
    public function isCustomerSafe(int $roleId, int $tenantId): bool
    {
        if (!$this->assignable->isAssignable($roleId, $tenantId)) {
            return false;
        }

        foreach ($this->permissionsThroughChain($roleId) as $permission) {
            if (self::isPrivilegedPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every role this workspace may offer customers, by name.
     *
     * @return list<array{id: int, name: string, description: string, global: bool}>
     */
    public function listForTenant(int $tenantId): array
    {
        // Own-or-global is the predicate, as in AssignableRole
        $stmt = $this->db->prepare(
            'SELECT id, name, description, tenant_id FROM roles
              WHERE tenant_id = :tid OR tenant_id IS NULL
              ORDER BY name ASC, id ASC'
        );
        $stmt->execute([':tid' => $tenantId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if (!$this->isCustomerSafe($id, $tenantId)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'global' => $row['tenant_id'] === null,
            ];
        }

        return $out;
    }

    /**
     * Direct permissions of the role and every parent, bounded and cycle-safe
     * the way RoleChecker walks the same chain.
     *
     * @return list<string>
     */
    private function permissionsThroughChain(int $roleId): array
    {
        $permissions = [];
        $visited = [];
        $current = $roleId;

        $direct = $this->db->prepare(
            'SELECT p.name FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :rid'
        );
        // @tenant-guard-ignore: walks the parent chain of a role already checked assignable to this tenant above; a parent is followed by id exactly as RoleChecker does
        $parent = $this->db->prepare('SELECT parent_id FROM roles WHERE id = :rid');

        while ($current !== null && !isset($visited[$current]) && count($visited) < 32) {
            $visited[$current] = true;

            $direct->execute([':rid' => $current]);
            foreach ($direct->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $permissions[(string) $name] = true;
            }

            $parent->execute([':rid' => $current]);
            $next = $parent->fetchColumn();
            $current = $next === false || $next === null ? null : (int) $next;
        }

        if ($current !== null) {
            // A cycle or a chain past the bound: refuse rather than guess.
            $permissions['*'] = true;
        }

        return array_keys($permissions);
    }
}
