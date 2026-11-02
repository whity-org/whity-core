<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddFormStaffFieldsAndReviews — questions the applicant is never asked, and where
 * the answers to them go.
 *
 * `form_fields.audience` says who answers a question. `applicant` is the default,
 * so every existing field keeps behaving as it did. A `staff` field is left out of
 * what a fill page is given and of what a submission is checked against.
 *
 * WHY THE ANSWERS DO NOT GO IN `form_submissions.data`
 * ----------------------------------------------------
 * A submission is what somebody declared at a moment under their own name, and
 * migration 127 refuses it any way of being edited. Writing the office's decision
 * into it would make it an edited record, and would put the office's notes one
 * careless `SELECT data` away from the person they are about. So a review is its
 * own row, keyed to the submission.
 *
 * APPEND-ONLY. Changing a decision adds a row, and the newest row is the current
 * one. Who decided what, and when, is the point of recording it, and an updated row
 * could not say that. The shape is migration 118's trail, for the same reason.
 *
 * Idempotent and reversible. The permission is migration 195's, kept separate so
 * the schema change and the authorization change are two reviewable acts.
 */
final class AddFormStaffFieldsAndReviews
{
    public static function up(Database $db): void
    {
        $db->exec(
            "ALTER TABLE form_fields ADD COLUMN IF NOT EXISTS audience VARCHAR(16) NOT NULL DEFAULT 'applicant'
                 CHECK (audience IN ('applicant', 'staff'))"
        );

        // ON DELETE SET NULL on the recorder: a person leaving must not erase what
        // they decided, and the same choice migration 127 makes for the submitter.
        $db->exec("
            CREATE TABLE IF NOT EXISTS form_submission_reviews (
                id                     BIGSERIAL NOT NULL PRIMARY KEY,
                tenant_id              INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                submission_id          BIGINT    NOT NULL REFERENCES form_submissions(id) ON DELETE CASCADE,
                data                   JSONB     NOT NULL DEFAULT '{}'::jsonb,
                recorded_by_profile_id INTEGER   REFERENCES profiles(id) ON DELETE SET NULL,
                recorded_at            TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_submission_reviews_tenant_submission
                ON form_submission_reviews(tenant_id, submission_id, id DESC)'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS form_submission_reviews CASCADE');
        $db->exec('ALTER TABLE form_fields DROP COLUMN IF EXISTS audience');
    }
}
