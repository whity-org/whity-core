<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddRouteFinalise (WHIT-921, #1725) — a finalise step that produces the final
 * PDF.
 *
 * A route may end with one `finalise` step. Nobody acts on it: when the route
 * reaches it, the engine makes the final document from the approved version of
 * what travelled (raising the document first for a subject that has none, such
 * as a form submission), numbers it, adds the verification QR and stores the
 * PDF on that document.
 *
 *  - `document_route_steps.final_template_id`: the document template a
 *    finalise step renders with. Copied onto the route when it is issued, like
 *    every other part of a step, so changing the design later does not change a
 *    route already running. No foreign key, for the same reason: a deleted
 *    template is reported when the step is reached, not hidden by a cascade.
 *  - `document_route_template_steps.kind` and `.final_template_id`: the same on
 *    a route design's stages. `kind` is NULL for every stage the older columns
 *    already describe and `finalise` for a finalise stage.
 *  - `forms.final_template_id`: the form setting "Final document template",
 *    which a finalise step on a submission route uses when the step names none.
 *    ON DELETE SET NULL: a form whose template was deleted has no default.
 *  - `forms.final_document_to_applicant`: whether the person who sent a form
 *    can open its final document from My submissions. TRUE by default.
 *  - `route_finalisations`: one row per route that reached its finalise step.
 *    It records what the engine did, which is not a person's act and so is not
 *    a trail entry: which version it was made from (with its SHA-256), the
 *    document and PDF it made, and whether it is still waiting (for example
 *    because the render service is down; a job tries again). Its status is the
 *    one thing that changes, from `waiting` to `done` or `failed` (and back to
 *    `waiting` when somebody asks for another try). `claim_token` names the one
 *    attempt allowed to finish it, so a slow render cannot be overtaken.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
final class AddRouteFinalise
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_steps ADD COLUMN IF NOT EXISTS final_template_id BIGINT');

        $db->exec(
            "ALTER TABLE document_route_template_steps ADD COLUMN IF NOT EXISTS kind VARCHAR(16)
                 CHECK (kind IS NULL OR kind IN ('review', 'decide', 'deliver', 'sign', 'stamp', 'finalise'))"
        );
        $db->exec('ALTER TABLE document_route_template_steps ADD COLUMN IF NOT EXISTS final_template_id BIGINT');

        $db->exec(
            'ALTER TABLE forms ADD COLUMN IF NOT EXISTS final_template_id BIGINT
                 REFERENCES document_sources(id) ON DELETE SET NULL'
        );
        $db->exec(
            'ALTER TABLE forms ADD COLUMN IF NOT EXISTS final_document_to_applicant BOOLEAN NOT NULL DEFAULT TRUE'
        );

        $db->exec("
            CREATE TABLE IF NOT EXISTS route_finalisations (
                id              BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id       INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                route_id        BIGINT       NOT NULL REFERENCES document_routes(id) ON DELETE CASCADE,
                step_id         BIGINT       NOT NULL REFERENCES document_route_steps(id) ON DELETE CASCADE,
                subject_type    VARCHAR(64)  NOT NULL,
                subject_id      BIGINT       NOT NULL,
                template_id     BIGINT,
                subject_version INTEGER,
                content_hash    VARCHAR(64),
                status          VARCHAR(16)  NOT NULL DEFAULT 'waiting'
                                CHECK (status IN ('waiting', 'done', 'failed')),
                reason          VARCHAR(32),
                document_id     BIGINT       REFERENCES documents(id) ON DELETE SET NULL,
                artifact_id     BIGINT       REFERENCES document_artifacts(id) ON DELETE SET NULL,
                attempts        INTEGER      NOT NULL DEFAULT 0,
                attempt_started_at TIMESTAMP,
                claim_token     VARCHAR(64),
                reached_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                finalised_at    TIMESTAMP,
                UNIQUE (route_id)
            )
        ");
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_route_finalisations_subject
                ON route_finalisations(tenant_id, subject_type, subject_id)'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_route_finalisations_subject');
        $db->exec('DROP TABLE IF EXISTS route_finalisations');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS final_document_to_applicant');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS final_template_id');
        $db->exec('ALTER TABLE document_route_template_steps DROP COLUMN IF EXISTS final_template_id');
        $db->exec('ALTER TABLE document_route_template_steps DROP COLUMN IF EXISTS kind');
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS final_template_id');
    }
}
