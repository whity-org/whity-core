<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddFormFieldConditions — "show this question only if ...".
 *
 * One nullable column rather than a rule table: a condition belongs to one
 * question, is read whole with it, and goes when it goes. NULL means always
 * shown, so every existing field keeps behaving as it did.
 * {@see \Whity\Core\Form\FieldCondition} owns the shape and its evaluation.
 *
 * Additive and idempotent; down() drops the column.
 */
final class AddFormFieldConditions
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE form_fields ADD COLUMN IF NOT EXISTS visible_when JSONB');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE form_fields DROP COLUMN IF EXISTS visible_when');
    }
}
