<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateDashboardLayout (SDK 1.115, WHIT-1177; design H.5): how each person
 * arranged their dashboard in each workspace.
 *
 * One row per item a person moved, hid or resized: a home card by its plugin
 * name, a widget by plugin and id (`salons:todays-bookings`). `position` is
 * their order (null keeps the apps' own), `hidden` hides it, `size` is the width
 * they chose among those the widget fits (null keeps its first). No row means
 * shown where the app puts it, so a newly installed app appears for everyone.
 * No foreign key to a plugin, because a plugin is not a row: an uninstalled
 * app's rows do nothing and it comes back as it was. Removing the workspace or
 * the person removes the rows.
 *
 * It replaces `home_card_hidden` (migration 200), whose rows move here as
 * hidden cards before that table is dropped. Table names spelled out literally
 * for TenantOwnedTablesTest and CoreTablesTest. Safe to run twice.
 */
final class CreateDashboardLayout
{
    public static function up(Database $db): void
    {
        $db->query(
            'CREATE TABLE IF NOT EXISTS dashboard_layout (
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                item_key VARCHAR(128) NOT NULL,
                position INTEGER NULL,
                hidden BOOLEAN NOT NULL DEFAULT FALSE,
                size VARCHAR(16) NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, profile_id, item_key)
            )'
        );

        if (self::tableExists($db, 'home_card_hidden')) {
            $db->query(
                'INSERT INTO dashboard_layout (tenant_id, profile_id, item_key, hidden)
                 SELECT tenant_id, profile_id, card_key, TRUE FROM home_card_hidden WHERE TRUE
                 ON CONFLICT (tenant_id, profile_id, item_key) DO NOTHING'
            );
            $db->query('DROP TABLE IF EXISTS home_card_hidden');
        }
    }

    public static function down(Database $db): void
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
        $db->query(
            "INSERT INTO home_card_hidden (tenant_id, profile_id, card_key)
             SELECT tenant_id, profile_id, item_key FROM dashboard_layout
              WHERE hidden AND item_key NOT LIKE '%:%' AND LENGTH(item_key) <= 64
             ON CONFLICT (tenant_id, profile_id, card_key) DO NOTHING"
        );
        $db->query('DROP TABLE IF EXISTS dashboard_layout');
    }

    private static function tableExists(Database $db, string $table): bool
    {
        $pdo = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $stmt = $driver === 'sqlite'
            ? $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?")
            : $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = current_schema()');
        $stmt->execute([$table]);
        $found = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $found;
    }
}
