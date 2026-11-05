<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Keys for a plugin's public form route (WHIT-906, SDK 1.67).
 *
 * The key is what a public form names its tenant with, in the path, and the
 * only thing that does. So only its SHA-256 is kept: `key_hash` carries the
 * global unique index the anonymous lookup probes, and `key_hint` is the few
 * characters an admin needs to tell two keys apart. The key itself is shown
 * once, when it is issued.
 *
 * - `plugin`, `form_name`: the route it opens. A key opens one form of one plugin.
 * - `allowed_origins`: a JSON list of the origins that may post with this key,
 *   beside the instance's own allowed origins.
 * - `revoked_at`: a revoked key stays listed, so the record of it isn't lost.
 *
 * WHO GETS `public_form_keys:manage`: every role holding `settings:write`, the
 * capability that already lets someone change this workspace's settings. Granted
 * by capability rather than to a role called admin, as migration 110 explains.
 */
class CreatePublicFormKeys
{
    private const DESCRIPTION = 'Issue and revoke the keys that open a plugin\'s public form for this workspace';

    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS public_form_keys (
                id              BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id       INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin          VARCHAR(64)  NOT NULL,
                form_name       VARCHAR(63)  NOT NULL,
                key_hash        CHAR(64)     NOT NULL UNIQUE,
                key_hint        VARCHAR(8)   NOT NULL,
                label           VARCHAR(120) NOT NULL,
                allowed_origins TEXT         NOT NULL DEFAULT '[]',
                created_by      INTEGER      NULL,
                created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                revoked_at      TIMESTAMP    NULL,
                revoked_by      INTEGER      NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_public_form_keys_tenant ON public_form_keys (tenant_id, plugin, form_name)');

        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [':name' => CorePermissions::PUBLIC_FORM_KEYS_MANAGE, ':description' => self::DESCRIPTION]
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
        if ($permissionId !== null) {
            $db->query('DELETE FROM role_permissions WHERE permission_id = :id', [':id' => $permissionId]);
            $db->query('DELETE FROM permissions WHERE id = :id', [':id' => $permissionId]);
        }
        $db->exec('DROP TABLE IF EXISTS public_form_keys');
    }

    /** @return list<int> */
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

    private static function permissionId(Database $db): ?int
    {
        $row = $db->query(
            'SELECT id FROM permissions WHERE name = :name',
            [':name' => CorePermissions::PUBLIC_FORM_KEYS_MANAGE]
        )->fetch();

        return $row === false ? null : (int) $row['id'];
    }
}
