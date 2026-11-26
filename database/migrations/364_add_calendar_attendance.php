<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * AddCalendarAttendance — did they come (WHIT-1427, design section 39 §4,
 * register AP.2–AP.5, AP.8–AP.10).
 *
 * 1. `calendar_bookings` gains what happened to a booking:
 *    - `attendance`: null, `attended` or `no_show`;
 *    - `attendance_at`, `attendance_by_profile_id` and `attendance_auto` (true
 *      when Whity marked it at the end of the day): who marked it and when;
 *    - `cancel_kind`: `early`, `late` or `business` for a cancelled booking.
 *    Existing data: a booking that was checked in is `attended` (at its
 *    check-in), and one the customer cancelled on their link is `early` or
 *    `late` by the default window, 24 hours. Nothing else is guessed.
 *
 * 2. `calendar_no_show_resets`: "Start again from today" for a customer's
 *    count (AP.10), who and when. A customer is their contact (`c:<id>`) or,
 *    for a booking with no contact, their email (`e:<email>`).
 *
 * 3. `calendar:reports` (AP.9): who sees the rates. Granted by capability to
 *    every role holding `calendar:manage`, never by role name.
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class AddCalendarAttendance
{
    private const PERMISSION = CorePermissions::CALENDAR_REPORTS;
    private const DESCRIPTION = 'See appointment reports: no-show and late-cancel rates';

    /** A role gets the permission only when it holds every one of these. */
    private const AUDIENCE = [CorePermissions::CALENDAR_MANAGE];

    private const COLUMNS = [
        'attendance' => 'VARCHAR(16)',
        'attendance_at' => 'TIMESTAMP',
        'attendance_by_profile_id' => 'INTEGER REFERENCES profiles(id) ON DELETE SET NULL',
        'attendance_auto' => 'BOOLEAN NOT NULL DEFAULT FALSE',
        'cancel_kind' => 'VARCHAR(16)',
    ];

    public static function up(Database $db): void
    {
        $driver = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (self::COLUMNS as $column => $type) {
            if ($driver === 'pgsql') {
                // migration-lint: allow constraint-not-valid (a nullable foreign key on new, empty column: there is nothing to check)
                $db->exec("ALTER TABLE calendar_bookings ADD COLUMN IF NOT EXISTS {$column} {$type}");
            } elseif (!self::sqliteHasColumn($db, 'calendar_bookings', $column)) {
                // migration-lint: allow not-idempotent (guarded by sqliteHasColumn: SQLite has no ADD COLUMN IF NOT EXISTS)
                $db->exec("ALTER TABLE calendar_bookings ADD COLUMN {$column} {$type}");
            }
        }

        $id = $driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_no_show_resets (
                id                     {$id},
                tenant_id              INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                customer_key           VARCHAR(320) NOT NULL,
                cleared_at             TIMESTAMP NOT NULL,
                cleared_by_profile_id  INTEGER REFERENCES profiles(id) ON DELETE SET NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_no_show_resets_customer ON calendar_no_show_resets(tenant_id, customer_key)');

        // What is already known. Only rows that say nothing yet are touched.
        // migration-lint: allow unbatched-backfill (only checked-in bookings with no attendance; the column was added above and is empty)
        $db->exec("
            UPDATE calendar_bookings SET attendance = 'attended', attendance_at = checked_in_at
             WHERE attendance IS NULL AND checked_in_at IS NOT NULL
        ");
        $late = $driver === 'pgsql'
            ? "cancelled_at > starts_at - INTERVAL '24 hours'"
            : "cancelled_at > datetime(starts_at, '-24 hours')";
        // migration-lint: allow unbatched-backfill (only bookings the customer cancelled, with no kind yet)
        $db->exec("
            UPDATE calendar_bookings
               SET cancel_kind = CASE WHEN {$late} THEN 'late' ELSE 'early' END
             WHERE status = 'cancelled' AND cancelled_by = 'customer' AND cancel_kind IS NULL AND cancelled_at IS NOT NULL
        ");

        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, CURRENT_TIMESTAMP)
             ON CONFLICT (name) DO NOTHING',
            [':name' => self::PERMISSION, ':description' => self::DESCRIPTION]
        );
        $permissionId = self::permissionId($db, self::PERMISSION);
        if ($permissionId !== null) {
            foreach (self::rolesHoldingAll($db, self::AUDIENCE) as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, CURRENT_TIMESTAMP)
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
            $db->query('DELETE FROM role_permissions WHERE permission_id = :permission_id', [':permission_id' => $permissionId]);
            $db->query('DELETE FROM permissions WHERE id = :id', [':id' => $permissionId]);
        }
        $db->exec('DROP TABLE IF EXISTS calendar_no_show_resets');
        $driver = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if ($driver === 'pgsql') {
                $db->exec("ALTER TABLE calendar_bookings DROP COLUMN IF EXISTS {$column}");
            } elseif (self::sqliteHasColumn($db, 'calendar_bookings', $column)) {
                $db->exec("ALTER TABLE calendar_bookings DROP COLUMN {$column}");
            }
        }
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $row = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) $row['id'] : null;
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
                'SELECT rp.role_id FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll(PDO::FETCH_COLUMN);
            $ids = array_map('intval', $rows);
            $holders = $holders === null ? $ids : array_values(array_intersect($holders, $ids));
        }

        return $holders ?? [];
    }

    private static function sqliteHasColumn(Database $db, string $table, string $column): bool
    {
        foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
