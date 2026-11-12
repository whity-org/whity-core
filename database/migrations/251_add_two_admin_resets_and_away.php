<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Two admins for two-step resets, and "I'm away" (A7; AG.1, OR.1).
 *
 * two_factor_recovery_requests gains the first admin's approval (who, when,
 * how they confirmed it was them), the checks admins made that it is really
 * the person (a call to the number on file, the manager's word), and where the
 * request came from. A request is cleared only by a second, different admin;
 * neither can be the person asking.
 *
 * away_periods holds a person's "I'm away": the dates, who decides for them,
 * what goes to that person, and the message for people who write. Ending it
 * early sets ended_at. Tenant-owned.
 */
final class AddTwoAdminResetsAndAway
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE two_factor_recovery_requests ADD COLUMN IF NOT EXISTS first_approver_profile_id INTEGER REFERENCES profiles(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE two_factor_recovery_requests ADD COLUMN IF NOT EXISTS first_approved_at TIMESTAMP');
        $db->exec('ALTER TABLE two_factor_recovery_requests ADD COLUMN IF NOT EXISTS first_approval_method VARCHAR(24)');
        $db->exec('ALTER TABLE two_factor_recovery_requests ADD COLUMN IF NOT EXISTS checks TEXT');
        $db->exec('ALTER TABLE two_factor_recovery_requests ADD COLUMN IF NOT EXISTS requested_from VARCHAR(255)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS away_periods (
                id                  BIGSERIAL    PRIMARY KEY,
                tenant_id           INTEGER      NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                profile_id          INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                delegate_profile_id INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                starts_on           DATE         NOT NULL,
                back_on             DATE         NOT NULL,
                covers              TEXT         NOT NULL,
                message             TEXT,
                ended_at            TIMESTAMP,
                created_at          TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_away_periods_tenant_profile ON away_periods (tenant_id, profile_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_away_periods_tenant_delegate ON away_periods (tenant_id, delegate_profile_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS away_periods');
        foreach (['requested_from', 'checks', 'first_approval_method', 'first_approved_at', 'first_approver_profile_id'] as $column) {
            $db->exec('ALTER TABLE two_factor_recovery_requests DROP COLUMN IF EXISTS ' . $column);
        }
    }
}
