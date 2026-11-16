<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Leaving out people the subject names (WHIT-1018).
 *
 * `exclude_named_in` on `document_route_template_steps` (a design's stages)
 * and on `document_route_steps` (a route's steps, copied from the design when
 * the route is issued): a JSON list of the subject's people questions, for
 * example a form's `supervisor` and `co_supervisor`. Anybody named in one is
 * left out when the step's rule is resolved: not asked, not counted in the
 * quorum, and so never printed among the approvals. A committee member who
 * supervises the project does not review it.
 *
 * NULL is no exclusions, which is every step written before this migration.
 * Idempotent (IF NOT EXISTS) and reversible.
 */
final class AddRouteStepExclusions
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_template_steps ADD COLUMN IF NOT EXISTS exclude_named_in TEXT');
        $db->exec('ALTER TABLE document_route_steps ADD COLUMN IF NOT EXISTS exclude_named_in TEXT');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS exclude_named_in');
        $db->exec('ALTER TABLE document_route_template_steps DROP COLUMN IF EXISTS exclude_named_in');
    }
}
