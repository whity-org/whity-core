<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Plugins kept for 30 days after uninstalling (S.7, WHIT-870).
 *
 * While a row exists the plugin is turned off and hidden from the Installed list,
 * its files and tables are untouched, and Reinstall restores it. When
 * `purge_after` passes, a job runs its migrations' down() and removes its files.
 *
 * Install-wide on purpose, with no tenant_id: a code plugin belongs to the
 * deployment (Q4), and only the system tenant reaches these rows.
 */
class CreatePluginRemovals
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plugin_removals (
                plugin_name  VARCHAR(64)  NOT NULL PRIMARY KEY,
                plugin_key   VARCHAR(255) NOT NULL,
                version      VARCHAR(64)  NULL,
                was_active   BOOLEAN      NOT NULL,
                removed_by_profile_id INTEGER NULL REFERENCES profiles(id) ON DELETE SET NULL,
                removed_at   TIMESTAMP    NOT NULL DEFAULT NOW(),
                purge_after  TIMESTAMP    NOT NULL,
                purge_error  TEXT         NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_plugin_removals_purge_after ON plugin_removals(purge_after)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_removals');
    }
}
