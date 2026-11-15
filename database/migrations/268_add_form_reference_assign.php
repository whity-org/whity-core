<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * When a form's references are given, and room for longer formats (WHIT-1017).
 *
 *  - `forms.reference_assign`: `submit` (what every form has done since
 *    migration 196, and the default) or `approval`, when the submission's
 *    route finishes approved. A reference given on approval can print an
 *    answer and a value a reviewer entered during review, such as a committee
 *    meeting number, and is on the final document.
 *  - `forms.reference_format` widens from 64 to 160 characters, for formats
 *    such as `{ANSWER:subject|human=IRB-AAU|animal=AUB-AAU} {SEQ}/{STEP:meeting_no}/{AY:9}`.
 *    PostgreSQL only: SQLite doesn't enforce VARCHAR length.
 *
 * Idempotent and reversible. down() narrows the format again only while no
 * stored format is longer than 64 characters.
 */
final class AddFormReferenceAssign
{
    public static function up(Database $db): void
    {
        $db->exec(
            "ALTER TABLE forms ADD COLUMN IF NOT EXISTS reference_assign VARCHAR(16) NOT NULL DEFAULT 'submit'
                 CHECK (reference_assign IN ('submit', 'approval'))"
        );

        if ($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $db->exec('ALTER TABLE forms ALTER COLUMN reference_format TYPE VARCHAR(160)');
        }
    }

    public static function down(Database $db): void
    {
        if ($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $long = (int) $db->getPdo()->query('SELECT COUNT(*) FROM forms WHERE LENGTH(reference_format) > 64')->fetchColumn();
            if ($long > 0) {
                throw new \RuntimeException(
                    'Cannot roll back migration 268: some forms have reference formats longer than 64 characters.'
                );
            }
            $db->exec('ALTER TABLE forms ALTER COLUMN reference_format TYPE VARCHAR(64)');
        }
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS reference_assign');
    }
}
