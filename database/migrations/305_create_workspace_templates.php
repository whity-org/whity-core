<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateWorkspaceTemplates (SDK 1.116, WHIT-1194): which template a workspace
 * started from, and what applying it made.
 *
 * `workspace_template_runs` has one row per workspace and template: who chose
 * it, which version of the manifest was applied last, and when the person
 * closed its guided setup or its plugin offer. A workspace with no row has not
 * chosen yet, so core offers the picker. "Blank" is a template too, so
 * choosing it is a row like any other.
 *
 * `workspace_template_items` lists each thing applying made: a service, a
 * role, a form, a document, and the examples (contacts and bookings) marked
 * `is_example`. "Remove examples" acts only on those rows, and only through
 * core's own routes as the person, so it archives or cancels what is still
 * there and never touches anything the workspace made itself. `ref_id` is the
 * id core's route returned, as text so any kind fits.
 *
 * Table names spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Safe to run twice.
 */
final class CreateWorkspaceTemplates
{
    public static function up(Database $db): void
    {
        $db->query(
            'CREATE TABLE IF NOT EXISTS workspace_template_runs (
                id                     SERIAL       PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                template_key           VARCHAR(64)  NOT NULL,
                plugin_name            VARCHAR(128) NULL,
                version                INTEGER      NOT NULL DEFAULT 1,
                applied_by_profile_id  INTEGER      NULL REFERENCES profiles(id) ON DELETE SET NULL,
                applied_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                setup_closed_at        TIMESTAMP    NULL,
                offer_closed_at        TIMESTAMP    NULL,
                UNIQUE (tenant_id, template_key)
            )'
        );
        $db->query(
            'CREATE TABLE IF NOT EXISTS workspace_template_items (
                id          SERIAL      PRIMARY KEY,
                tenant_id   INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                run_id      INTEGER     NOT NULL REFERENCES workspace_template_runs(id) ON DELETE CASCADE,
                kind        VARCHAR(32) NOT NULL,
                item_key    VARCHAR(64) NOT NULL,
                ref_id      VARCHAR(64) NOT NULL,
                is_example  BOOLEAN     NOT NULL DEFAULT FALSE,
                removed_at  TIMESTAMP   NULL,
                created_at  TIMESTAMP   NOT NULL DEFAULT NOW(),
                UNIQUE (run_id, kind, item_key)
            )'
        );
        $db->query('CREATE INDEX IF NOT EXISTS idx_workspace_template_items_tenant ON workspace_template_items (tenant_id, is_example)');
    }

    public static function down(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS workspace_template_items');
        $db->query('DROP TABLE IF EXISTS workspace_template_runs');
    }
}
