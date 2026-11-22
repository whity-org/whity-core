<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateThemes (WHIT-1225, TH.1, TH.13): the theme files a workspace imported
 * or installed from the store, beside Whity's own (shipped in
 * `src/Core/Theme/themes/`) and plugins' (read from the plugin).
 *
 * One row per workspace and theme id: importing a new version of the same id
 * replaces the row. `document` is the theme file itself (JSON, already checked
 * against `schemas/theme/v1.json`), `source` says where it came from (`import`
 * or `store`), and `signature_verified` is true only for a store package whose
 * signature and hash core checked. A workspace's chosen theme is the
 * `branding_theme` setting, not a column here.
 *
 * Table name spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Safe to run twice.
 */
final class CreateThemes
{
    public static function up(Database $db): void
    {
        $db->query(
            'CREATE TABLE IF NOT EXISTS themes (
                id                     SERIAL       PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                -- @reference-lint-ignore: the id the theme file gives itself (vendor.name), not a row in another table
                theme_id               VARCHAR(100) NOT NULL,
                version                VARCHAR(32)  NOT NULL,
                source                 VARCHAR(16)  NOT NULL DEFAULT \'import\',
                document               TEXT         NOT NULL,
                signature_verified     BOOLEAN      NOT NULL DEFAULT FALSE,
                created_by_profile_id  INTEGER      NULL REFERENCES profiles(id) ON DELETE SET NULL,
                created_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, theme_id)
            )'
        );
    }

    public static function down(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS themes');
    }
}
