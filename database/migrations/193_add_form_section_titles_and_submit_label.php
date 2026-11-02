<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddFormSectionTitlesAndSubmitLabel — the form text that had nowhere to live.
 *
 * `forms.sections` holds a title and description per `section_key`. Which sections
 * exist is still derived from the fields ({@see \Whity\Core\Form\FormRenderer}), so
 * this cannot create a section with no questions, and an entry whose key no field
 * uses is simply not drawn.
 *
 * `forms.submit_label` is what the button says ("Send application"). NULL means the
 * page's own default wording.
 *
 * `forms.description` and `form_fields.help_text` change meaning without changing
 * type: they may now hold a `{ar, en}` JSON object, and
 * {@see \Whity\Core\Form\LocalizedLabel::decode()} already reads a plain string
 * written earlier as English. So no row needs rewriting.
 *
 * Additive and idempotent; down() drops the two columns. A translated description
 * stays stored as JSON after a rollback, which the older code would show as text.
 */
final class AddFormSectionTitlesAndSubmitLabel
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE forms ADD COLUMN IF NOT EXISTS sections JSONB NOT NULL DEFAULT '[]'::jsonb");
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS submit_label TEXT');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS submit_label');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS sections');
    }
}
