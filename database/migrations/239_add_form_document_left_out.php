<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddFormDocumentLeftOut — the questions a person chose to keep out of a form's
 * final document, so the gap check stops raising them.
 *
 * `forms.document_left_out` is a JSON list of `field_key`s. The choice is about
 * one template, so it is emptied when the form's final document template
 * changes.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
final class AddFormDocumentLeftOut
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE forms ADD COLUMN IF NOT EXISTS document_left_out JSONB NOT NULL DEFAULT '[]'::jsonb");
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS document_left_out');
    }
}
