<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * MoveTimeOffIntoCalendarEvents — time off is an event (ADR 0018, WHIT-1110).
 *
 * Each row of `calendar_availability_exceptions` is copied into
 * `calendar_events`:
 *  - `off` becomes kind `time_off`: busy, titled with its note, or "Time off"
 *    when it has none;
 *  - `open` (extra working hours) becomes kind `open_hours`: not busy, and never
 *    shown on the calendar. It only widens the person's hours.
 *
 * The time-off API then reads and writes events, so days off and extra hours
 * share one table and one id space.
 *
 * SAFE TO RE-RUN. A copied row carries `legacy_ref = 'exception:<id>'` (unique
 * per workspace, migration 284), and a row is copied only when no event has its
 * ref. The old table is left as it is, no longer read, and is dropped one
 * release later (ADR 0018, T8). On app.whity.dev it holds no rows today.
 */
final class MoveTimeOffIntoCalendarEvents
{
    public static function up(Database $db): void
    {
        $db->exec("
            INSERT INTO calendar_events
                (tenant_id, profile_id, title, notes, starts_at, ends_at, all_day, busy, status, kind, legacy_ref, created_by_profile_id, created_at, updated_at)
            SELECT x.tenant_id,
                   x.profile_id,
                   CASE WHEN x.note <> '' THEN SUBSTR(x.note, 1, 200)
                        WHEN x.kind = 'open' THEN 'Extra hours'
                        ELSE 'Time off' END,
                   x.note,
                   x.starts_at,
                   x.ends_at,
                   FALSE,
                   CASE WHEN x.kind = 'open' THEN FALSE ELSE TRUE END,
                   'confirmed',
                   CASE WHEN x.kind = 'open' THEN 'open_hours' ELSE 'time_off' END,
                   'exception:' || CAST(x.id AS VARCHAR(20)),
                   x.created_by_profile_id,
                   x.created_at,
                   x.created_at
              FROM calendar_availability_exceptions x
             WHERE NOT EXISTS (
                   SELECT 1 FROM calendar_events e
                    WHERE e.tenant_id = x.tenant_id
                      AND e.legacy_ref = 'exception:' || CAST(x.id AS VARCHAR(20)))
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec("DELETE FROM calendar_events WHERE legacy_ref LIKE 'exception:%'");
    }
}
