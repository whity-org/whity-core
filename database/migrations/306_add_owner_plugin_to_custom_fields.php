<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * A plugin's own fields on core's records (WHIT-1209, SDK 1.119, #2131).
 *
 *   owner_plugin  the name of the plugin that declared the field
 *                 (PluginContactFieldsInterface), or NULL for a field the
 *                 workspace made itself. A plugin's field has a key under the
 *                 plugin's name (`keyhub.supplier_code`); it is archived when
 *                 the plugin is turned off and restored when it is turned on,
 *                 with its values kept. People can't archive or restore it.
 *
 * Optional, so additive with nothing to backfill. Idempotent and reversible.
 */
final class AddOwnerPluginToCustomFields
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE custom_field_definitions ADD COLUMN IF NOT EXISTS owner_plugin VARCHAR(100)');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE custom_field_definitions DROP COLUMN IF EXISTS owner_plugin');
    }
}
