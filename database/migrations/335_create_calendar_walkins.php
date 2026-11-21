<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateCalendarWalkins — the walk-in queue at the front desk (WHIT-1334,
 * design section 35 §3, register SL.2-SL.4), moved from the Salons plugin's
 * `salons_walkins`.
 *
 * - `calendar_walkins`: someone who came in without a booking, waiting for a
 *   member of staff. It holds only the wait: once served, the booking (which
 *   found or added the contact) and the contact are kept by id. Status
 *   `waiting`, `assigning` (one desk has claimed it), `served` or `left`;
 *   rows are never deleted. `legacy_id` is the `salons_walkins.id` a row was
 *   copied from (section 35 §6), unique per workspace, null for core's own.
 *
 * Grants `calendar:front_desk` by capability to every role holding both
 * `calendar:write` and `contacts:write`, since handing someone over books and
 * adds the contact as that person (ci-grant-by-role-name-guard).
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarWalkins
{
    private const PERMISSION = CorePermissions::CALENDAR_FRONT_DESK;
    private const DESCRIPTION = 'Add walk-ins to the queue at the front desk and hand them to staff';

    /** A role gets the permission only when it holds every one of these. */
    private const AUDIENCE = [CorePermissions::CALENDAR_WRITE, CorePermissions::CONTACTS_WRITE];

    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_walkins (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name                  VARCHAR(200)  NOT NULL,
                phone                 VARCHAR(40)   NOT NULL DEFAULT \'\',
                service_id            INTEGER       NOT NULL REFERENCES calendar_services(id),
                preferred_profile_id  INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                note                  VARCHAR(500)  NOT NULL DEFAULT \'\',
                status                VARCHAR(16)   NOT NULL DEFAULT \'waiting\',
                added_at              TIMESTAMP     NOT NULL,
                added_by_profile_id   INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                assigned_at           TIMESTAMP,
                assigned_profile_id   INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                booking_id            INTEGER                REFERENCES calendar_bookings(id) ON DELETE SET NULL,
                contact_id            INTEGER                REFERENCES contacts(id) ON DELETE SET NULL,
                left_at               TIMESTAMP,
                -- @reference-lint-ignore: the id the row had in the retired Salons plugin table it was copied from
                legacy_id             INTEGER
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_walkins_queue ON calendar_walkins(tenant_id, status, added_at)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_calendar_walkins_legacy ON calendar_walkins(tenant_id, legacy_id) WHERE legacy_id IS NOT NULL');

        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [':name' => self::PERMISSION, ':description' => self::DESCRIPTION]
        );
        $permissionId = self::permissionId($db, self::PERMISSION);
        if ($permissionId !== null) {
            foreach (self::rolesHoldingAll($db, self::AUDIENCE) as $roleId) {
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
        $permissionId = self::permissionId($db, self::PERMISSION);
        if ($permissionId !== null) {
            foreach (self::rolesHoldingAll($db, self::AUDIENCE) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
            $db->query(
                'DELETE FROM permissions
                  WHERE name = :name
                    AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id)',
                [':name' => self::PERMISSION]
            );
        }

        $db->exec('DROP TABLE IF EXISTS calendar_walkins');
    }

    /**
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAll(Database $db, array $permissions): array
    {
        $holders = null;
        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();
            $ids = array_values(array_map(static fn (array $r): int => (int) $r['role_id'], $rows));
            $holders = $holders === null ? $ids : array_values(array_intersect($holders, $ids));
        }

        return $holders ?? [];
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
