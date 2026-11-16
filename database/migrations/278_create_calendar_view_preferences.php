<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateCalendarViewPreferences — what each person last chose on the
 * calendar, in each workspace (design 3.5, CV.1, CV.2, CV.4, CV.7).
 *
 * The view and the date range, Colour by (person, service or room), the
 * people and rooms they unticked, Also show, and whether they opened the
 * sidebar on the calendar. It only changes what that person sees: Colour by
 * is "for themselves only" (CV.4), so it is never a workspace setting.
 *
 * One row per person per workspace; `choices` is a JSON object the API
 * validates key by key, so an old client's unknown keys are dropped rather
 * than stored. Table name spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarViewPreferences
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_view_preferences (
                id          SERIAL PRIMARY KEY,
                tenant_id   INTEGER   NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                profile_id  INTEGER   NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                choices     TEXT      NOT NULL DEFAULT \'{}\',
                updated_at  TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, profile_id)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_view_preferences_tenant_id ON calendar_view_preferences(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS calendar_view_preferences');
    }
}
