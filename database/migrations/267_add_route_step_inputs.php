<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Values a person enters at a route step (WHIT-1016).
 *
 * A step can ask the person acting on it for a few short values before they
 * approve: a committee meeting number, a file number, a date written on paper.
 *
 *  - `inputs` on `document_route_template_steps` (a design's stages) and on
 *    `document_route_steps` (a route's steps, copied from the design when the
 *    route is issued, as `allow_send_back` is). A JSON list of
 *    {key, label, label_ar, required, max_length}; NULL means the step asks
 *    for nothing, which is every step written before this migration.
 *  - `route_step_values`: one row per value entered, insert-only like the
 *    trail. The value a route holds for a key is its latest row, so a value
 *    entered again after a send-back replaces the old one without rewriting
 *    it. Each row names the trail event it was entered with.
 *
 * Tenant-owned (CoreTables, TenantOwnedTables): every query binds tenant_id.
 * Idempotent (IF NOT EXISTS) and reversible.
 */
final class AddRouteStepInputs
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_template_steps ADD COLUMN IF NOT EXISTS inputs TEXT');
        $db->exec('ALTER TABLE document_route_steps ADD COLUMN IF NOT EXISTS inputs TEXT');

        $db->exec("
            CREATE TABLE IF NOT EXISTS route_step_values (
                id          BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                route_id    BIGINT       NOT NULL REFERENCES document_routes(id) ON DELETE CASCADE,
                step_id     BIGINT       NOT NULL REFERENCES document_route_steps(id) ON DELETE CASCADE,
                event_id    BIGINT       NOT NULL REFERENCES document_route_events(id) ON DELETE CASCADE,
                value_key   VARCHAR(40)  NOT NULL,
                value       VARCHAR(200) NOT NULL,
                entered_by  INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");
        // A route's values, read whenever the route is shown or printed.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_route_step_values_route ON route_step_values(tenant_id, route_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS route_step_values');
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS inputs');
        $db->exec('ALTER TABLE document_route_template_steps DROP COLUMN IF EXISTS inputs');
    }
}
