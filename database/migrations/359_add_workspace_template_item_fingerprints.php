<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddWorkspaceTemplateItemFingerprints (SDK 1.170, WHIT-1413; design section
 * 40 §2.4, decision WT.2): which version of its manifest entry each item a
 * template made now matches.
 *
 * When a newer version of a template changes an item the workspace already
 * has (a price, a form's questions), core compares the entry's fingerprint
 * with the one stored here and offers "Use the template's version" for that
 * item; it never changes the item on its own. Items made before this column
 * have none: they get one the next time their template is applied at the
 * same version, and until then a newer version offers them as changed.
 *
 * Safe to run twice.
 */
final class AddWorkspaceTemplateItemFingerprints
{
    public static function up(Database $db): void
    {
        $db->query('ALTER TABLE workspace_template_items ADD COLUMN IF NOT EXISTS fingerprint VARCHAR(64) NULL');
    }

    public static function down(Database $db): void
    {
        $db->query('ALTER TABLE workspace_template_items DROP COLUMN IF EXISTS fingerprint');
    }
}
