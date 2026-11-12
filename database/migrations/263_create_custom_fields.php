<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateCustomFields — the workspace's own fields on core's records, and
 * their values (WHIT-954, design 3.3 A9, `TplCustomFields`).
 *
 * `custom_field_definitions` is one field on one record type (`contact` in
 * core; a plugin's own types later). `kind` is one of the form builder's kinds
 * ({@see \Whity\Core\Form\FieldType}). `label` is `{en, ar}` JSON;
 * `options_json` holds the choices of a choose-one or choose-several field.
 * `field_key` is what documents and filters use (`{{contact.preferred_branch}}`)
 * and never changes once made. `edit_permission`, when set, is the permission
 * needed to change the value besides the record's own write permission.
 *
 * `custom_field_values` is one value of one field on one record, as JSON, with
 * `value_text` its plain-text form, which lists filter on.
 *
 * Both tables are tenant-owned. Each name is spelled out literally because
 * TenantOwnedTablesTest and CoreTablesTest re-derive their registries by
 * scanning this source.
 */
final class CreateCustomFields
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS custom_field_definitions (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                record_type           VARCHAR(64)  NOT NULL,
                field_key             VARCHAR(64)  NOT NULL,
                label                 TEXT         NOT NULL,
                kind                  VARCHAR(20)  NOT NULL,
                options_json          TEXT         NOT NULL DEFAULT \'[]\',
                show_in_list          BOOLEAN      NOT NULL DEFAULT FALSE,
                is_required           BOOLEAN      NOT NULL DEFAULT FALSE,
                edit_permission       VARCHAR(100),
                sort_order            INTEGER      NOT NULL DEFAULT 0,
                created_by_profile_id INTEGER               REFERENCES profiles(id) ON DELETE SET NULL,
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, record_type, field_key)
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS custom_field_values (
                id          SERIAL PRIMARY KEY,
                tenant_id   INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                field_id    INTEGER       NOT NULL REFERENCES custom_field_definitions(id) ON DELETE CASCADE,
                record_type VARCHAR(64)   NOT NULL,
                record_id   INTEGER       NOT NULL,
                value_json  TEXT          NOT NULL,
                value_text  VARCHAR(1000) NOT NULL DEFAULT \'\',
                updated_at  TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (field_id, record_id)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_custom_field_values_record ON custom_field_values(tenant_id, record_type, record_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_custom_field_values_filter ON custom_field_values(tenant_id, field_id, value_text)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS custom_field_values');
        $db->exec('DROP TABLE IF EXISTS custom_field_definitions');
    }
}
