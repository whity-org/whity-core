<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddFormReferences — a reference somebody can quote.
 *
 * `forms.reference_format` is the wording the workspace chose, and NULL means this
 * form gives no references, so every existing form behaves as it did.
 * `form_submissions.reference` is the value one submission was given. It is stored
 * rather than derived, because the format may change later and a reference somebody
 * has already quoted must not.
 *
 * Unique per tenant, because a reference is how somebody finds one submission. Only
 * new submissions get one: an old one was never told a reference, so inventing one
 * now would quote nothing anybody holds.
 *
 * Additive and idempotent; down() drops the index and both columns.
 */
final class AddFormReferences
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS reference_format VARCHAR(64)');
        $db->exec('ALTER TABLE form_submissions ADD COLUMN IF NOT EXISTS reference VARCHAR(128)');
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_form_submissions_tenant_reference
                ON form_submissions(tenant_id, reference)'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS uq_form_submissions_tenant_reference');
        $db->exec('ALTER TABLE form_submissions DROP COLUMN IF EXISTS reference');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS reference_format');
    }
}
