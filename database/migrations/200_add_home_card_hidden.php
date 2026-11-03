<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddHomeCardHidden (WHIT-872, design 2.9 H.4): which home cards a person hid.
 *
 * Per workspace and per person, because the apps a person sees differ by workspace,
 * and hiding one is nobody else's business. A row means hidden; no row means shown,
 * so a newly installed app appears for everyone. Keyed by plugin name, which is what
 * a card goes by since each plugin has one, with no foreign key because a plugin is
 * not a row: an uninstalled plugin's rows do nothing, and it stays hidden if it
 * comes back. Removing the workspace or the person removes the rows.
 */
final class AddHomeCardHidden
{
    public static function up(Database $db): void
    {
        $db->query(
            'CREATE TABLE IF NOT EXISTS home_card_hidden (
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                card_key VARCHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, profile_id, card_key)
            )'
        );
    }

    public static function down(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS home_card_hidden');
    }
}
