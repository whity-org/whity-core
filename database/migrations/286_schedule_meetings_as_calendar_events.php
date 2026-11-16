<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\Convening\MeetingCalendar;
use Whity\Database\Database;

/**
 * ScheduleMeetingsAsCalendarEvents — meetings on the calendar (ADR 0018,
 * WHIT-1112).
 *
 * Every meeting that has a date gets its calendar event of kind `meeting`,
 * written by the same {@see MeetingCalendar::sync()} the app uses from now on:
 *  - start at `scheduled_at`, lasting 60 minutes (owner, 16 Nov 2026);
 *  - its place, and its invitations as the event's people, with their answers;
 *  - cancelled when the meeting was.
 *
 * Drafts have no date and get nothing. Nothing in Convening is changed.
 * `meeting_invitations`, `scheduled_at` and `location` stay the meeting's record,
 * and the event mirrors them.
 *
 * SAFE TO RE-RUN. sync() finds an existing event by `meeting_id` (unique,
 * migration 284) and updates it rather than adding a second. On
 * app.whity.dev there are no meetings today.
 */
final class ScheduleMeetingsAsCalendarEvents
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();
        $calendar = new MeetingCalendar($pdo);
        $rows = $pdo->query("SELECT tenant_id, id FROM meetings WHERE scheduled_at IS NOT NULL AND status <> 'draft' ORDER BY id");
        if ($rows === false) {
            return;
        }
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $calendar->sync((int) $row['tenant_id'], (int) $row['id']);
        }
    }

    public static function down(Database $db): void
    {
        $db->exec("DELETE FROM calendar_events WHERE kind = 'meeting' AND meeting_id IS NOT NULL");
    }
}
