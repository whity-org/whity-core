<?php

declare(strict_types=1);

namespace Database\Migrations;

use RuntimeException;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateCompanyStamps (#1729) — a company stamp held by named people.
 *
 * WHAT IT ADDS
 * ------------
 *  - `company_stamps`: a workspace's stamps. A name, the legal name the stamp
 *    stands for, and its image (a PNG or JPEG as a data URL, with its SHA-256).
 *    Archived, never deleted: a route step and every stamp already applied name
 *    it.
 *  - `company_stamp_holders`: who may apply a stamp. Each row is a named
 *    person or a role, never both.
 *  - `document_route_steps.stamp_id`: the stamp a `stamp` step asks for.
 *  - `route_signatures.kind`, `stamp_id`, `stamp_name`, `stamp_legal_name`:
 *    an applied stamp is a sealed record in the same table as a signature, so
 *    the audit, the final PDF and the public check read both in one place. Every
 *    existing row is a signature (`kind` defaults to `signature`). The stamp's
 *    image is the record's `appearance`, copied in, so a later change to the
 *    stamp never changes a record already made.
 *  - The permission `documents:stamps` (create, change and archive stamps and
 *    their holders), granted to every role that holds `settings:write`: the
 *    people who run the workspace today (#834: a capability, never a role name).
 *
 * The trail's `stamped` verb was already allowed by migration 226.
 *
 * Idempotent and reversible. down() refuses once a stamp has been applied,
 * because the record is evidence.
 */
final class CreateCompanyStamps
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::DOCUMENTS_STAMPS => "Create, change and archive the workspace's company stamps and choose who holds them",
    ];

    private const AUDIENCE_PERMISSION = CorePermissions::SETTINGS_WRITE;

    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS company_stamps (
                id                    BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name                  VARCHAR(200) NOT NULL,
                legal_name            VARCHAR(300) NOT NULL,
                image                 TEXT         NOT NULL,
                image_hash            VARCHAR(64)  NOT NULL,
                created_by_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                archived_at           TIMESTAMP
            )
        ");
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_company_stamps_tenant ON company_stamps(tenant_id, id)'
        );

        $db->exec("
            CREATE TABLE IF NOT EXISTS company_stamp_holders (
                id         BIGSERIAL NOT NULL PRIMARY KEY,
                tenant_id  INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                stamp_id   BIGINT    NOT NULL REFERENCES company_stamps(id) ON DELETE CASCADE,
                profile_id INTEGER   REFERENCES profiles(id) ON DELETE CASCADE,
                role_id    INTEGER   REFERENCES roles(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CHECK ((profile_id IS NULL) <> (role_id IS NULL))
            )
        ");
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_company_stamp_holders_stamp
                ON company_stamp_holders(tenant_id, stamp_id)'
        );

        // migration-lint: allow constraint-not-valid (a new, all-NULL column: there is nothing to check)
        $db->exec(
            'ALTER TABLE document_route_steps ADD COLUMN IF NOT EXISTS stamp_id BIGINT
                REFERENCES company_stamps(id) ON DELETE SET NULL'
        );

        $db->exec(
            "ALTER TABLE route_signatures ADD COLUMN IF NOT EXISTS kind VARCHAR(16) NOT NULL DEFAULT 'signature'"
        );
        // A sealed record keeps the stamp by id AND by its names as they were
        // when it was applied, like the signer. No foreign key: a sealed record
        // must not be changed by a later cascade.
        // @reference-lint-ignore: which stamp was applied, a fact of a legal record; stamp_name and stamp_legal_name keep it
        $db->exec('ALTER TABLE route_signatures ADD COLUMN IF NOT EXISTS stamp_id BIGINT');
        $db->exec('ALTER TABLE route_signatures ADD COLUMN IF NOT EXISTS stamp_name VARCHAR(200)');
        $db->exec('ALTER TABLE route_signatures ADD COLUMN IF NOT EXISTS stamp_legal_name VARCHAR(300)');

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
        $pdo = $db->getPdo();
        $stmt = $pdo->query("SELECT COUNT(*) FROM route_signatures WHERE kind = 'stamp'");
        $applied = 0;
        if ($stmt !== false) {
            $applied = (int) $stmt->fetchColumn();
            $stmt->closeCursor();
        }
        if ($applied > 0) {
            throw new RuntimeException(
                'Cannot roll back migration 331: company stamps have been applied, and the records are kept as evidence.'
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
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id)',
                [':name' => $name]
            );
        }

        $db->exec('ALTER TABLE route_signatures DROP COLUMN IF EXISTS stamp_legal_name');
        $db->exec('ALTER TABLE route_signatures DROP COLUMN IF EXISTS stamp_name');
        $db->exec('ALTER TABLE route_signatures DROP COLUMN IF EXISTS stamp_id');
        $db->exec('ALTER TABLE route_signatures DROP COLUMN IF EXISTS kind');
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS stamp_id');
        $db->exec('DROP TABLE IF EXISTS company_stamp_holders');
        $db->exec('DROP TABLE IF EXISTS company_stamps');
    }

    /** @return list<int> */
    private static function rolesHolding(Database $db, string $permission): array
    {
        $rows = $db->query(
            'SELECT rp.role_id
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :name',
            [':name' => $permission]
        )->fetchAll();

        return array_values(array_map(static fn (array $row): int => (int) $row['role_id'], $rows));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
