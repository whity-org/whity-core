<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddApprovalRequestReasons — why somebody is asking (design 2.9, A16 and A48).
 *
 * The approval page shows what a person asked and why, and an approver deciding
 * whether to strip somebody's second step needs the why. The two self-service
 * requests had nowhere to put one.
 *
 * Optional, and written only when the person confirms from the emailed link, so
 * the text comes from whoever controls that mailbox and never from an anonymous
 * caller. Sign-ups have no column here: what they ask for is the workspace, which
 * is already on the row.
 *
 * Additive and idempotent; down() drops the two columns.
 */
final class AddApprovalRequestReasons
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE password_resets ADD COLUMN IF NOT EXISTS reason VARCHAR(500)');
        $db->exec('ALTER TABLE two_factor_recovery_requests ADD COLUMN IF NOT EXISTS reason VARCHAR(500)');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE two_factor_recovery_requests DROP COLUMN IF EXISTS reason');
        $db->exec('ALTER TABLE password_resets DROP COLUMN IF EXISTS reason');
    }
}
