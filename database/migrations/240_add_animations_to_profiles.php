<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * A person's Animations setting (LT.4): full, calm or off.
 *
 * On `profiles`, beside text size and Show technical details (191), because it
 * belongs to the person and follows them across workspaces and devices.
 * Additive, idempotent and reversible.
 */
class AddAnimationsToProfiles
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS animations VARCHAR(8) NOT NULL DEFAULT 'full'");
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS animations');
    }
}
