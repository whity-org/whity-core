<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateWorkspaceTemplateParts (SDK 1.169, WHIT-1409; design section 40
 * §2.4): which version of each part a template run applied.
 *
 * A run applies its template, and later minors add the templates it builds
 * on and the extensions other plugins add to it. Each is a part, keyed
 * `template:{key}` (and later `extends:{key}`, `extension:{plugin}.{key}`),
 * with the version applied last. Core offers an update when a part's current
 * version is higher than the one recorded here.
 *
 * Every existing run gets its template's part, at the run's version, so the
 * first update offer compares like with like.
 *
 * Table name spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Safe to run twice.
 */
final class CreateWorkspaceTemplateParts
{
    public static function up(Database $db): void
    {
        $db->query(
            'CREATE TABLE IF NOT EXISTS workspace_template_parts (
                id           SERIAL       PRIMARY KEY,
                tenant_id    INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                run_id       INTEGER      NOT NULL REFERENCES workspace_template_runs(id) ON DELETE CASCADE,
                part_key     VARCHAR(160) NOT NULL,
                plugin_name  VARCHAR(128) NULL,
                version      INTEGER      NOT NULL DEFAULT 1,
                applied_at   TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (run_id, part_key)
            )'
        );
        $db->query('CREATE INDEX IF NOT EXISTS idx_workspace_template_parts_tenant ON workspace_template_parts (tenant_id)');
        $db->query(
            "INSERT INTO workspace_template_parts (tenant_id, run_id, part_key, plugin_name, version, applied_at)
             SELECT tenant_id, id, 'template:' || template_key, plugin_name, version, applied_at
               FROM workspace_template_runs
              WHERE 1 = 1
             ON CONFLICT (run_id, part_key) DO NOTHING"
        );
    }

    public static function down(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS workspace_template_parts');
    }
}
