<?php

declare(strict_types=1);

namespace MyCourses\Migrations;

use Whity\Sdk\MigrationInterface;

/**
 * Puts the plugin's permissions in the catalogue and gives them to the admin
 * role, like every reference plugin (WHIT-1426), so no route of it is a
 * lockout. Customers get `mycourses_enrolment:*` through the role the
 * workspace's sign-up gives them (design section 41, Q8).
 */
final class GrantMyCoursesPermissionsToAdmin implements MigrationInterface
{
    /** @var list<string> */
    private const PERMISSIONS = ['mycourses_enrolment:read', 'mycourses_enrolment:update', 'mycourses:manage'];

    private const DESCRIPTION_PREFIX = 'MyCourses plugin permission';

    public function up(\PDO $pdo): void
    {
        $insert = $pdo->prepare(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, CURRENT_TIMESTAMP)
             ON CONFLICT (name) DO NOTHING'
        );
        $grant = $pdo->prepare(
            "INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT r.id, p.id, CURRENT_TIMESTAMP
             FROM roles r, permissions p
             WHERE r.name = 'admin' AND p.name = :permission
             ON CONFLICT (role_id, permission_id) DO NOTHING"
        );

        foreach (self::PERMISSIONS as $permission) {
            $insert->execute([
                ':name' => $permission,
                ':description' => self::DESCRIPTION_PREFIX . ' (' . $permission . ')',
            ]);
            $grant->execute([':permission' => $permission]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $dropGrant = $pdo->prepare(
            "DELETE FROM role_permissions
             WHERE role_id IN (SELECT id FROM roles WHERE name = 'admin')
               AND permission_id IN (SELECT id FROM permissions WHERE name = :permission)"
        );
        $dropCatalogue = $pdo->prepare(
            'DELETE FROM permissions
             WHERE name = :permission
               AND description LIKE :marker
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id)'
        );

        foreach (self::PERMISSIONS as $permission) {
            $dropGrant->execute([':permission' => $permission]);
            $dropCatalogue->execute([
                ':permission' => $permission,
                ':marker' => self::DESCRIPTION_PREFIX . '%',
            ]);
        }
    }
}
