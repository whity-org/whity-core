<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Workspace-controlled customer sign-up (WHIT-914).
 *
 * Until now the only self-service sign-up created a NEW workspace, governed by
 * the operator. This adds the second kind: a person joins ONE existing
 * workspace as a customer, governed by that workspace's own admins.
 *
 * - `memberships.kind`: `member` (staff, every row written before this
 *   migration) or `customer`. RBAC reads it, so a customer membership can never
 *   carry privileged permissions whatever its role says.
 * - `workspace_signup_settings`: one row per workspace that has touched the
 *   setting. No row means Off. `role_id` is the starting role, checked
 *   customer-safe on save and again on every sign-up; a deleted role sets it to
 *   NULL, which reads as "not configured" and refuses sign-ups.
 * - `customer_signups`: one row per sign-up request. Only the SHA-256 of the
 *   emailed token is kept. No membership exists until the address is verified
 *   AND (in Approval required mode) an admin approves, so a pending request
 *   grants nothing and cannot be completed by any other path.
 *
 * No role is seeded: the starting role is any role the workspace may assign
 * that holds nothing privileged, and the admin picks it.
 *
 * WHO GETS `signup:manage`: every role holding `settings:write`, the capability
 * that already lets someone change this workspace's settings. Granted by
 * capability rather than to a role called admin, as migration 110 explains.
 *
 * Idempotent and reversible.
 */
final class CreateWorkspaceSignup
{
    private const DESCRIPTION = 'Turn customer sign-up on or off for this workspace, choose the starting role, and approve or reject people waiting to join';

    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE memberships ADD COLUMN IF NOT EXISTS kind VARCHAR(16) NOT NULL DEFAULT 'member'");

        $db->exec("
            CREATE TABLE IF NOT EXISTS workspace_signup_settings (
                tenant_id       INTEGER      NOT NULL PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
                mode            VARCHAR(16)  NOT NULL DEFAULT 'off',
                role_id         INTEGER      NULL REFERENCES roles(id) ON DELETE SET NULL,
                allowed_domains TEXT         NOT NULL DEFAULT '[]',
                updated_by      INTEGER      NULL,
                updated_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                CHECK (mode IN ('off', 'approval', 'open'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS customer_signups (
                id            BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id     INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                email         VARCHAR(255) NOT NULL,
                display_name  VARCHAR(255) NOT NULL,
                token_hash    CHAR(64)     NULL UNIQUE,
                status        VARCHAR(16)  NOT NULL DEFAULT 'unverified',
                profile_id    INTEGER      NULL REFERENCES profiles(id) ON DELETE CASCADE,
                expires_at    TIMESTAMP    NOT NULL,
                verified_at   TIMESTAMP    NULL,
                decided_at    TIMESTAMP    NULL,
                decided_by    INTEGER      NULL,
                created_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
                CHECK (status IN ('unverified', 'pending', 'approved', 'rejected', 'joined', 'superseded'))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_customer_signups_tenant_status ON customer_signups (tenant_id, status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_customer_signups_tenant_email ON customer_signups (tenant_id, email)');

        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [':name' => CorePermissions::SIGNUP_MANAGE, ':description' => self::DESCRIPTION]
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
        $db->exec('DROP TABLE IF EXISTS customer_signups');
        $db->exec('DROP TABLE IF EXISTS workspace_signup_settings');
        $db->exec('ALTER TABLE memberships DROP COLUMN IF EXISTS kind');
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

        return array_values(array_map(static fn (array $row): int => (int) $row['role_id'], $rows));
    }

    private static function permissionId(Database $db): ?int
    {
        $row = $db->query(
            'SELECT id FROM permissions WHERE name = :name',
            [':name' => CorePermissions::SIGNUP_MANAGE]
        )->fetch();

        return $row === false ? null : (int) $row['id'];
    }
}
