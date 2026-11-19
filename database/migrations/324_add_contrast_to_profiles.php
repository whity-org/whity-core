<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * A person's Higher contrast preference (WHIT-1225, TH.12), on `profiles` next
 * to `text_size` and `animations`, because it belongs to the person in every
 * workspace and theme.
 *
 *   contrast  'standard' (default) or 'high': text at 7:1 and borders and the
 *             focus ring at 4.5:1, whatever the workspace's theme.
 *
 * Additive with a default, so nothing to backfill. Idempotent and reversible.
 */
class AddContrastToProfiles
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS contrast VARCHAR(16) NOT NULL DEFAULT 'standard'");
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS contrast');
    }
}
