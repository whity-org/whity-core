<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantFormReviewPermission — who may record the office's decision on a submission.
 *
 * A slug of its own because it is a capability somebody would grant separately: the
 * person who records decisions is often not the person who decides what the form
 * asks (`forms:manage`), and reading submissions (`forms:read`) must not be enough
 * to change the outcome of one.
 *
 * Granted to every role that DIRECTLY holds `forms:manage`, by capability and never
 * by role name (migration 110, #834). Whoever may rewrite the form can today see
 * everything on it, and would otherwise have no way to use the staff fields they
 * author. Nobody else gains anything; an office adds the slug to its own role.
 *
 * Idempotent and reversible. down() takes back exactly the audience's grants, and
 * the catalogue row only when nothing else holds it.
 */
final class GrantFormReviewPermission
{
    private const DESCRIPTION = 'Record the office\'s decision and notes on a submitted form';

    public static function up(Database $db): void
    {
        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [':name' => CorePermissions::FORMS_REVIEW, ':description' => self::DESCRIPTION]
        );

        $permissionId = self::permissionId($db);
        if ($permissionId === null) {
            return;
        }

        foreach (self::audience($db) as $roleId) {
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
        $permissionId = self::permissionId($db);
        if ($permissionId === null) {
            return;
        }

        foreach (self::audience($db) as $roleId) {
            $db->query(
                'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                [':role_id' => $roleId, ':permission_id' => $permissionId]
            );
        }

        $db->query(
            'DELETE FROM permissions
             WHERE name = :name
               AND NOT EXISTS (
                   SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
               )',
            [':name' => CorePermissions::FORMS_REVIEW]
        );
    }

    /**
     * @return list<int>
     */
    private static function audience(Database $db): array
    {
        $rows = $db->query(
            'SELECT rp.role_id
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :name',
            [':name' => CorePermissions::FORMS_MANAGE]
        )->fetchAll();

        return $rows === false ? [] : array_map(static fn (array $row): int => (int) $row['role_id'], $rows);
    }

    private static function permissionId(Database $db): ?int
    {
        $row = $db->query(
            'SELECT id FROM permissions WHERE name = :name',
            [':name' => CorePermissions::FORMS_REVIEW]
        )->fetch();

        return $row === false ? null : (int) $row['id'];
    }
}
