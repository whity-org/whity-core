<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * UniqueTimeOffExternalRef (SDK 1.120, WHIT-1210, issue #2132): a plugin's
 * reference names at most one live time off per workspace.
 *
 * {@see \Whity\Sdk\Calendar\TimeOff::create()} is idempotent by the plugin's
 * `externalRef` (`keyhub.leave:123`). The host looks first, but two retries
 * at once could both miss, so the database keeps the promise: confirmed
 * `time_off` events with a reference are unique per workspace. Cancelled time
 * off frees its reference. Sync plugins' `external` events are not touched:
 * they are keyed per person and may repeat a reference across people.
 *
 * Existing time off has no reference (it came from the time-off route and
 * migration 285), so nothing can collide. Safe to run twice; reversible.
 */
final class UniqueTimeOffExternalRef
{
    public static function up(Database $db): void
    {
        $db->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_calendar_events_time_off_ref
                 ON calendar_events(tenant_id, external_ref)
              WHERE kind = 'time_off' AND status = 'confirmed' AND external_ref IS NOT NULL"
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_calendar_events_time_off_ref');
    }
}
