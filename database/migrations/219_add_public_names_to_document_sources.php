<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddPublicNamesToDocumentSources (#1627): what kind of document a template makes,
 * in words a stranger may read.
 *
 * The public check can say "Handover certificate · revision A · 2 pages" when a
 * workspace chooses the `described` detail level. The kind can't be the template's
 * title, because a title is often about one customer ("Handover for Example Bakery"),
 * and the public check never shows names or subjects. So it is its own field, empty
 * until somebody who may publish writes it. An empty one shows no kind at all.
 *
 * Idempotent and reversible.
 */
final class AddPublicNamesToDocumentSources
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_sources ADD COLUMN IF NOT EXISTS public_name VARCHAR(120)');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE document_sources DROP COLUMN IF EXISTS public_name');
    }
}
