<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateTwoFactorReminders — when somebody was last reminded to set up two-step
 * sign-in, so Settings > Security can show it and refuse to send a second one
 * the same day.
 *
 * One row per person per workspace, which is what makes the limit hold against a
 * double click: the send is an update that only matches a row older than the
 * window. `reminded_by` goes NULL if that admin's profile is deleted, because the
 * audit log is the record of who did it.
 *
 * Additive and idempotent; down() drops the table.
 */
final class CreateTwoFactorReminders
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS two_factor_reminders (
                tenant_id   INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                profile_id  INTEGER   NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                reminded_at TIMESTAMP NOT NULL,
                reminded_by INTEGER   REFERENCES profiles(id) ON DELETE SET NULL,
                PRIMARY KEY (tenant_id, profile_id)
            )
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS two_factor_reminders');
    }
}
