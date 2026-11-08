<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePluginStarterSeeds (WHIT-995, #1739): which starter templates a
 * workspace plugin has seeded into a workspace (SDK 1.81).
 *
 * One row per workspace, plugin and starter, written when the starter is first
 * seeded. It is what lets the host keep three promises:
 *
 *  - SEEDED ONCE. A starter the workspace deleted is not brought back the next
 *    time the plugin is enabled: the row stays after the template is gone.
 *  - EDITS ARE THE WORKSPACE'S. `content_hash` is the hash of what the plugin
 *    last wrote. When the template no longer hashes to it, a person changed it,
 *    and a plugin update leaves it alone.
 *  - UNTOUCHED COPIES FOLLOW THE PLUGIN. When it still hashes to it, a new
 *    version of the starter replaces it and is published.
 *
 * `source_id` is not a foreign key: deleting the template must not delete the
 * record that it was seeded.
 *
 * Idempotent and reversible.
 */
final class CreatePluginStarterSeeds
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS plugin_starter_seeds (
                id            BIGSERIAL PRIMARY KEY,
                tenant_id     BIGINT       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin        VARCHAR(64)  NOT NULL,
                starter       VARCHAR(40)  NOT NULL,
                source_id     BIGINT,
                content_hash  VARCHAR(64)  NOT NULL,
                created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tenant_id, plugin, starter)
            )
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_starter_seeds');
    }
}
