<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateCalendarCommissionCloses — closing a commission period by hand
 * (WHIT-1334, design section 35 §4.3, register SL.6; owner, Q3, "Accept all").
 *
 * - `calendar_commission_closes`: one closed period, its first and last day in
 *   the workspace's time zone (both included), who closed it and when, and the
 *   reference the books gave it (`expense_ref`, null until booked).
 * - `calendar_commission_close_lines`: the frozen totals, one per member of
 *   staff and currency, so a later price or rate change never moves a closed
 *   period.
 *
 * Periods never overlap: core refuses a close that touches a closed day. Rows
 * are never changed after the close except `expense_ref`.
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarCommissionCloses
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_commission_closes (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                period_start          DATE         NOT NULL,
                period_end            DATE         NOT NULL,
                closed_by_profile_id  INTEGER               REFERENCES profiles(id) ON DELETE SET NULL,
                closed_at             TIMESTAMP    NOT NULL,
                expense_ref           VARCHAR(100),
                booked_at             TIMESTAMP,
                CHECK (period_end >= period_start)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_commission_closes_period ON calendar_commission_closes(tenant_id, period_start, period_end)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_commission_close_lines (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id)                    ON DELETE CASCADE,
                close_id              INTEGER      NOT NULL REFERENCES calendar_commission_closes(id) ON DELETE CASCADE,
                profile_id            INTEGER      NOT NULL REFERENCES profiles(id)                   ON DELETE RESTRICT,
                currency              VARCHAR(3)   NOT NULL DEFAULT \'\',
                bookings              INTEGER      NOT NULL DEFAULT 0,
                sales_minor           BIGINT       NOT NULL DEFAULT 0,
                commission_minor      BIGINT       NOT NULL DEFAULT 0,
                UNIQUE (tenant_id, close_id, profile_id, currency)
            )
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS calendar_commission_close_lines');
        $db->exec('DROP TABLE IF EXISTS calendar_commission_closes');
    }
}
