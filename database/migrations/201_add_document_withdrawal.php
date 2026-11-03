<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * AddDocumentWithdrawal — taking back an issued document (S5, design 2.9 D.3).
 *
 * Paper cannot be recalled and the record must survive, so withdrawing DELETES
 * NOTHING: it is two columns on `documents`, and everything else the act does
 * (retiring codes, closing open items) is written by
 * {@see \Whity\Core\Document\DocumentWithdrawal} through the tables that already
 * record those facts.
 *
 * `withdrawn_by` is SET NULL like every other person pointer on the record: the
 * withdrawal stays a fact after the person who did it has gone.
 *
 * `document_route_events.withdrawal` follows migration 119's argument for
 * `verdict`: the action vocabulary is an inline CHECK that SQLite cannot widen,
 * and an open inbox row can only be closed by a real trail row. So the row
 * closing it is a `noted` event carrying this flag, which also lets a screen tell
 * it from a note somebody wrote.
 *
 * WHO HOLDS `documents:withdraw`: every role that may issue a document today, by
 * capability rather than by role name (migration 110 has the argument). It is its
 * own permission because issuing is an everyday act and withdrawing is not: a
 * workspace can keep the first with its clerks and take the second away.
 *
 * down() removes the columns and exactly the grants up() added. Codes the
 * withdrawal retired stay retired and closed items stay closed, because reopening
 * them would make paper verify again that somebody decided not to stand behind.
 */
final class AddDocumentWithdrawal
{
    private const DESCRIPTION = 'Withdraw an issued document: its codes stop being honoured and open items on it close. The record is kept.';

    private const AUDIENCE_PERMISSION = CorePermissions::DOCUMENTS_ISSUE;

    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS withdrawn_at TIMESTAMP');
        $db->exec(
            'ALTER TABLE documents
                 ADD COLUMN IF NOT EXISTS withdrawn_by INTEGER
                 REFERENCES profiles(id) ON DELETE SET NULL'
        );
        $db->exec(
            'ALTER TABLE document_route_events
                 ADD COLUMN IF NOT EXISTS withdrawal BOOLEAN NOT NULL DEFAULT FALSE'
        );

        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [':name' => CorePermissions::DOCUMENTS_WITHDRAW, ':description' => self::DESCRIPTION]
        );

        $permissionId = self::permissionId($db, CorePermissions::DOCUMENTS_WITHDRAW);
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
        $permissionId = self::permissionId($db, CorePermissions::DOCUMENTS_WITHDRAW);
        if ($permissionId !== null) {
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
                [':name' => CorePermissions::DOCUMENTS_WITHDRAW]
            );
        }

        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS withdrawal');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS withdrawn_by');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS withdrawn_at');
    }

    /**
     * The ids of every role holding the audience permission directly.
     *
     * @return list<int>
     */
    private static function audience(Database $db): array
    {
        $rows = $db->query(
            'SELECT rp.role_id
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :name',
            [':name' => self::AUDIENCE_PERMISSION]
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
