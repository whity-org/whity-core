<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * AddCalendarStaffAndTime — staff and time (WHIT design section 39 §5.3,
 * §5.4 and §7.1, register AP.16, AP.17 and AP.19).
 *
 * 1. `calendar_service_staff` gains `duration_minutes`, `buffer_after_minutes`
 *    and `price_minor`, all nullable: what this person's version of the
 *    service takes and costs. Empty means the service's own value. A booking
 *    already keeps what it used: its end, its block and its price.
 *
 * 2. `organizational_units.time_zone` (IANA, nullable): a branch's own zone.
 *    Without one, the workspace's zone applies, as it always has.
 *
 * 3. `calendar_busy_check_failures`: an external calendar that could not be
 *    read for a person, when, and from which source. Booking stays open; the
 *    desk and the person are told. Short-lived: a daily job deletes rows
 *    older than seven days, so the table never grows.
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class AddCalendarStaffAndTime
{
    private const STAFF_COLUMNS = [
        'duration_minutes' => 'INTEGER',
        'buffer_after_minutes' => 'INTEGER',
        'price_minor' => 'BIGINT',
    ];

    public static function up(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        foreach (self::STAFF_COLUMNS as $column => $type) {
            self::addColumn($db, $pgsql, 'calendar_service_staff', $column, $type);
        }
        self::addColumn($db, $pgsql, 'organizational_units', 'time_zone', 'VARCHAR(64)');

        $id = $pgsql ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_busy_check_failures (
                id          {$id},
                tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                profile_id  INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                source      VARCHAR(120) NOT NULL,
                failed_at   TIMESTAMP NOT NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_busy_check_failures_when ON calendar_busy_check_failures(tenant_id, failed_at)');
    }

    public static function down(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $db->exec('DROP TABLE IF EXISTS calendar_busy_check_failures');
        self::dropColumn($db, $pgsql, 'organizational_units', 'time_zone');
        foreach (array_reverse(array_keys(self::STAFF_COLUMNS)) as $column) {
            self::dropColumn($db, $pgsql, 'calendar_service_staff', $column);
        }
    }

    private static function addColumn(Database $db, bool $pgsql, string $table, string $column, string $type): void
    {
        if ($pgsql) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$type}");
        } elseif (!self::sqliteHasColumn($db, $table, $column)) {
            // migration-lint: allow not-idempotent (guarded by sqliteHasColumn: SQLite has no ADD COLUMN IF NOT EXISTS)
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
        }
    }

    private static function dropColumn(Database $db, bool $pgsql, string $table, string $column): void
    {
        if ($pgsql) {
            $db->exec("ALTER TABLE {$table} DROP COLUMN IF EXISTS {$column}");
        } elseif (self::sqliteHasColumn($db, $table, $column)) {
            $db->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
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
