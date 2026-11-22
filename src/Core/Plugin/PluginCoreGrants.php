<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Contribute\CorePermissionRequestsInterface;
use Whity\Sdk\PluginInterface;

/**
 * Core permissions a plugin's roles hold because an operator approved them
 * (#2256).
 *
 * A plugin grants only its own permissions ({@see \Whity\Core\PluginRoleSeeder}).
 * When its roles need a core one (an HR role writing approved leave to the
 * calendar needs `calendar:write` and `calendar:manage`), the plugin ASKS
 * through {@see CorePermissionRequestsInterface}, and nothing happens until an
 * operator approves it, per role and per workspace.
 *
 * Rules:
 *  - An approval grants exactly what the plugin asked for that role, or the
 *    part of it the operator picked, and never anything else: a slug the
 *    plugin did not ask for, or one that is not a core permission, is refused.
 *  - Each approved permission is one `plugin_core_grants` row (who approved it,
 *    when) and one `role_permissions` row marked `granted_by_plugin`, so
 *    {@see \Whity\Core\PluginRoleSeeder::removeGrants()} takes it away with the
 *    plugin's other grants. A permission the role already held for another
 *    reason keeps that grant: revoking takes back only what the approval added.
 *  - Turning the plugin off in a workspace, or revoking, removes the grants and
 *    the approvals there ({@see self::removeAll()}).
 *  - `tenant_id` 0 means the global roles, as everywhere in the seeder.
 */
final class PluginCoreGrants
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * What the plugin asks for: role name => core permissions, de-duplicated.
     * Anything that is not a core permission is dropped here; the plugin's own
     * permissions go through the seeder, not through an approval.
     *
     * @return array<string, list<string>>
     */
    public static function requests(PluginInterface $plugin): array
    {
        if (!$plugin instanceof CorePermissionRequestsInterface) {
            return [];
        }
        try {
            // A plugin is third-party code: its answer is checked, not trusted
            // to match the interface's declared shape.
            /** @var array<mixed, mixed> $raw */
            $raw = $plugin->getCorePermissionRequests();
        } catch (\Throwable) {
            return [];
        }

        $core = array_fill_keys(CorePermissions::all(), true);
        $requests = [];
        foreach ($raw as $role => $slugs) {
            if (!is_string($role) || $role === '' || !is_array($slugs)) {
                continue;
            }
            $kept = [];
            foreach ($slugs as $slug) {
                if (is_string($slug) && isset($core[$slug])) {
                    $kept[$slug] = true;
                }
            }
            if ($kept !== []) {
                $names = array_keys($kept);
                sort($names);
                $requests[$role] = $names;
            }
        }
        ksort($requests);

        return $requests;
    }

    /**
     * For each role the plugin asks for, in one workspace: what it asks for and
     * what is approved.
     *
     * @return list<array{role: string, role_id: int|null, requested: list<string>, granted: list<string>}>
     */
    public function status(PluginInterface $plugin, int $tenantId): array
    {
        $rows = [];
        foreach (self::requests($plugin) as $role => $requested) {
            $roleId = $this->roleId($role, $tenantId);
            $rows[] = [
                'role' => $role,
                'role_id' => $roleId,
                'requested' => $requested,
                'granted' => $roleId === null ? [] : $this->granted($plugin->getName(), $roleId, $tenantId),
            ];
        }

        return $rows;
    }

    /**
     * Whether the operator approved `$permission` for the plugin's `$role` in
     * this workspace, and the grant is in place.
     */
    public function isGranted(string $pluginName, string $role, string $permission, int $tenantId): bool
    {
        $roleId = $this->roleId($role, $tenantId);

        return $roleId !== null && in_array($permission, $this->granted($pluginName, $roleId, $tenantId), true);
    }

    /**
     * Approve the plugin's request for one role in one workspace: all of it,
     * or the listed part of it.
     *
     * @param list<string>|null $permissions Null: everything the plugin asks for this role.
     * @return list<string> What this call granted (already-approved ones are not repeated).
     *
     * @throws \InvalidArgumentException When the role isn't asked for, doesn't
     *         exist there, or a listed permission wasn't asked for.
     */
    public function approve(PluginInterface $plugin, string $role, int $tenantId, ?int $approvedBy, ?array $permissions = null): array
    {
        $requested = self::requests($plugin)[$role] ?? null;
        if ($requested === null) {
            throw new \InvalidArgumentException("The plugin does not ask for core permissions for role {$role}");
        }
        $roleId = $this->roleId($role, $tenantId);
        if ($roleId === null) {
            throw new \InvalidArgumentException("Role {$role} does not exist in this workspace");
        }
        $wanted = $permissions ?? $requested;
        $extra = array_values(array_diff($wanted, $requested));
        if ($extra !== []) {
            throw new \InvalidArgumentException('Not asked for by the plugin: ' . implode(', ', $extra));
        }

        $pluginName = $plugin->getName();
        $insertApproval = $this->db->prepare(
            'INSERT INTO plugin_core_grants (tenant_id, plugin_key, role_id, permission, approved_by_profile_id, created_at)
             VALUES (:tenant_id, :plugin, :role_id, :permission, :by, CURRENT_TIMESTAMP)
             ON CONFLICT (role_id, plugin_key, permission) DO NOTHING'
        );
        $insertGrant = $this->db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, granted_by_plugin, created_at)
             SELECT :role_id, id, :plugin, CURRENT_TIMESTAMP FROM permissions WHERE name = :permission
             ON CONFLICT (role_id, permission_id) DO NOTHING'
        );

        $granted = [];
        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }
        try {
            $wanted = array_values(array_unique($wanted));
            sort($wanted);
            foreach ($wanted as $permission) {
                $insertApproval->execute([
                    ':tenant_id' => $tenantId,
                    ':plugin' => $pluginName,
                    ':role_id' => $roleId,
                    ':permission' => $permission,
                    ':by' => $approvedBy,
                ]);
                if ($insertApproval->rowCount() === 1) {
                    $granted[] = $permission;
                }
                $insertGrant->execute([':role_id' => $roleId, ':plugin' => $pluginName, ':permission' => $permission]);
            }
            if ($owned) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $granted;
    }

    /**
     * Take back an approval for one role in one workspace: all of it, or the
     * listed permissions.
     *
     * @param list<string>|null $permissions Null: everything approved for this role.
     * @return list<string> What was taken back.
     */
    public function revoke(string $pluginName, string $role, int $tenantId, ?array $permissions = null): array
    {
        $roleId = $this->roleId($role, $tenantId);
        if ($roleId === null) {
            return [];
        }
        $approved = $this->granted($pluginName, $roleId, $tenantId);
        $taken = $permissions === null ? $approved : array_values(array_intersect($approved, $permissions));
        if ($taken === []) {
            return [];
        }

        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }
        try {
            $this->removeRows($pluginName, [$roleId], $taken, $tenantId);
            if ($owned) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $taken;
    }

    /**
     * Every approval the plugin holds in a workspace, and its grants, gone:
     * the plugin was turned off there or removed. Approving again is the
     * operator's to do.
     */
    public function removeAll(string $pluginName, int $tenantId): void
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT role_id, permission FROM plugin_core_grants WHERE tenant_id = :tenant_id AND plugin_key = :plugin'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':plugin' => $pluginName]);
        $byRole = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byRole[(int) $row['role_id']][] = (string) $row['permission'];
        }
        foreach ($byRole as $roleId => $permissions) {
            $this->removeRows($pluginName, [$roleId], $permissions, $tenantId);
        }
    }

    /**
     * @param list<int>    $roleIds
     * @param list<string> $permissions
     */
    private function removeRows(string $pluginName, array $roleIds, array $permissions, int $tenantId): void
    {
        foreach ($roleIds as $roleId) {
            foreach ($permissions as $permission) {
                $this->db->prepare(
                    'DELETE FROM plugin_core_grants
                      WHERE tenant_id = :tenant_id AND plugin_key = :plugin AND role_id = :role_id AND permission = :permission'
                )->execute([':tenant_id' => $tenantId, ':plugin' => $pluginName, ':role_id' => $roleId, ':permission' => $permission]);
                // Only the row this approval wrote: one that core or an admin
                // wrote carries no plugin name and stays.
                $this->db->prepare(
                    'DELETE FROM role_permissions
                      WHERE role_id = :role_id AND granted_by_plugin = :plugin
                        AND permission_id IN (SELECT id FROM permissions WHERE name = :permission)'
                )->execute([':role_id' => $roleId, ':plugin' => $pluginName, ':permission' => $permission]);
            }
        }
    }

    /** @return list<string> */
    private function granted(string $pluginName, int $roleId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT permission FROM plugin_core_grants
              WHERE tenant_id = :tenant_id AND plugin_key = :plugin AND role_id = :role_id
              ORDER BY permission'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':plugin' => $pluginName, ':role_id' => $roleId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** The role in the workspace's scope (0 = the global roles). */
    private function roleId(string $role, int $tenantId): ?int
    {
        if ($tenantId === 0) {
            $stmt = $this->db->prepare('SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL ORDER BY id LIMIT 1');
            $stmt->execute([':name' => $role]);
        } else {
            $stmt = $this->db->prepare('SELECT id FROM roles WHERE name = :name AND tenant_id = :tenant_id ORDER BY id LIMIT 1');
            $stmt->execute([':name' => $role, ':tenant_id' => $tenantId]);
        }
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
