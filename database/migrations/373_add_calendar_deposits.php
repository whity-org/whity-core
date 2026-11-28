<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * AddCalendarDeposits — deposits and the cancellation policy (WHIT-1445,
 * design section 39 §9, register AP.25 and AP.26).
 *
 * 1. `calendar_services` gains its deposit: `deposit_kind` (`none`, `fixed`
 *    or `percent`) and `deposit_value` (the amount in minor units for a fixed
 *    deposit, or 1 to 100 for a percentage of the price). Every service starts
 *    with none.
 * 2. `calendar_bookings` gains what happened to its deposit:
 *    - `deposit_minor`: the deposit asked for, in the booking's currency;
 *    - `deposit_paid_at`: when the payment step confirmed it;
 *    - `deposit_refunded_at`, `deposit_refunded_by_profile_id` and
 *      `deposit_refund_ref`: the refund a person pressed.
 *    Whether it is kept, to refund or applied is worked out from the booking
 *    (its cancel and whether they came), never stored twice.
 * 3. `calendar_bookings.late_moved_at`: the customer moved it inside the
 *    cancellation window, which they may do once online (§9.2).
 *
 * Nothing existing is guessed: every new booking column starts empty.
 * Idempotent and reversible.
 */
final class AddCalendarDeposits
{
    private const SERVICE_COLUMNS = [
        'deposit_kind' => "VARCHAR(8) NOT NULL DEFAULT 'none'",
        'deposit_value' => 'INTEGER NOT NULL DEFAULT 0',
    ];

    private const BOOKING_COLUMNS = [
        'deposit_minor' => 'INTEGER',
        'deposit_paid_at' => 'TIMESTAMP',
        'deposit_refunded_at' => 'TIMESTAMP',
        'deposit_refunded_by_profile_id' => 'INTEGER REFERENCES profiles(id) ON DELETE SET NULL',
        'deposit_refund_ref' => 'VARCHAR(200)',
        'late_moved_at' => 'TIMESTAMP',
    ];

    public static function up(Database $db): void
    {
        self::add($db, 'calendar_services', self::SERVICE_COLUMNS);
        self::add($db, 'calendar_bookings', self::BOOKING_COLUMNS);
    }

    public static function down(Database $db): void
    {
        self::drop($db, 'calendar_bookings', array_keys(self::BOOKING_COLUMNS));
        self::drop($db, 'calendar_services', array_keys(self::SERVICE_COLUMNS));
    }

    /** @param array<string, string> $columns */
    private static function add(Database $db, string $table, array $columns): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        foreach ($columns as $column => $type) {
            if ($pgsql) {
                // migration-lint: allow constraint-not-valid (a nullable foreign key on a new, empty column: there is nothing to check)
                $db->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$type}");
            } elseif (!self::sqliteHasColumn($db, $table, $column)) {
                // migration-lint: allow not-idempotent (guarded by sqliteHasColumn: SQLite has no ADD COLUMN IF NOT EXISTS)
                $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
            }
        }
    }

    /** @param list<string> $columns */
    private static function drop(Database $db, string $table, array $columns): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        foreach (array_reverse($columns) as $column) {
            if ($pgsql) {
                $db->exec("ALTER TABLE {$table} DROP COLUMN IF EXISTS {$column}");
            } elseif (self::sqliteHasColumn($db, $table, $column)) {
                $db->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
            }
        }
    }

    private static function sqliteHasColumn(Database $db, string $table, string $column): bool
    {
        $stmt = $db->query("PRAGMA table_info({$table})");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
