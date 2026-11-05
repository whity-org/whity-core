<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissionDescriptions;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Registers `plugins:install` (S.9, WHIT-870): installing from a trusted store,
 * split from `plugins:upload`, which stays the permission for a .zip of
 * unreviewed code.
 *
 * Granted to every role that holds `plugins:upload`, by capability rather than
 * by role name (the rule migration 110 set and `ci-grant-by-role-name-guard`
 * enforces). Until now installing from a store WAS `plugins:upload`, so those
 * roles keep what they can do and nobody gains anything.
 */
class GrantPluginsInstallPermission
{
    private const AUDIENCE_PERMISSION = CorePermissions::PLUGINS_UPLOAD;

    public static function up(Database $db): void
    {
        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [
                ':name' => CorePermissions::PLUGINS_INSTALL,
                ':description' => CorePermissionDescriptions::all()[CorePermissions::PLUGINS_INSTALL],
            ]
        );

        $permissionId = self::permissionId($db, CorePermissions::PLUGINS_INSTALL);
        if ($permissionId === null) {
            return;
        }

        foreach (self::rolesHolding($db, self::AUDIENCE_PERMISSION) as $roleId) {
            $db->query(
                'INSERT INTO role_permissions (role_id, permission_id, created_at)
                 VALUES (:role_id, :permission_id, NOW())
                 ON CONFLICT (role_id, permission_id) DO NOTHING',
                [':role_id' => $roleId, ':permission_id' => $permissionId]
            );
        }
    }

    public static function down(Database $db): void
    {
        $permissionId = self::permissionId($db, CorePermissions::PLUGINS_INSTALL);
        if ($permissionId === null) {
            return;
        }

        // The slug didn't exist before up(), so every grant of it goes with it,
        // including any an operator has made since.
        $db->query('DELETE FROM role_permissions WHERE permission_id = :permission_id', [':permission_id' => $permissionId]);
        $db->query('DELETE FROM permissions WHERE id = :id', [':id' => $permissionId]);
    }

    /**
     * Role ids holding a permission directly, by slug.
     *
     * @return list<int>
     */
    private static function rolesHolding(Database $db, string $permission): array
    {
        $rows = $db->query(
            'SELECT rp.role_id
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :name',
            [':name' => $permission]
        )->fetchAll();

        return array_values(array_unique(array_map(static fn (array $row): int => (int) $row['role_id'], $rows)));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
