<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePluginCoreGrants (#2256): the core permissions an operator approved for
 * a plugin's role, per workspace.
 *
 * A plugin may only ask for core permissions for its roles
 * (`Whity\Sdk\Contribute\CorePermissionRequestsInterface`); core never grants
 * them on its own. Each row is one approved permission for one role, made by
 * one operator, so an approval grants exactly what was asked and can be taken
 * back one permission at a time. The grant itself is the `role_permissions` row
 * written beside it, marked `granted_by_plugin`, so turning the plugin off or
 * uninstalling it removes it with the plugin's other grants.
 *
 * `tenant_id` is the workspace the role belongs to; 0 for a global role.
 *
 * Table name spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Safe to run twice.
 */
final class CreatePluginCoreGrants
{
    public static function up(Database $db): void
    {
        $db->query(
            'CREATE TABLE IF NOT EXISTS plugin_core_grants (
                id                     SERIAL       PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL,
                -- @reference-lint-ignore: the plugin name, as PluginInterface::getName() returns it
                plugin_key             VARCHAR(255) NOT NULL,
                role_id                INTEGER      NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                permission             VARCHAR(255) NOT NULL,
                approved_by_profile_id INTEGER      NULL REFERENCES profiles(id) ON DELETE SET NULL,
                created_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (role_id, plugin_key, permission)
            )'
        );
        $db->query('CREATE INDEX IF NOT EXISTS idx_plugin_core_grants_tenant_plugin ON plugin_core_grants (tenant_id, plugin_key)');
    }

    public static function down(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS plugin_core_grants');
    }
}
