<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * DropCalendarAvailabilityExceptions — the old time-off table goes (merge map
 * T8, ADR 0018).
 *
 * Migration 285 copied every row of `calendar_availability_exceptions` into
 * `calendar_events` (kinds `time_off` and `open_hours`), and since then time off
 * and extra hours are read and written only there. Nothing reads the old table,
 * and with no customers to carry it goes in this release (owner, 2026-11-16).
 *
 * Run it after 285 has copied the rows. On app.whity.dev that copy ran with
 * T3. Idempotent; down() brings the table back empty.
 */
final class DropCalendarAvailabilityExceptions
{
    public static function up(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS calendar_availability_exceptions');
    }

    public static function down(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_availability_exceptions (
                id          SERIAL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                profile_id  INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                kind        VARCHAR(8)   NOT NULL,
                starts_at   TIMESTAMP    NOT NULL,
                ends_at     TIMESTAMP    NOT NULL,
                note        VARCHAR(500) NOT NULL DEFAULT \'\',
                created_by_profile_id INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_exceptions_owner ON calendar_availability_exceptions(tenant_id, profile_id, starts_at)');
    }
}
