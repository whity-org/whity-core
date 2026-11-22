<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateThemeVersions (WHIT-1225, TH.25, 30-themes 11 "Versions"): every
 * version of a workspace's own theme, so the editor can list its history,
 * compare any two side by side and restore one.
 *
 * `themes` (323) keeps one row per theme with the version in use; this keeps
 * one row per theme and version. Saving the same version again replaces that
 * version's document (an import of an unchanged version number), and a new
 * version number adds a row. `source` is how that version came in: `import`,
 * `editor` or `restore`. Removing the theme removes its history with it (the
 * theme handler does, and the tenant cascade does for a removed workspace).
 *
 * Each theme already in `themes` becomes its own first version here, so a
 * workspace sees the version it has today in the history. Table name spelled
 * out literally for TenantOwnedTablesTest and CoreTablesTest. Safe to run
 * twice.
 */
final class CreateThemeVersions
{
    public static function up(Database $db): void
    {
        $db->query(
            'CREATE TABLE IF NOT EXISTS theme_versions (
                id                     SERIAL       PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                -- @reference-lint-ignore: the id the theme file gives itself (vendor.name), not a row in another table
                theme_id               VARCHAR(100) NOT NULL,
                version                VARCHAR(32)  NOT NULL,
                source                 VARCHAR(16)  NOT NULL DEFAULT \'import\',
                document               TEXT         NOT NULL,
                created_by_profile_id  INTEGER      NULL REFERENCES profiles(id) ON DELETE SET NULL,
                created_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, theme_id, version)
            )'
        );
        $db->query('CREATE INDEX IF NOT EXISTS idx_theme_versions_theme ON theme_versions (tenant_id, theme_id)');

        // Today's version of each theme is the first in its history.
        $db->query(
            'INSERT INTO theme_versions (tenant_id, theme_id, version, source, document, created_by_profile_id, created_at)
             SELECT t.tenant_id, t.theme_id, t.version, t.source, t.document, t.created_by_profile_id, t.updated_at
             FROM themes t
             WHERE NOT EXISTS (
                 SELECT 1 FROM theme_versions v
                 WHERE v.tenant_id = t.tenant_id AND v.theme_id = t.theme_id AND v.version = t.version
             )'
        );
    }

    public static function down(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS theme_versions');
    }
}
