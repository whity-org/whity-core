<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * DropContactImports — the contacts' own import tables (migration 261) are
 * gone: migration 401 copied them into `import_jobs` and
 * `import_job_changes`, which every kind of import now shares (WHIT-1322,
 * design section 34 §6.1, register IM.2; no real customers, owner 16 November
 * 2026).
 *
 * `down()` brings the empty tables back, so migration 261 reads as it did;
 * the rows stay in `import_jobs`.
 */
final class DropContactImports
{
    public static function up(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS contact_import_changes');
        $db->exec('DROP TABLE IF EXISTS contact_imports');
    }

    public static function down(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS contact_imports (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                created_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                file_name             VARCHAR(255)  NOT NULL DEFAULT \'\',
                status                VARCHAR(20)   NOT NULL DEFAULT \'uploaded\',
                header_json           TEXT          NOT NULL,
                rows_json             TEXT          NOT NULL,
                options_json          TEXT          NOT NULL DEFAULT \'{}\',
                summary_json          TEXT          NOT NULL DEFAULT \'{}\',
                imported_at           TIMESTAMP,
                undone_at             TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_contact_imports_tenant ON contact_imports(tenant_id, created_at)');
        $db->exec('
            CREATE TABLE IF NOT EXISTS contact_import_changes (
                id          SERIAL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                import_id   INTEGER      NOT NULL REFERENCES contact_imports(id) ON DELETE CASCADE,
                -- @reference-lint-ignore: the contact an import made or changed; undo removes made ones itself, and a row for a contact deleted since is skipped
                contact_id  INTEGER      NOT NULL,
                action      VARCHAR(10)  NOT NULL,
                before_json TEXT,
                tags_added  TEXT         NOT NULL DEFAULT \'[]\',
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_contact_import_changes_import ON contact_import_changes(tenant_id, import_id)');
    }
}
