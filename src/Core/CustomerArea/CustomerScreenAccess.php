<?php

declare(strict_types=1);

namespace Whity\Core\CustomerArea;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Signup\WorkspaceSignupSettings;

/**
 * Who among a workspace's customers can open a plugin's customer screen, and
 * giving them the screen's permission after the admin confirms (WHIT-1438;
 * design section 41 §9 and Q8).
 *
 * A customer holds a plugin permission only through their role. The roles
 * that matter are the workspace's customer sign-up role (what new customers
 * get) and the roles of its active customer memberships (what existing ones
 * have). Granting adds the permission to each of those roles that:
 *  - is the workspace's own: a global role is shared by every workspace, so
 *    it is skipped and reported, never changed from here;
 *  - no staff membership here uses: giving a customer screen's permission to
 *    a role staff also hold would widen staff, so it is skipped and reported.
 * The permission itself must be customer-grade (the plugin scopes it to the
 * caller's own records); anything privileged is refused.
 *
 * Each changed role goes through the same `role.updating` / `role.updated`
 * hooks as Settings › Roles, so it is audited like any role edit, and the
 * role cache is cleared.
 */
final class CustomerScreenAccess
{
    public const SKIPPED_GLOBAL = 'global';
    public const SKIPPED_STAFF = 'staff';

    public function __construct(
        private readonly PDO $db,
        private readonly RoleChecker $roles,
        private readonly WorkspaceSignupSettings $signup,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * The customer roles and whether each holds the permission.
     *
     * @return list<array{id: int, name: string, tenant_id: ?int, signup: bool, customers: int, has: bool, skipped: ?string}>
     */
    public function rolesFor(int $tenantId, string $permission): array
    {
        $out = [];
        foreach ($this->customerRoles($tenantId) as $role) {
            $has = in_array($permission, $this->roles->getEffectivePermissionsForRole($role['id'], $tenantId), true);
            $out[] = $role + [
                'has' => $has,
                'skipped' => $has ? null : $this->whySkipped($tenantId, $role),
            ];
        }

        return $out;
    }

    /**
     * Whether every customer role holds the permission. True with no
     * customer role at all: there is nobody to give it to.
     */
    public function customersHave(int $tenantId, string $permission): bool
    {
        foreach ($this->rolesFor($tenantId, $permission) as $role) {
            if (!$role['has']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add the permission to every customer role that may take it.
     *
     * @return array{granted: list<array{id: int, name: string}>, skipped: list<array{id: int, name: string, reason: string}>}|null
     *         null when the permission is unknown or not customer-grade.
     */
    public function grant(int $tenantId, string $permission, int $actorProfileId): ?array
    {
        if (CustomerSafeRole::isPrivilegedPermission($permission)) {
            return null;
        }
        $find = $this->db->prepare('SELECT id FROM permissions WHERE name = :name');
        $find->execute([':name' => $permission]);
        $permissionId = $find->fetchColumn();
        if ($permissionId === false) {
            return null;
        }
        $permissionId = (int) $permissionId;

        $granted = [];
        $skipped = [];
        foreach ($this->rolesFor($tenantId, $permission) as $role) {
            if ($role['has']) {
                continue;
            }
            if ($role['skipped'] !== null) {
                $skipped[] = ['id' => $role['id'], 'name' => $role['name'], 'reason' => $role['skipped']];
                continue;
            }
            $changes = ['permissions' => [$permission], 'permissionsChange' => 'grant', 'by' => 'customer_area', 'actor_profile_id' => $actorProfileId];
            $this->hooks?->dispatch('role.updating', ['id' => $role['id'], 'changes' => $changes, 'tenant_id' => $tenantId]);
            // Bound to this workspace's own role again in the write itself.
            $insert = $this->db->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.id, :pid, NOW() FROM roles r WHERE r.id = :rid AND r.tenant_id = :tid
                 ON CONFLICT DO NOTHING'
            );
            $insert->execute([':pid' => $permissionId, ':rid' => $role['id'], ':tid' => $tenantId]);
            $this->hooks?->dispatch('role.updated', ['id' => $role['id'], 'changes' => $changes, 'tenant_id' => $tenantId]);
            $granted[] = ['id' => $role['id'], 'name' => $role['name']];
        }
        if ($granted !== []) {
            RoleChecker::clearCache();
        }

        return ['granted' => $granted, 'skipped' => $skipped];
    }

    /**
     * The sign-up role first, then the roles of active customer memberships.
     *
     * @return list<array{id: int, name: string, tenant_id: ?int, signup: bool, customers: int}>
     */
    private function customerRoles(int $tenantId): array
    {
        $signupRole = $this->signup->get($tenantId)['role_id'];
        $stmt = $this->db->prepare(
            "SELECT r.id, r.name, r.tenant_id,
                    (SELECT COUNT(*) FROM memberships m
                      WHERE m.role_id = r.id AND m.tenant_id = :tid AND m.status = 'active' AND m.kind = 'customer') AS customers
               FROM roles r
              WHERE (r.tenant_id = :tid2 OR r.tenant_id IS NULL)
                AND (r.id = :signup OR EXISTS (
                      SELECT 1 FROM memberships m2
                       WHERE m2.role_id = r.id AND m2.tenant_id = :tid3 AND m2.status = 'active' AND m2.kind = 'customer'))
              ORDER BY r.name ASC, r.id ASC"
        );
        $stmt->execute([':tid' => $tenantId, ':tid2' => $tenantId, ':tid3' => $tenantId, ':signup' => $signupRole ?? 0]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $role = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'tenant_id' => $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
                'signup' => $signupRole !== null && (int) $row['id'] === $signupRole,
                'customers' => (int) $row['customers'],
            ];
            if ($role['signup']) {
                array_unshift($out, $role);
            } else {
                $out[] = $role;
            }
        }

        return $out;
    }

    /** @param array{id: int, tenant_id: ?int} $role */
    private function whySkipped(int $tenantId, array $role): ?string
    {
        if ($role['tenant_id'] === null) {
            return self::SKIPPED_GLOBAL;
        }
        $staff = $this->db->prepare(
            "SELECT 1 FROM memberships
              WHERE role_id = :rid AND tenant_id = :tid AND status = 'active' AND kind <> 'customer'
              LIMIT 1"
        );
        $staff->execute([':rid' => $role['id'], ':tid' => $tenantId]);

        return $staff->fetchColumn() !== false ? self::SKIPPED_STAFF : null;
    }
}
