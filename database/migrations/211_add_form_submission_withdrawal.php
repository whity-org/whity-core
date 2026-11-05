<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddFormSubmissionWithdrawal — a person can take back what they sent.
 *
 * The row is kept, as {@see \Whity\Core\Form\FormSubmissionRepository} promises a
 * submission always is: these two columns say that its author stopped standing behind
 * it, and when. Nobody gains anything on upgrade: both are NULL on every existing row.
 *
 * Additive and idempotent; down() drops the two columns.
 */
final class AddFormSubmissionWithdrawal
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE form_submissions ADD COLUMN IF NOT EXISTS withdrawn_at TIMESTAMP');
        $db->exec(
            'ALTER TABLE form_submissions ADD COLUMN IF NOT EXISTS withdrawn_by_profile_id INTEGER
                 REFERENCES profiles(id) ON DELETE SET NULL'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE form_submissions DROP COLUMN IF EXISTS withdrawn_by_profile_id');
        $db->exec('ALTER TABLE form_submissions DROP COLUMN IF EXISTS withdrawn_at');
    }
}
