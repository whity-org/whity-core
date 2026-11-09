<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddFormTitleAndBanner (WHIT-944, #1715): what the person filling in a form sees
 * at its top.
 *
 * `forms.title` is the heading written for that person, in a `{ar, en}` JSON object
 * like `name`. NULL means the form's name, which stays the name the workspace's own
 * people use in lists. The description was already written for the person filling
 * it in, so it doesn't get a second column.
 *
 * `forms.banner` is the picture above the title, or NULL for none:
 * - `{"kind": "pattern", "style": "waves", "seed": 4821}` is drawn in the browser
 *   from the workspace's main colour, so it follows the brand when the colour
 *   changes and never needs storing;
 * - `{"kind": "image", "name": "form-banner-7-1a2b3c4d5e6f7a8b.webp"}` is an
 *   uploaded file kept beside the workspace's branding assets.
 *
 * Additive and idempotent; down() drops both columns.
 */
final class AddFormTitleAndBanner
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS title TEXT');
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS banner JSONB');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS banner');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS title');
    }
}
