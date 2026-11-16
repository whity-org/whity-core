<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateCalendar — staff calendars, their weekly hours, dated exceptions and
 * time off, and the events on them (WHIT-941, ADR 0017).
 *
 * `calendar_staff` is one row per person who has a calendar in a workspace. It
 * is also that person's LOCK ROW: every write that can take time on their
 * calendar first does `SELECT … FOR UPDATE` on it, then re-reads their busy
 * time inside the same transaction (ADR 0017, section 3). `feed_token_hash`
 * is the SHA-256 of their ICS feed token; the token itself is never stored.
 *
 * Working hours are LOCAL wall-clock `HH:MM` strings, not instants: they are
 * turned into UTC per local date, in the workspace's time zone, when
 * availability is computed, so the same row stays right across a clock change.
 * Every other time here is a UTC instant.
 *
 * Every table is tenant-owned. Each name is spelled out literally because
 * TenantOwnedTablesTest and CoreTablesTest re-derive their registries by
 * scanning this source.
 */
final class CreateCalendar
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_staff (
                id              SERIAL PRIMARY KEY,
                tenant_id       INTEGER      NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                profile_id      INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                bookable        BOOLEAN      NOT NULL DEFAULT TRUE,
                sort_order      INTEGER      NOT NULL DEFAULT 0,
                feed_token_hash CHAR(64),
                created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, profile_id)
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_calendar_staff_feed_token ON calendar_staff(feed_token_hash)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_working_hours (
                id          SERIAL PRIMARY KEY,
                tenant_id   INTEGER     NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                profile_id  INTEGER     NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                weekday     SMALLINT    NOT NULL,
                start_time  CHAR(5)     NOT NULL,
                end_time    CHAR(5)     NOT NULL,
                created_at  TIMESTAMP   NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_working_hours_owner ON calendar_working_hours(tenant_id, profile_id)');

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

        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_events (
                id            SERIAL PRIMARY KEY,
                tenant_id     INTEGER      NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                profile_id    INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                title         VARCHAR(200) NOT NULL,
                notes         TEXT         NOT NULL DEFAULT \'\',
                starts_at     TIMESTAMP    NOT NULL,
                ends_at       TIMESTAMP    NOT NULL,
                all_day       BOOLEAN      NOT NULL DEFAULT FALSE,
                busy          BOOLEAN      NOT NULL DEFAULT TRUE,
                status        VARCHAR(16)  NOT NULL DEFAULT \'confirmed\',
                external_ref  VARCHAR(255),
                created_by_profile_id INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                created_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_events_owner ON calendar_events(tenant_id, profile_id, starts_at)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS calendar_events');
        $db->exec('DROP TABLE IF EXISTS calendar_availability_exceptions');
        $db->exec('DROP TABLE IF EXISTS calendar_working_hours');
        $db->exec('DROP TABLE IF EXISTS calendar_staff');
    }
}
