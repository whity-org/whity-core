<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Customers invite their own team (#1872, WHIT-996).
 *
 * - `workspace_signup_settings.customer_invites`: whether a customer of this
 *   workspace may invite colleagues. Off by default, and a workspace with no
 *   row is off.
 * - `invitations.kind`: `member` (staff; every row written before this
 *   migration and every invitation an admin sends) or `customer`. Accepting a
 *   `customer` invitation creates a customer membership, which RBAC caps.
 * - `invitations.annotations`: what plugins attached to a customer's invitation
 *   while it was being sent (a JSON object, small), handed back to them when it
 *   is accepted. Core never reads it.
 *
 * Idempotent and reversible.
 */
final class CustomerTeamInvites
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE workspace_signup_settings ADD COLUMN IF NOT EXISTS customer_invites BOOLEAN NOT NULL DEFAULT FALSE');
        $db->exec("ALTER TABLE invitations ADD COLUMN IF NOT EXISTS kind VARCHAR(16) NOT NULL DEFAULT 'member'");
        $db->exec('ALTER TABLE invitations ADD COLUMN IF NOT EXISTS annotations TEXT NULL');
        // A customer lists and withdraws only the invitations they sent.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_invitations_tenant_inviter ON invitations (tenant_id, invited_by)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_invitations_tenant_inviter');
        $db->exec('ALTER TABLE invitations DROP COLUMN IF EXISTS annotations');
        $db->exec('ALTER TABLE invitations DROP COLUMN IF EXISTS kind');
        $db->exec('ALTER TABLE workspace_signup_settings DROP COLUMN IF EXISTS customer_invites');
    }
}
