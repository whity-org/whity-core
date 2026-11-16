<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateContacts — customers and suppliers as one record, and the imports
 * that bring them in from a spreadsheet (WHIT-953, design 3.3 A8).
 *
 * `contacts` is one row per customer or supplier; `is_customer` and
 * `is_supplier` are both allowed, so a contact can be both. `profile_id` links
 * the contact to a person who already has a profile in Whity (a signed-up
 * customer, someone who booked or wrote in). Messages and Calendar keep their
 * own `customer_profile_id`: a contact joins them through the profile, nothing
 * is moved. Tags are core's `entity_tags` with entity type `contact`.
 *
 * `contact_imports` is one spreadsheet import. The parsed rows are kept as
 * JSON while it is being matched and checked, so nothing changes until the
 * person imports. `contact_import_changes` records what an import did to each
 * contact (created, or updated with the values before), which is what Undo
 * reverses for 24 hours.
 *
 * Every table is tenant-owned. Each name is spelled out literally because
 * TenantOwnedTablesTest and CoreTablesTest re-derive their registries by
 * scanning this source.
 */
final class CreateContacts
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS contacts (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                is_customer           BOOLEAN       NOT NULL DEFAULT TRUE,
                is_supplier           BOOLEAN       NOT NULL DEFAULT FALSE,
                name                  VARCHAR(200)  NOT NULL,
                contact_person        VARCHAR(200)  NOT NULL DEFAULT \'\',
                email                 VARCHAR(320)  NOT NULL DEFAULT \'\',
                mobile                VARCHAR(40)   NOT NULL DEFAULT \'\',
                phone                 VARCHAR(40)   NOT NULL DEFAULT \'\',
                tax_number            VARCHAR(64)   NOT NULL DEFAULT \'\',
                payment_terms         VARCHAR(200)  NOT NULL DEFAULT \'\',
                address               VARCHAR(1000) NOT NULL DEFAULT \'\',
                notes                 VARCHAR(4000) NOT NULL DEFAULT \'\',
                profile_id            INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                created_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_contacts_tenant_name ON contacts(tenant_id, name)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_contacts_tenant_mobile ON contacts(tenant_id, mobile)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_contacts_tenant_tax ON contacts(tenant_id, tax_number)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_contacts_tenant_email ON contacts(tenant_id, email)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_contacts_tenant_profile ON contacts(tenant_id, profile_id)');

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

    public static function down(Database $db): void
    {
        $db->exec("DELETE FROM entity_tags WHERE entity_type = 'contact'");
        $db->exec('DROP TABLE IF EXISTS contact_import_changes');
        $db->exec('DROP TABLE IF EXISTS contact_imports');
        $db->exec('DROP TABLE IF EXISTS contacts');
    }
}
