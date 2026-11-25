<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddInvitationContact — an invitation to a customer's account names its
 * contact (WHIT-1428, design section 41 Q4, CA.6).
 *
 * Staff with `contacts:write` can invite a contact to the customer area from
 * the contact's page, whatever the workspace's public sign-up mode. Accepting
 * makes a customer membership and links THAT contact to the person
 * (`contacts.profile_id`), so their bookings and bills are theirs at once. A
 * contact that is removed leaves the invitation without one, and accepting it
 * then links nothing.
 *
 * Idempotent and reversible. No backfill: no invitation named a contact before.
 */
final class AddInvitationContact
{
    public static function up(Database $db): void
    {
        // migration-lint: allow constraint-not-valid (a new, all-NULL column: there is nothing to check)
        $db->exec('ALTER TABLE invitations ADD COLUMN IF NOT EXISTS contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL');
        // migration-lint: allow index-not-concurrent (invitations holds a few rows per workspace; the column is new and empty when the index is built)
        $db->exec('CREATE INDEX IF NOT EXISTS idx_invitations_contact ON invitations (tenant_id, contact_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_invitations_contact');
        $db->exec('ALTER TABLE invitations DROP COLUMN IF EXISTS contact_id');
    }
}
