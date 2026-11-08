<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddNameLanguageToOrganizationalUnits (#821, WHIT-978): units get names in
 * more than one language, the same way workspaces did in migration 225.
 *
 * `organizational_units.name` keeps the ORIGINAL name and the new
 * `name_language` column records its language. The unit's names in other
 * languages go in `localized_texts` with entity_type `ou`. No second table:
 * one mechanism for workspaces and units.
 *
 * Existing units get `name_language = 'ar'` when their name is written in
 * Arabic script, and `en` otherwise.
 *
 * Additive and idempotent; down() drops the column and the units' rows in
 * localized_texts.
 */
final class AddNameLanguageToOrganizationalUnits
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE organizational_units ADD COLUMN IF NOT EXISTS name_language VARCHAR(16) NOT NULL DEFAULT 'en'");

        $rows = $db->query('SELECT id, tenant_id, name FROM organizational_units')->fetchAll();
        foreach ($rows === false ? [] : $rows as $row) {
            if (preg_match('/\p{Arabic}/u', (string) ($row['name'] ?? '')) === 1) {
                $db->query(
                    "UPDATE organizational_units SET name_language = 'ar' WHERE id = :id AND tenant_id = :tenant_id",
                    [':id' => (int) $row['id'], ':tenant_id' => (int) $row['tenant_id']]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        $db->exec("DELETE FROM localized_texts WHERE entity_type = 'ou'");
        $db->exec('ALTER TABLE organizational_units DROP COLUMN IF EXISTS name_language');
    }
}
