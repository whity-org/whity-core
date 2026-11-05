<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddRepeatingAndSignatureFieldKinds — two more kinds of question.
 *
 * `repeating` is a table the person adds rows to; its columns are declared in the
 * field's `validation`. `signature` is a typed full name with a server timestamp.
 * {@see \Whity\Core\Form\FieldType} is the other half of this list, and
 * {@see \Tests\Unit\Core\Form\FieldTypeTest} reads this file and fails if they differ.
 *
 * WIDENING THE CHECK. On PostgreSQL that is drop and re-add. SQLite cannot alter a
 * CHECK, so the table is rebuilt there, which migration 125 calls routine for a small
 * table that is not an append-only trail. `form_fields` is one, and no table holds a
 * foreign key to it.
 *
 * down() on PostgreSQL turns the new kinds into `textarea` before narrowing, because
 * the alternative is deleting questions from forms people have answered. On SQLite it
 * leaves the wider CHECK, which refuses nothing the older code writes.
 */
final class AddRepeatingAndSignatureFieldKinds
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $pdo->exec('ALTER TABLE form_fields DROP CONSTRAINT IF EXISTS form_fields_field_type_check');
            $pdo->exec("
                ALTER TABLE form_fields ADD CONSTRAINT form_fields_field_type_check
                    CHECK (field_type IN (
                        'text', 'textarea', 'number', 'date', 'select',
                        'multiselect', 'checkbox', 'file', 'profile_ref', 'ou_ref',
                        'repeating', 'signature'
                    ))
            ");

            return;
        }

        self::rebuildOnSqlite($db);
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return;
        }

        $pdo->exec('ALTER TABLE form_fields DROP CONSTRAINT IF EXISTS form_fields_field_type_check');
        $pdo->exec("UPDATE form_fields SET field_type = 'textarea' WHERE field_type IN ('repeating', 'signature')");
        $pdo->exec("
            ALTER TABLE form_fields ADD CONSTRAINT form_fields_field_type_check
                CHECK (field_type IN (
                    'text', 'textarea', 'number', 'date', 'select',
                    'multiselect', 'checkbox', 'file', 'profile_ref', 'ou_ref'
                ))
        ");
    }

    private static function rebuildOnSqlite(Database $db): void
    {
        $pdo = $db->getPdo();

        // Columns are named on both sides of the copy, so the order the earlier
        // migrations happened to add them in cannot shift a value into a neighbour.
        $columns = 'id, tenant_id, form_id, field_key, field_type, label, help_text, is_required, options,
                    validation, prefill_source, section_key, position, created_at, updated_at,
                    visible_when, audience';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS form_fields_new (
                id             INTEGER      PRIMARY KEY AUTOINCREMENT,
                tenant_id      INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                form_id        INTEGER      NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
                field_key      VARCHAR(128) NOT NULL,
                field_type     VARCHAR(32)  NOT NULL,
                label          TEXT         NOT NULL,
                help_text      TEXT,
                is_required    BOOLEAN      NOT NULL DEFAULT FALSE,
                options        TEXT         NOT NULL DEFAULT '[]',
                validation     TEXT         NOT NULL DEFAULT '{}',
                prefill_source VARCHAR(128),
                section_key    VARCHAR(128),
                position       INTEGER      NOT NULL DEFAULT 0,
                created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                visible_when   TEXT,
                audience       VARCHAR(16)  NOT NULL DEFAULT 'applicant' CHECK (audience IN ('applicant', 'staff')),
                UNIQUE (form_id, field_key),
                CHECK (field_type IN (
                    'text', 'textarea', 'number', 'date', 'select',
                    'multiselect', 'checkbox', 'file', 'profile_ref', 'ou_ref',
                    'repeating', 'signature'
                ))
            )
        ");
        $pdo->exec("INSERT INTO form_fields_new ({$columns}) SELECT {$columns} FROM form_fields");
        $pdo->exec('DROP TABLE form_fields');
        $pdo->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_fields_tenant_form
                ON form_fields(tenant_id, form_id, position)'
        );
    }
}
