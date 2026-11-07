<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddRouteSubjectsAndStepKinds (WHIT-918, #1722): step 1 of one routing engine for
 * documents, form submissions and records plugins own (#1717).
 *
 * WHAT A ROUTE TRAVELS ON. Until now a route could only ever travel on a document,
 * so `document_routes.document_id` said everything. The owner chose a polymorphic
 * subject (7 Nov 2026): a route names a SUBJECT TYPE and a SUBJECT ID, and a
 * registered subject provider answers what the engine needs to know about it.
 * Every existing route is a route on a document, so it is backfilled as
 * `document` / its `document_id`, and new routes on documents write the same pair.
 *
 * The two columns are nullable here because SQLite cannot add a NOT NULL column
 * without a default, and no default is right for an id. The engine always writes
 * them, and the backfill leaves no row without them.
 *
 * WHAT KIND OF STEP. A step's behaviour has been spread over two columns:
 * `decision` (migration 119) and `satisfied_by` (migration 125). The plan adds
 * `sign`, `stamp` and `finalise`, which neither column can express, so `kind`
 * names the step once. It is backfilled from the two old columns, which stay and
 * are still written for one release so that nothing reading them changes.
 *
 * THE TABLES KEEP THEIR NAMES. Renaming `document_route_*` to `route_*` would buy
 * nothing a user sees and would rewrite every query and fixture that names them,
 * so this step changes no behaviour and the routing suite runs unchanged.
 *
 * Idempotent and reversible.
 */
final class AddRouteSubjectsAndStepKinds
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_routes ADD COLUMN IF NOT EXISTS subject_type VARCHAR(64)');
        $db->exec('ALTER TABLE document_routes ADD COLUMN IF NOT EXISTS subject_id BIGINT');
        $db->exec(
            "UPDATE document_routes SET subject_type = 'document', subject_id = document_id
              WHERE subject_type IS NULL"
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_routes_tenant_subject
                ON document_routes(tenant_id, subject_type, subject_id, id)'
        );

        // The CHECK sits on the ADDED column, which SQLite accepts (the property
        // migrations 119 and 125 rely on). NOT NULL is legal with a non-null default.
        $db->exec("
            ALTER TABLE document_route_steps
                ADD COLUMN IF NOT EXISTS kind VARCHAR(16) NOT NULL DEFAULT 'review'
                CHECK (kind IN ('review', 'decide', 'deliver', 'sign', 'stamp', 'finalise'))
        ");
        $db->exec(
            "UPDATE document_route_steps
                SET kind = CASE
                    WHEN decision = TRUE THEN 'decide'
                    WHEN satisfied_by = 'delivery' THEN 'deliver'
                    ELSE 'review'
                END"
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS kind');
        $db->exec('DROP INDEX IF EXISTS idx_document_routes_tenant_subject');
        $db->exec('ALTER TABLE document_routes DROP COLUMN IF EXISTS subject_id');
        $db->exec('ALTER TABLE document_routes DROP COLUMN IF EXISTS subject_type');
    }
}
