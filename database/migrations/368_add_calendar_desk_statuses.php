<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * AddCalendarDeskStatuses — the front desk (WHIT-1441, design section 39 §10,
 * register AP.27 and AP.29).
 *
 * `calendar_bookings` gains what the desk records during a visit:
 *  - `started_at`: the staff member started (With staff);
 *  - `finished_at`: the visit is done (Done);
 *  - `late_minutes` and `late_marked_at`: the customer said they are running
 *    late by this many minutes. It is shown on the booking and moves nothing.
 * Arrived stays `checked_in_at` and Didn't come stays `attendance` (M3).
 * Nothing existing is guessed: every new column starts empty.
 *
 * Idempotent and reversible.
 */
final class AddCalendarDeskStatuses
{
    private const COLUMNS = [
        'started_at' => 'TIMESTAMP',
        'finished_at' => 'TIMESTAMP',
        'late_minutes' => 'INTEGER',
        'late_marked_at' => 'TIMESTAMP',
    ];

    public static function up(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        foreach (self::COLUMNS as $column => $type) {
            if ($pgsql) {
                $db->exec("ALTER TABLE calendar_bookings ADD COLUMN IF NOT EXISTS {$column} {$type}");
            } elseif (!self::sqliteHasColumn($db, $column)) {
                // migration-lint: allow not-idempotent (guarded by sqliteHasColumn: SQLite has no ADD COLUMN IF NOT EXISTS)
                $db->exec("ALTER TABLE calendar_bookings ADD COLUMN {$column} {$type}");
            }
        }
    }

    public static function down(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if ($pgsql) {
                $db->exec("ALTER TABLE calendar_bookings DROP COLUMN IF EXISTS {$column}");
            } elseif (self::sqliteHasColumn($db, $column)) {
                $db->exec("ALTER TABLE calendar_bookings DROP COLUMN {$column}");
            }
        }
    }

    private static function sqliteHasColumn(Database $db, string $column): bool
    {
        foreach ($db->query('PRAGMA table_info(calendar_bookings)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
