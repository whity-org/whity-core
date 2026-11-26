<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddChannelToDesktopAppReleases: a desktop app release belongs to a channel,
 * `stable` or `beta`, chosen per device (whity-desktop ADR 0001, decision 6;
 * WHIT-1431). A stable device is offered stable releases only; a beta device
 * is offered beta and stable ones. Everything registered so far is stable.
 *
 * Install-wide table (no tenant_id), like `desktop_app_releases` itself.
 * Safe to run twice; reversible.
 */
final class AddChannelToDesktopAppReleases
{
    public static function up(Database $db): void
    {
        $db->query("ALTER TABLE desktop_app_releases ADD COLUMN IF NOT EXISTS channel VARCHAR(16) NOT NULL DEFAULT 'stable'");
        // The default fills the column on both engines; this makes it so on a
        // row that somehow lacks it, and is a no-op otherwise.
        $db->query("UPDATE desktop_app_releases SET channel = 'stable' WHERE channel IS NULL OR channel = ''");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_desktop_app_releases_target_channel_released ON desktop_app_releases (target, channel, released_at DESC)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_desktop_app_releases_target_channel_released');
        $db->query('ALTER TABLE desktop_app_releases DROP COLUMN IF EXISTS channel');
    }
}
