<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantMigrationsPermissions migration (#990, the migrations group).
 *
 * Registers `migrations:read` and `migrations:manage` so the three migrations
 * routes can stop being gated on the `admin` ROLE NAME:
 *
 *   GET  /api/migrations           migrations:read    (HTTP and CLI)
 *   POST /api/migrations/run       migrations:manage  (CLI only)
 *   POST /api/migrations/rollback  migrations:manage  (CLI only)
 *
 * WHO GETS THEM
 * -------------
 * By capability, never by role name (#834, `ci-grant-by-role-name-guard`).
 *
 * `migrations:read` goes to every role holding `stats:read`. The server
 * statistics page already reports the migrations (migration 199 says so), so
 * whoever may read it may already see this list. Nobody gains anything.
 *
 * `migrations:manage` goes to the roles holding BOTH `stats:read` and
 * `plugins:install`. Running or rolling back a migration changes the schema;
 * installing a plugin already does that, through the plugin's own migrations.
 * Requiring `stats:read` as well keeps it on the roles a workspace does not own,
 * which migration 199 left as the only holders of that slug.
 *
 * On a fresh install the seeded global `admin` holds both anchors, as does the
 * CLI service principal that carries it, so nobody who could reach these routes
 * by role name loses them. If no role holds an anchor, only the catalogue row
 * is written; `ci-permission-holder-guard` reports a gated slug held by nobody.
 *
 * Idempotent and reversible.
 */
final class GrantMigrationsPermissions
{
    /** @var array<string, list<string>> slug => every permission a role must already hold */
    private const AUDIENCES = [
        CorePermissions::MIGRATIONS_READ => [CorePermissions::STATS_READ],
        CorePermissions::MIGRATIONS_MANAGE => [CorePermissions::STATS_READ, CorePermissions::PLUGINS_INSTALL],
    ];

    /** @var array<string, string> */
    private const DESCRIPTIONS = [
        CorePermissions::MIGRATIONS_READ => 'See which database migrations have run',
        CorePermissions::MIGRATIONS_MANAGE => 'Run and roll back database migrations from the command line',
    ];

    public static function up(Database $db): void
    {
        foreach (self::AUDIENCES as $name => $anchors) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => self::DESCRIPTIONS[$name]]
            );

            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            foreach (self::rolesHoldingAll($db, $anchors) as $roleId) {
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
        foreach (array_keys(self::AUDIENCES) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            $db->query('DELETE FROM role_permissions WHERE permission_id = :id', [':id' => $permissionId]);
            $db->query('DELETE FROM permissions WHERE id = :id', [':id' => $permissionId]);
        }
    }

    /**
     * Role ids holding every one of the given permissions.
     *
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAll(Database $db, array $permissions): array
    {
        $roles = null;
        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();

            $holders = $rows === false ? [] : array_map(static fn (array $row): int => (int) $row['role_id'], $rows);
            $roles = $roles === null ? $holders : array_values(array_intersect($roles, $holders));
        }

        return $roles ?? [];
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $row = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $row === false ? null : (int) $row['id'];
    }
}
