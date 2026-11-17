<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * A person's own phone number (WHIT-1181), on `profiles` because it belongs to
 * the person and follows them across workspaces, like their name.
 *
 *   phone         E.164 (+962791234567), or NULL when none is set.
 *   phone_source  who set it: 'person' (their own profile) or
 *                 'identity_provider' (the phone_number claim of a Whity ID
 *                 sign-in). NULL until either does. A sign-in only fills or
 *                 refreshes a phone that is empty or came from a sign-in, so
 *                 one the person typed, or cleared, is never overwritten.
 *
 * Optional, so additive with nothing to backfill. Idempotent and reversible.
 */
class AddPhoneToProfiles
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE profiles ADD COLUMN IF NOT EXISTS phone VARCHAR(16)');
        $db->exec('ALTER TABLE profiles ADD COLUMN IF NOT EXISTS phone_source VARCHAR(20)');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS phone_source');
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS phone');
    }
}
