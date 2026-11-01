<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantDocumentSuggestAndCommentPermissions (editor M5; E.10, E.29;
 * IMPLEMENTATION-NOTES 3.5).
 *
 * Two new slugs for the editor's comments and suggestions:
 *
 * - `documents:suggest`: suggest changes to a document (E.10). The permission
 *   of people who can read a document but not edit it, and of agents, whose
 *   every change is a suggestion (E.4).
 * - `documents:comment`: start comment threads, reply, resolve and reopen.
 *
 * Accepting and rejecting stays with `documents:write` (E.29).
 *
 * Everyone who can read documents today can comment on and suggest changes to
 * them, so both are granted to every role that holds `documents:read`
 * directly: a capability the deployment granted, never a role name this
 * migration would have to guess (#834; the pattern of migrations 166 and 172).
 *
 * Additive and idempotent (`ON CONFLICT DO NOTHING`). down() removes exactly
 * the grants up() added, and the catalogue rows only when nothing holds them.
 */
class GrantDocumentSuggestAndCommentPermissions
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::DOCUMENTS_SUGGEST => 'Suggest changes to a document, which someone who can edit it accepts or rejects',
        CorePermissions::DOCUMENTS_COMMENT => 'Comment on a document: start threads, reply, resolve and reopen them',
    ];

    /** Whoever can read documents today. */
    private const AUDIENCE_PERMISSION = CorePermissions::DOCUMENTS_READ;

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

        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);
        foreach (array_keys(self::PERMISSIONS) as $name) {
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
        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);
        foreach (array_keys(self::PERMISSIONS) as $name) {
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

    /**
     * The ids of every role holding a permission DIRECTLY (as migration 113:
     * an inherited grant is not copied into an independent one).
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

        if ($rows === false) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['role_id'], $rows);
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
