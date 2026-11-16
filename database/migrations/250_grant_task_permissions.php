<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantTaskPermissions — the three Taskaat slugs and who holds them (WHIT-962, A10).
 *
 * Tasks and boards live in the workspace's Taskaat (T.3); these gate Whity's
 * way into them: its Tasks and Boards pages and the Taskaat tools in the
 * workspace MCP. Granted BY CAPABILITY (migration 110, ci-grant-by-role-name-guard):
 *
 * - `tasks:read` and `tasks:write` go to every role holding `documents:read`
 *   (every staff role, as migration 249 anchors calendar:read), or
 *   `settings:write`. Tasks are something the whole team uses.
 * - `tasks:manage` (connecting the workspace to Taskaat) goes to every role
 *   holding `settings:write` or `roles:write`, so the administrator holds all
 *   three after upgrade.
 *
 * Direct grants only, as migration 110 explains.
 */
final class GrantTaskPermissions
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::TASKS_READ => 'See tasks and boards in the workspace\'s Taskaat',
        CorePermissions::TASKS_WRITE => 'Create and change tasks in the workspace\'s Taskaat: checks, evidence and reviews',
        CorePermissions::TASKS_MANAGE => 'Connect the workspace to Taskaat, or disconnect it',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::TASKS_READ => [CorePermissions::DOCUMENTS_READ, CorePermissions::SETTINGS_WRITE],
        CorePermissions::TASKS_WRITE => [CorePermissions::DOCUMENTS_READ, CorePermissions::SETTINGS_WRITE],
        CorePermissions::TASKS_MANAGE => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
    ];

    public static function up(Database $db): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $db->query(
                'DELETE FROM permissions
                  WHERE name = :name
                    AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id)',
                [':name' => $name]
            );
        }
    }

    /**
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAny(Database $db, array $permissions): array
    {
        $roleIds = [];
        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();
            if ($rows === false) {
                continue;
            }
            foreach ($rows as $row) {
                $roleIds[(int) $row['role_id']] = true;
            }
        }

        return array_map('intval', array_keys($roleIds));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
