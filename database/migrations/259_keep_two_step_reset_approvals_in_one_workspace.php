<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Two-step resets: both approvals come from one workspace, and requests have a
 * lifetime (AG.1).
 *
 * `first_approver_tenant_id` records the workspace the first approval was
 * given in; the second approval must be given in the same workspace.
 *
 * Existing pending requests: a first approval given before the workspace was
 * recorded no longer counts (the next approval becomes the first), and every
 * pending request gets a week from now to be approved, since a confirmed
 * request used to keep its one-hour link lifetime.
 */
final class KeepTwoStepResetApprovalsInOneWorkspace
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE two_factor_recovery_requests ADD COLUMN IF NOT EXISTS first_approver_tenant_id INTEGER REFERENCES tenants(id) ON DELETE SET NULL');

        $db->exec(
            "UPDATE two_factor_recovery_requests
                SET first_approver_profile_id = NULL, first_approved_at = NULL, first_approval_method = NULL
              WHERE status = 'pending' AND first_approver_profile_id IS NOT NULL AND first_approver_tenant_id IS NULL"
        );
        $db->query(
            "UPDATE two_factor_recovery_requests SET expires_at = :until WHERE status = 'pending' AND expires_at < :until",
            [':until' => gmdate('Y-m-d H:i:s', time() + 7 * 86400)]
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE two_factor_recovery_requests DROP COLUMN IF EXISTS first_approver_tenant_id');
    }
}
