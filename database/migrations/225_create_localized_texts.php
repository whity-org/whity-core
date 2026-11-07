<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateLocalizedTexts (#821, WHIT-973): names that exist in more than one
 * language, for entities that already have a name column.
 *
 * `localized_texts` holds one row per entity, field and language. The entity's
 * own column keeps the ORIGINAL name, in the language the new
 * `tenants.name_language` column records; this table holds the other languages.
 * For a Jordanian institution the Arabic name is usually the legal one and the
 * English name is the derived one, so English is not assumed to be the original.
 *
 * Keeping the original in `tenants.name` means every existing query, sort and
 * uniqueness check keeps working. Organizational units use the same table next
 * (entity_type `ou`), so there is one mechanism for both.
 *
 * `tenant_id` is the workspace the row belongs to. For a workspace's own name
 * it is the workspace itself.
 *
 * Existing workspaces get `name_language = 'ar'` when their name is written in
 * Arabic script, and `en` otherwise.
 *
 * Additive and idempotent; down() drops the table and the column.
 */
final class CreateLocalizedTexts
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS localized_texts (
                id            BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id     INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                entity_type   VARCHAR(32)  NOT NULL,
                entity_id     INTEGER      NOT NULL,
                field         VARCHAR(32)  NOT NULL,
                language_code VARCHAR(16)  NOT NULL,
                value         TEXT         NOT NULL,
                created_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (entity_type, entity_id, field, language_code)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_localized_texts_tenant ON localized_texts (tenant_id, entity_type, entity_id)');

        $db->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS name_language VARCHAR(16) NOT NULL DEFAULT 'en'");

        $rows = $db->query('SELECT id, name FROM tenants')->fetchAll();
        foreach ($rows === false ? [] : $rows as $row) {
            if (preg_match('/\p{Arabic}/u', (string) ($row['name'] ?? '')) === 1) {
                $db->query("UPDATE tenants SET name_language = 'ar' WHERE id = :id", [':id' => (int) $row['id']]);
            }
        }
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS localized_texts');
        $db->exec('ALTER TABLE tenants DROP COLUMN IF EXISTS name_language');
    }
}
