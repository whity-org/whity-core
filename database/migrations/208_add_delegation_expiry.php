<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddDelegationExpiry — a delegation can end on a date, and a revoke can be undone.
 *
 * `expires_at` is the "Until" of the delegations screen (design 2.9, A14). NULL is
 * "No end date", which is what every existing row keeps, so nothing that is live
 * today ends because of this migration. It is stored in UTC, like the time columns
 * the auth services compare from PHP, and enforced where permissions are resolved:
 * {@see \Whity\Core\Delegation\DelegationRepository::livePermissionsForGrantee()}.
 *
 * `revoked_by_profile_id` is what makes Undo an undo and not a re-grant: only the
 * person who revoked a delegation may restore it, for a short time. Rows revoked
 * before this column existed have NULL here and so can never be restored.
 *
 * Additive and idempotent; down() drops the two columns.
 */
final class AddDelegationExpiry
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE permission_delegations ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP');
        $db->exec(
            'ALTER TABLE permission_delegations ADD COLUMN IF NOT EXISTS revoked_by_profile_id INTEGER
                 REFERENCES profiles(id) ON DELETE SET NULL'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE permission_delegations DROP COLUMN IF EXISTS revoked_by_profile_id');
        $db->exec('ALTER TABLE permission_delegations DROP COLUMN IF EXISTS expires_at');
    }
}
