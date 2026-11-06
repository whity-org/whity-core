<?php

declare(strict_types=1);

namespace Whity\Core;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRolesInterface;

/**
 * Seeds and removes plugin-declared roles and permission grants.
 *
 * Called by {@see PluginLoader} after a plugin passes the compatibility gate
 * and is registered (seed path) and before its capabilities are torn down on
 * uninstall (removal path). All DB writes carry tenant_id so isolation is
 * never violated.
 *
 * Design constraints
 * ------------------
 * - Idempotent: calling seed() twice for the same plugin/tenant is safe.
 * - Fail-soft: a DB error on any individual row is caught, logged, and skipped
 *   so one bad permission slug never aborts the whole seeding sequence.
 * - Never exposes raw exceptions to callers: errors are swallowed after logging.
 * - Tenant isolation: every INSERT carries the resolved tenant_id.
 * - A plugin stays within its own grants (GHSA-r4hx-wj99-jw8m, migration 221):
 *   it grants only permissions it declares in
 *   {@see PluginInterface::getPermissions()}, never a core permission or one
 *   under a core resource, and only to a role it created or one the operator
 *   approved for it ({@see approveRole()}). Each grant records the plugin that
 *   made it, and uninstall removes only those rows.
 */
final class PluginRoleSeeder
{
    /** System-tenant id used for global (non-tenant-scoped) activations. */
    public const SYSTEM_TENANT_ID = 0;

    private PDO $pdo;
    private ?LoggerInterface $logger;

    /**
     * @param PDO                  $pdo    Live database connection.
     * @param LoggerInterface|null $logger Optional PSR-3 logger.
     */
    public function __construct(PDO $pdo, ?LoggerInterface $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    /**
     * Seed the roles and permission grants declared by a plugin.
     *
     * Called after the plugin registers successfully (activation path). If the
     * plugin does not implement {@see PluginRolesInterface} this is a no-op.
     *
     * @param PluginInterface $plugin   The activating plugin.
     * @param int             $tenantId The tenant to seed into (0 = system tenant).
     * @return void
     */
    public function seed(PluginInterface $plugin, int $tenantId): void
    {
        if (!$plugin instanceof PluginRolesInterface) {
            return;
        }

        $pluginName = $plugin->getName();

        /** @var mixed $rolesRaw */
        $rolesRaw = [];
        /** @var mixed $permissionsRaw */
        $permissionsRaw = [];

        try {
            $rolesRaw = $plugin->getRoles();
            $permissionsRaw = $plugin->getRolePermissions();
        } catch (\Throwable $e) {
            $this->log(
                'warning',
                sprintf(
                    'PluginRoleSeeder: getRoles()/getRolePermissions() threw for plugin "%s": %s',
                    $pluginName,
                    $e->getMessage()
                ),
                ['plugin' => $pluginName, 'tenant_id' => $tenantId]
            );
            return;
        }

        // getRoles()/getRolePermissions() declare a native `array` return, so a
        // plugin that returns anything else throws a TypeError, caught above.
        /** @var array<mixed, mixed> $roles */
        $roles = $rolesRaw;
        /** @var array<mixed, mixed> $permissions */
        $permissions = $permissionsRaw;

        // First pass: seed role rows. Track name => id for the grants pass.
        /** @var array<string, int> $roleIds name => persisted id */
        $roleIds = [];

        foreach ($roles as $roleName => $descriptor) {
            if (!is_string($roleName) || $roleName === '' || !is_array($descriptor)) {
                continue;
            }

            $description = isset($descriptor['description']) && is_string($descriptor['description'])
                ? $descriptor['description']
                : '';

            $parentName = isset($descriptor['parent']) && is_string($descriptor['parent'])
                ? $descriptor['parent']
                : null;

            $id = $this->seedRole($roleName, $description, $parentName, $tenantId, $pluginName);
            if ($id !== null) {
                $roleIds[$roleName] = $id;
            }
        }

        $ownPermissions = $this->ownPermissions($plugin, $tenantId);

        // Second pass: grant the plugin's own permissions to roles it may grant to.
        foreach ($permissions as $roleName => $slugs) {
            if (!is_string($roleName) || !is_array($slugs)) {
                continue;
            }

            $roleId = $roleIds[$roleName] ?? $this->findRoleId($roleName, $tenantId);

            if ($roleId === null) {
                $this->log(
                    'warning',
                    sprintf(
                        'PluginRoleSeeder: role "%s" not found for plugin "%s" when seeding permissions; skipping',
                        $roleName,
                        $pluginName
                    ),
                    ['plugin' => $pluginName, 'role' => $roleName, 'tenant_id' => $tenantId]
                );
                continue;
            }

            if (!$this->mayGrantTo($roleId, $pluginName, $tenantId)) {
                $this->log(
                    'warning',
                    sprintf(
                        'PluginRoleSeeder: plugin "%s" did not create role "%s" and it is not approved for the plugin; not granting',
                        $pluginName,
                        $roleName
                    ),
                    ['plugin' => $pluginName, 'role' => $roleName, 'tenant_id' => $tenantId]
                );
                continue;
            }

            foreach ($slugs as $slug) {
                if (!is_string($slug) || $slug === '') {
                    continue;
                }

                if (!isset($ownPermissions[$slug])) {
                    $this->log(
                        'warning',
                        sprintf(
                            'PluginRoleSeeder: plugin "%s" grants only its own declared, non-core permissions; not granting "%s"',
                            $pluginName,
                            $slug
                        ),
                        ['plugin' => $pluginName, 'role' => $roleName, 'slug' => $slug, 'tenant_id' => $tenantId]
                    );
                    continue;
                }

                $this->grantPermission($roleId, $slug, $pluginName, $tenantId);
            }
        }
    }

    /**
     * Record that the operator approved an existing role for a plugin's grants.
     *
     * Without an approval a plugin grants only to roles it created itself.
     * Idempotent.
     *
     * @param string   $pluginName The plugin's name, as {@see PluginInterface::getName()} returns it.
     * @param int      $roleId     The approved role.
     * @param int|null $approvedBy The operator's user id, when known.
     * @return void
     */
    public function approveRole(string $pluginName, int $roleId, ?int $approvedBy = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO plugin_role_approvals (role_id, plugin_key, approved_by, created_at)
             VALUES (:role_id, :plugin_key, :approved_by, CURRENT_TIMESTAMP)
             ON CONFLICT (role_id, plugin_key) DO NOTHING'
        );
        $stmt->execute([':role_id' => $roleId, ':plugin_key' => $pluginName, ':approved_by' => $approvedBy]);
    }

    /**
     * Remove the permission grants this plugin made.
     *
     * Called on the uninstall / permanent-removal path. If the plugin does not
     * implement {@see PluginRolesInterface} this is a no-op.
     *
     * Only rows recorded as made by this plugin are removed. A grant made by
     * core or the operator carries no plugin name and stays, even when the
     * plugin also lists that permission.
     *
     * Role rows are intentionally left in place: operators may have assigned
     * users to them; deleting the role row could orphan accounts.
     *
     * @param PluginInterface $plugin   The plugin being uninstalled.
     * @param int             $tenantId The tenant whose grants should be removed (0 = system tenant).
     * @return void
     */
    public function removeGrants(PluginInterface $plugin, int $tenantId): void
    {
        if (!$plugin instanceof PluginRolesInterface) {
            return;
        }

        $pluginName = $plugin->getName();

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM role_permissions
                     WHERE granted_by_plugin = :plugin
                       AND role_id IN (SELECT id FROM roles WHERE tenant_id IS NULL)'
                );
                $stmt->execute([':plugin' => $pluginName]);
            } else {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM role_permissions
                     WHERE granted_by_plugin = :plugin
                       AND role_id IN (SELECT id FROM roles WHERE tenant_id = :tenant_id)'
                );
                $stmt->execute([':plugin' => $pluginName, ':tenant_id' => $tenantId]);
            }
        } catch (PDOException $e) {
            $this->log(
                'error',
                sprintf('PluginRoleSeeder: failed to remove grants for plugin "%s": %s', $pluginName, $e->getMessage()),
                ['plugin' => $pluginName, 'tenant_id' => $tenantId]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Insert or look up a role row, return the role id (or null on error).
     *
     * Idempotent: if a role with (name, tenant_id) already exists its id is
     * returned unchanged, and so is its recorded creator.
     *
     * @param string      $name        Role name to seed.
     * @param string      $description Human-readable description.
     * @param string|null $parentName  Optional parent role name to resolve.
     * @param int         $tenantId    Tenant to scope the role to.
     * @param string      $pluginName  Recorded as the role's creator when it is inserted.
     * @return int|null The role id, or null when the operation failed.
     */
    private function seedRole(
        string $name,
        string $description,
        ?string $parentName,
        int $tenantId,
        string $pluginName
    ): ?int {
        try {
            // Resolve parent role id (if declared).
            $parentId = null;
            if ($parentName !== null && $parentName !== '') {
                $parentId = $this->findRoleId($parentName, $tenantId)
                    ?? $this->findRoleId($parentName, self::SYSTEM_TENANT_ID);
                // If parent still not found, proceed without it (safe fallback).
            }

            // Normalise tenant_id: NULL in the DB means system/global; 0 in PHP
            // is our sentinel for the system tenant. Store NULL for system tenant.
            $dbTenantId = $tenantId === self::SYSTEM_TENANT_ID ? null : $tenantId;

            // Attempt INSERT … ON CONFLICT DO NOTHING, so re-seeding an already
            // seeded role is a no-op rather than a duplicate-key error.
            //
            // This used to infer the conflict on plain `(name)`, with a TODO
            // noting it would need revisiting if role names ever became
            // per-tenant. Migration 093 (#712) did exactly that: `roles_name_key`
            // is gone, replaced by two PARTIAL unique indexes. A conflict target
            // must match one of them INCLUDING its predicate — an unqualified
            // `ON CONFLICT (name)` now matches no index at all and PostgreSQL
            // rejects the statement outright, which would have made every plugin
            // role seed fail at activation. Which index applies is decided by the
            // row being written: a global (NULL-tenant) role conflicts within the
            // global namespace, a tenant-owned one within its tenant's.
            $conflictTarget = $dbTenantId === null
                ? 'ON CONFLICT (name) WHERE tenant_id IS NULL DO NOTHING'
                : 'ON CONFLICT (tenant_id, name) WHERE tenant_id IS NOT NULL DO NOTHING';

            $stmt = $this->pdo->prepare(
                'INSERT INTO roles (name, description, parent_id, tenant_id, plugin_key, created_at)
                 VALUES (:name, :description, :parent_id, :tenant_id, :plugin_key, CURRENT_TIMESTAMP)
                 ' . $conflictTarget
            );

            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':parent_id' => $parentId,
                ':tenant_id' => $dbTenantId,
                ':plugin_key' => $pluginName,
            ]);

            // Retrieve the id regardless of whether we just inserted or the row
            // already existed. Only the role in this tenant's scope counts.
            return $this->findRoleId($name, $tenantId);
        } catch (PDOException $e) {
            $this->log(
                'error',
                sprintf('PluginRoleSeeder: failed to seed role "%s": %s', $name, $e->getMessage()),
                ['role' => $name, 'tenant_id' => $tenantId]
            );
            return null;
        }
    }

    /**
     * Look up a role id by name and tenant.
     *
     * Tries an exact (name, tenant_id) match first; for system-tenant-aware
     * lookups the NULL column value is tested via IS NULL.
     *
     * @param string $name     Role name to look up.
     * @param int    $tenantId Tenant scope (0 = system tenant → NULL in DB).
     * @return int|null The role id, or null when not found.
     */
    private function findRoleId(string $name, int $tenantId): ?int
    {
        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $stmt = $this->pdo->prepare(
                    'SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL LIMIT 1'
                );
                $stmt->execute([':name' => $name]);
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT id FROM roles WHERE name = :name AND tenant_id = :tenant_id LIMIT 1'
                );
                $stmt->execute([':name' => $name, ':tenant_id' => $tenantId]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            return isset($row['id']) ? (int) $row['id'] : null;
        } catch (PDOException $e) {
            $this->log(
                'error',
                sprintf('PluginRoleSeeder: findRoleId failed for "%s": %s', $name, $e->getMessage()),
                ['role' => $name, 'tenant_id' => $tenantId]
            );
            return null;
        }
    }

    /**
     * The permissions this plugin may grant: the ones it declares itself,
     * minus core permissions and anything under a core resource.
     *
     * @param PluginInterface $plugin   The seeding plugin.
     * @param int             $tenantId Tenant context for log messages.
     * @return array<string, true> Slug => true.
     */
    private function ownPermissions(PluginInterface $plugin, int $tenantId): array
    {
        try {
            /** @var array<mixed> $declared getPermissions() is typed natively; the elements are plugin-supplied. */
            $declared = $plugin->getPermissions();
        } catch (\Throwable $e) {
            $this->log(
                'warning',
                sprintf('PluginRoleSeeder: getPermissions() threw for plugin "%s": %s', $plugin->getName(), $e->getMessage()),
                ['plugin' => $plugin->getName(), 'tenant_id' => $tenantId]
            );
            return [];
        }

        $core = CorePermissions::all();
        $coreResources = [];
        foreach ($core as $permission) {
            $coreResources[explode(':', $permission, 2)[0]] = true;
        }

        $own = [];
        foreach ($declared as $slug) {
            if (!is_string($slug) || !str_contains($slug, ':')) {
                continue;
            }
            if (in_array($slug, $core, true) || isset($coreResources[explode(':', $slug, 2)[0]])) {
                continue;
            }
            $own[$slug] = true;
        }

        return $own;
    }

    /**
     * Whether the plugin created this role, or the operator approved it for the plugin.
     *
     * @param int    $roleId     The role to grant to, already resolved in this tenant's scope.
     * @param string $pluginName The granting plugin.
     * @param int    $tenantId   Tenant scope (0 = system tenant → NULL in DB).
     * @return bool
     */
    private function mayGrantTo(int $roleId, string $pluginName, int $tenantId): bool
    {
        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM roles WHERE id = :role_id AND plugin_key = :plugin AND tenant_id IS NULL'
                );
                $stmt->execute([':role_id' => $roleId, ':plugin' => $pluginName]);
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM roles WHERE id = :role_id AND plugin_key = :plugin AND tenant_id = :tenant_id'
                );
                $stmt->execute([':role_id' => $roleId, ':plugin' => $pluginName, ':tenant_id' => $tenantId]);
            }
            if ($stmt->fetchColumn() !== false) {
                return true;
            }

            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM plugin_role_approvals WHERE role_id = :role_id AND plugin_key = :plugin'
            );
            $stmt->execute([':role_id' => $roleId, ':plugin' => $pluginName]);

            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            $this->log(
                'error',
                sprintf('PluginRoleSeeder: could not check role %d for plugin "%s": %s', $roleId, $pluginName, $e->getMessage()),
                ['role_id' => $roleId, 'plugin' => $pluginName]
            );
            return false;
        }
    }

    /**
     * Grant a permission slug to a role, recording the plugin that made the
     * grant (idempotent via ON CONFLICT DO NOTHING).
     *
     * An existing grant keeps its recorded maker, so a grant core or the
     * operator made first is never attributed to the plugin.
     *
     * Silently skips the slug when it does not exist in the permissions catalogue
     * rather than raising an error — the catalogue may not yet contain plugin
     * permissions that are registered later.
     *
     * @param int    $roleId     The role to grant to.
     * @param string $slug       The `resource:action` permission slug.
     * @param string $pluginName The plugin making the grant.
     * @param int    $tenantId   Tenant context for log messages.
     * @return void
     */
    private function grantPermission(int $roleId, string $slug, string $pluginName, int $tenantId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, granted_by_plugin, created_at)
                 SELECT :role_id, id, :plugin, CURRENT_TIMESTAMP
                 FROM permissions
                 WHERE name = :slug
                 ON CONFLICT (role_id, permission_id) DO NOTHING'
            );

            $stmt->execute([':role_id' => $roleId, ':slug' => $slug, ':plugin' => $pluginName]);
        } catch (PDOException $e) {
            $this->log(
                'error',
                sprintf(
                    'PluginRoleSeeder: failed to grant permission "%s" to role %d for plugin "%s": %s',
                    $slug,
                    $roleId,
                    $pluginName,
                    $e->getMessage()
                ),
                ['slug' => $slug, 'role_id' => $roleId, 'plugin' => $pluginName, 'tenant_id' => $tenantId]
            );
        }
    }

    /**
     * Emit a log message through the wired logger, or silently drop when none is wired.
     *
     * @param string               $level   PSR-3 log level ('error', 'warning', 'info', …).
     * @param string               $message The log message.
     * @param array<string, mixed> $context Optional structured context.
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->{$level}($message, $context);
    }
}
