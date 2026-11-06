<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * TrackPluginRoleGrants migration (GHSA-r4hx-wj99-jw8m).
 *
 * Records who made what, so {@see \Whity\Core\PluginRoleSeeder} can keep a
 * plugin within its own roles and grants:
 *
 *  - `roles.plugin_key`: the plugin that created the role. NULL for core roles,
 *    operator-made roles and roles created before this migration.
 *  - `role_permissions.granted_by_plugin`: the plugin that made the grant. NULL
 *    for core and operator grants. Uninstall removes only rows carrying the
 *    plugin's own name, so a grant that existed before the plugin stays.
 *  - `plugin_role_approvals`: an existing role the operator approved for a
 *    plugin at install. The seeder grants to a role the plugin did not create
 *    only when a row here says so. It carries no tenant_id: like
 *    `role_permissions` it is scoped through its role, and a deleted role takes
 *    its approvals with it.
 *
 * No backfill: rows written before this migration are treated as core's. That
 * errs towards keeping a grant on uninstall rather than removing one.
 *
 * Idempotent and reversible.
 */
final class TrackPluginRoleGrants
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE roles ADD COLUMN IF NOT EXISTS plugin_key VARCHAR(255) NULL');
        $db->exec('ALTER TABLE role_permissions ADD COLUMN IF NOT EXISTS granted_by_plugin VARCHAR(255) NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_role_permissions_granted_by_plugin ON role_permissions (granted_by_plugin)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS plugin_role_approvals (
                id          SERIAL       PRIMARY KEY,
                role_id     INTEGER      NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                plugin_key  VARCHAR(255) NOT NULL,
                approved_by INTEGER      NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (role_id, plugin_key)
            )
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_role_approvals');
        $db->exec('DROP INDEX IF EXISTS idx_role_permissions_granted_by_plugin');
        $db->exec('ALTER TABLE role_permissions DROP COLUMN IF EXISTS granted_by_plugin');
        $db->exec('ALTER TABLE roles DROP COLUMN IF EXISTS plugin_key');
    }
}
