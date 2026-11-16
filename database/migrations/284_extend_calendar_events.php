<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * ExtendCalendarEvents — one calendar for everything with a time (ADR 0018,
 * WHIT-1109, issue #1933).
 *
 * `calendar_events` becomes the one events table: a `kind` says what an event
 * is (a team event or everyday meeting, a formal meeting, time off, or one
 * synced from an outside calendar), and `calendar_event_people` holds who is
 * invited and how they answered. Bookings keep their own table and join the
 * calendar through the read model ({@see \Whity\Core\Calendar\CalendarItems}).
 *
 * The columns later steps fill are added here in one go, so those steps only
 * copy rows: `meeting_id` (T4), `legacy_ref` (a copied row's origin, unique per
 * workspace so a copy can be re-run; T3, T4), `ics_uid` / `ics_sequence` (T5)
 * and the reminder pair (T5).
 *
 * Existing rows: every event is `kind = 'event'`, except those a sync plugin
 * wrote (`external_ref` set), which become `external`. No row is rewritten in
 * any other way. Table names spelled out literally for TenantOwnedTablesTest
 * and CoreTablesTest. Idempotent and reversible.
 */
final class ExtendCalendarEvents
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS kind VARCHAR(16) NOT NULL DEFAULT 'event'");
        $db->exec('ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS room_id INTEGER REFERENCES calendar_rooms(id) ON DELETE SET NULL');
        $db->exec("ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS location VARCHAR(255) NOT NULL DEFAULT ''");
        $db->exec('ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS meeting_id BIGINT REFERENCES meetings(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS legacy_ref VARCHAR(64)');
        $db->exec('ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS ics_uid VARCHAR(255)');
        $db->exec('ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS ics_sequence INTEGER NOT NULL DEFAULT 0');
        $db->exec('ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS reminder_due_at TIMESTAMP');
        $db->exec('ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS reminder_sent_at TIMESTAMP');

        $db->exec("UPDATE calendar_events SET kind = 'external' WHERE external_ref IS NOT NULL AND kind = 'event'");

        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_calendar_events_legacy_ref ON calendar_events(tenant_id, legacy_ref) WHERE legacy_ref IS NOT NULL');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_calendar_events_meeting ON calendar_events(meeting_id) WHERE meeting_id IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_events_room ON calendar_events(tenant_id, room_id, starts_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_events_range ON calendar_events(tenant_id, starts_at)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_event_people (
                id           SERIAL PRIMARY KEY,
                tenant_id    INTEGER     NOT NULL REFERENCES tenants(id)          ON DELETE CASCADE,
                event_id     INTEGER     NOT NULL REFERENCES calendar_events(id)  ON DELETE CASCADE,
                profile_id   INTEGER     NOT NULL REFERENCES profiles(id)         ON DELETE CASCADE,
                role         VARCHAR(12) NOT NULL DEFAULT 'required',
                response     VARCHAR(12) NOT NULL DEFAULT 'invited',
                responded_at TIMESTAMP,
                created_at   TIMESTAMP   NOT NULL DEFAULT NOW(),
                UNIQUE (event_id, profile_id)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_event_people_person ON calendar_event_people(tenant_id, profile_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS calendar_event_people');
        $db->exec('DROP INDEX IF EXISTS idx_calendar_events_range');
        $db->exec('DROP INDEX IF EXISTS idx_calendar_events_room');
        $db->exec('DROP INDEX IF EXISTS idx_calendar_events_meeting');
        $db->exec('DROP INDEX IF EXISTS idx_calendar_events_legacy_ref');
        foreach (['reminder_sent_at', 'reminder_due_at', 'ics_sequence', 'ics_uid', 'legacy_ref', 'meeting_id', 'location', 'room_id', 'kind'] as $column) {
            $db->exec("ALTER TABLE calendar_events DROP COLUMN IF EXISTS {$column}");
        }
    }
}
