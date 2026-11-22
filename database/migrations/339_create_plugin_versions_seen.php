<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePluginVersionsSeen: the last version of each plugin the deployment
 * has run, so the host calls a plugin's onInstalled() or onUpgraded() once per
 * version (SDK 1.152, #1686, WHIT-1331).
 *
 * Deployment-wide, like plugin_installs: a plugin is installed on the
 * deployment, not in a workspace, so there is no tenant_id. Idempotent and
 * reversible.
 */
final class CreatePluginVersionsSeen
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS plugin_versions_seen (
                plugin_name VARCHAR(64) NOT NULL PRIMARY KEY,
                version     VARCHAR(64) NOT NULL,
                seen_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_versions_seen');
    }
}
