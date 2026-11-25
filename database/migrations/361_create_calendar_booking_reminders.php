<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;
use Whity\Database\Migration\SafeIndex;

/**
 * CreateCalendarBookingReminders — reminders always go out (WHIT-1417,
 * design section 39 §5.2, register AP.14–AP.15).
 *
 * 1. `calendar_booking_reminders`: one row for each reminder sent on each
 *    channel, with what happened (`sent`, `failed`, `no_address`), so the
 *    desk can say "Reminder sent 09:00 by WhatsApp" or "Reminder failed: no
 *    phone". `hours_before` says which reminder it was (24 or 2 by default).
 *
 * 2. `calendar_bookings.confirmed_by_customer_at`: set when the customer
 *    presses I'm coming on their booking page.
 *
 * 3. An index for the sweep: confirmed bookings by `reminder_due_at`. The
 *    sweep runs every 5 minutes across every workspace.
 *
 * 4. `calendar.reminders` replaces `calendar.reminder_hours_before`: a
 *    workspace that chose its own hours keeps that one reminder (0 = none);
 *    the rest get the new default, 24 and 2 hours before. The old override
 *    is left in place (nothing reads it any more), so down() loses nothing.
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarBookingReminders
{
    public static function up(Database $db): void
    {
        $driver = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $db->exec('
                CREATE TABLE IF NOT EXISTS calendar_booking_reminders (
                    id            BIGSERIAL PRIMARY KEY,
                    tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    booking_id    INTEGER NOT NULL REFERENCES calendar_bookings(id) ON DELETE CASCADE,
                    hours_before  INTEGER NOT NULL,
                    channel       VARCHAR(40) NOT NULL,
                    outcome       VARCHAR(16) NOT NULL,
                    sent_at       TIMESTAMP NOT NULL
                )
            ');
            $db->exec('ALTER TABLE calendar_bookings ADD COLUMN IF NOT EXISTS confirmed_by_customer_at TIMESTAMP');
        } else {
            $db->exec('
                CREATE TABLE IF NOT EXISTS calendar_booking_reminders (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    booking_id    INTEGER NOT NULL REFERENCES calendar_bookings(id) ON DELETE CASCADE,
                    hours_before  INTEGER NOT NULL,
                    channel       VARCHAR(40) NOT NULL,
                    outcome       VARCHAR(16) NOT NULL,
                    sent_at       TIMESTAMP NOT NULL
                )
            ');
            if (!self::sqliteHasColumn($db, 'calendar_bookings', 'confirmed_by_customer_at')) {
                // migration-lint: allow not-idempotent (guarded by sqliteHasColumn: SQLite has no ADD COLUMN IF NOT EXISTS)
                $db->exec('ALTER TABLE calendar_bookings ADD COLUMN confirmed_by_customer_at TIMESTAMP');
            }
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_booking_reminders_booking ON calendar_booking_reminders(tenant_id, booking_id)');
        SafeIndex::create($db, 'idx_calendar_bookings_reminder_due', 'calendar_bookings', 'reminder_due_at', where: "status = 'confirmed'");

        // A workspace's own reminder hours carry over as its one reminder.
        // migration-lint: allow unbatched-backfill (one row per workspace that changed the setting; a handful)
        $db->exec("
            INSERT INTO tenant_settings (tenant_id, setting_key, value)
            SELECT tenant_id, 'calendar.reminders',
                   CASE WHEN TRIM(COALESCE(value, '')) IN ('', '0') THEN '' ELSE TRIM(value) END
              FROM tenant_settings old
             WHERE old.setting_key = 'calendar.reminder_hours_before'
               AND NOT EXISTS (
                   SELECT 1 FROM tenant_settings n
                    WHERE n.tenant_id = old.tenant_id AND n.setting_key = 'calendar.reminders'
               )
        ");
    }

    public static function down(Database $db): void
    {
        $driver = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec("DELETE FROM tenant_settings WHERE setting_key = 'calendar.reminders'");
        $db->exec('DROP INDEX IF EXISTS idx_calendar_bookings_reminder_due');
        $db->exec('DROP TABLE IF EXISTS calendar_booking_reminders');
        if ($driver === 'pgsql') {
            $db->exec('ALTER TABLE calendar_bookings DROP COLUMN IF EXISTS confirmed_by_customer_at');

            return;
        }
        if (self::sqliteHasColumn($db, 'calendar_bookings', 'confirmed_by_customer_at')) {
            $db->exec('ALTER TABLE calendar_bookings DROP COLUMN confirmed_by_customer_at');
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
