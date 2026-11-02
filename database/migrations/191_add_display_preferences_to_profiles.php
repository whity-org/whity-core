<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * A person's display preferences (design decisions W.3, A.1, A.3).
 *
 * On `profiles`, beside `language_code`, because they belong to the person and
 * follow them across workspaces and devices. Additive, idempotent and reversible.
 */
class AddDisplayPreferencesToProfiles
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS text_size VARCHAR(16) NOT NULL DEFAULT 'standard'");
        $db->exec('ALTER TABLE profiles ADD COLUMN IF NOT EXISTS show_technical_details BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS show_technical_details');
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS text_size');
    }
}
