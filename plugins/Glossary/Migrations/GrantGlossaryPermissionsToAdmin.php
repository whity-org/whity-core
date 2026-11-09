<?php

declare(strict_types=1);

namespace Glossary\Migrations;

use Whity\Sdk\MigrationInterface;

/**
 * Seeds the plugin's permissions and grants them to `admin`, so the screen
 * works on a fresh install. `down()` removes only what `up()` added.
 *
 * The same pattern as HelloWorld's GrantGreetingsPermissionsToAdmin, which
 * explains each step.
 */
final class GrantGlossaryPermissionsToAdmin implements MigrationInterface
{
    /** @var list<string> */
    private const PERMISSIONS = ['glossary:view', 'glossary:manage'];

    private const DESCRIPTION_PREFIX = 'Glossary plugin permission';

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
