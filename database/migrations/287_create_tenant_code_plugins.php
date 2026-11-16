<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Each workspace's switch for an installed code plugin (WHIT-1122).
 *
 * A code plugin is installed once for the whole installation, and before this
 * table it ran for every workspace. A row here says whether one workspace has
 * turned it on. It applies only to plugins whose operator access is `choose`
 * (see `code_plugin_access`, migration 288). With no row, a `choose` plugin is
 * off.
 *
 * - `plugin_name` is the plugin's `getName()`, the same key as `plugin_installs`.
 *   There is no foreign key: a plugin mounted from disk has no install row.
 * - Turning a plugin off never touches its data. Its tables and rows stay, so
 *   turning it back on brings everything back.
 * - `changed_by_profile_id` and `changed_at` hold the latest change, for the
 *   Settings › Plugins page. The full history is in `audit_log`
 *   (`code_plugin.enabled` and `code_plugin.disabled`).
 * - `trial_started_at` and `trial_ends_at` are for paid plugins (WHIT-1125):
 *   one trial per workspace and plugin.
 */
final class CreateTenantCodePlugins
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS tenant_code_plugins (
                tenant_id             INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin_name           VARCHAR(64) NOT NULL,
                enabled               BOOLEAN     NOT NULL DEFAULT FALSE,
                trial_started_at      TIMESTAMP   NULL,
                trial_ends_at         TIMESTAMP   NULL,
                changed_by_profile_id INTEGER     NULL REFERENCES profiles(id) ON DELETE SET NULL,
                changed_at            TIMESTAMP   NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, plugin_name)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_code_plugins_plugin ON tenant_code_plugins (plugin_name)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_tenant_code_plugins_plugin');
        $db->exec('DROP TABLE IF EXISTS tenant_code_plugins');
    }
}
