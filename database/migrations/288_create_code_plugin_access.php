<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Which workspaces may use each installed code plugin (WHIT-1122). Only the
 * operator changes this, from the system workspace.
 *
 * `workspace_access` takes one of three values:
 * - `all`: on for every workspace, as before this table existed. A plugin with
 *   no row behaves this way, so plugins mounted from disk keep working.
 * - `choose`: each workspace turns it on in Settings › Plugins. It is off until
 *   it does (`tenant_code_plugins`, migration 287). Installing from the store
 *   or by upload writes this.
 * - `operator`: only the system workspace, for plugins that run the
 *   installation itself (the store, the operator's billing).
 *
 * `free_for_all` is the operator's "free for all my workspaces" switch. A
 * workspace may then turn on a paid plugin without a plan, add-on or trial
 * (WHIT-1125).
 *
 * The table is install-wide, with no tenant_id, like `plugin_installs`.
 */
final class CreateCodePluginAccess
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS code_plugin_access (
                plugin_name           VARCHAR(64) NOT NULL PRIMARY KEY,
                workspace_access      VARCHAR(16) NOT NULL DEFAULT 'choose'
                                      CHECK (workspace_access IN ('all', 'choose', 'operator')),
                free_for_all          BOOLEAN     NOT NULL DEFAULT FALSE,
                updated_by_profile_id INTEGER     NULL REFERENCES profiles(id) ON DELETE SET NULL,
                updated_at            TIMESTAMP   NOT NULL DEFAULT NOW()
            )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS code_plugin_access');
    }
}
