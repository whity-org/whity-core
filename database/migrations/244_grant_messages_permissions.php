<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Messages permissions (ADR 0016 §1, WHIT-938), granted by capability.
 *
 * `messages:read` sees the inbox, `messages:reply` sends replies and writes
 * notes, `messages:manage` assigns, changes status and sets the channels up.
 * All three go to the roles that already hold `settings:write`, the people who
 * run the workspace; they then hand read and reply to whichever staff roles
 * answer customers. Granted by what a role can already do, never by its name
 * (#834): a renamed administrative role keeps the grant.
 */
final class GrantMessagesPermissions
{
    private const GRANTS = [
        CorePermissions::MESSAGES_READ => 'See the workspace\'s conversations with customers',
        CorePermissions::MESSAGES_REPLY => 'Reply to customers and write internal notes in conversations',
        CorePermissions::MESSAGES_MANAGE => 'Assign conversations, change their status and set up message channels',
    ];

    public static function up(Database $db): void
    {
        $audience = self::audience($db);

        foreach (self::GRANTS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );

            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            foreach ($audience as $roleId) {
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
        $audience = self::audience($db);

        foreach (array_keys(self::GRANTS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            foreach ($audience as $roleId) {
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
                [':name' => $name]
            );
        }
    }

    /** @return list<int> roles holding `settings:write` */
    private static function audience(Database $db): array
    {
        $rows = $db->query(
            'SELECT rp.role_id
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :name',
            [':name' => CorePermissions::SETTINGS_WRITE]
        )->fetchAll();

        return $rows === false ? [] : array_map(static fn (array $row): int => (int) $row['role_id'], $rows);
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $row = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $row === false ? null : (int) $row['id'];
    }
}
