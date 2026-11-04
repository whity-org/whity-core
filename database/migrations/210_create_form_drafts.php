<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateFormDrafts — answers somebody hasn't sent yet, kept on the server so they can
 * carry on from another device (F.2).
 *
 * One row per person per form. A draft is not a submission: nobody has declared it, it
 * isn't validated, and nobody but its owner reads it. Sending the form deletes it in
 * the same transaction.
 *
 * ON DELETE CASCADE from the form and the profile, unlike a submission. A draft is
 * nobody's record of anything, so nothing is lost with either. Answers to `file`
 * questions are never kept, because an upload reference is single-use and expires.
 *
 * Idempotent and reversible.
 */
final class CreateFormDrafts
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS form_drafts (
                id         BIGSERIAL NOT NULL PRIMARY KEY,
                tenant_id  INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                form_id    BIGINT    NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
                profile_id INTEGER   NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                data       JSONB     NOT NULL DEFAULT '{}'::jsonb,
                step       INTEGER,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (form_id, profile_id)
            )
        ");
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_drafts_tenant_profile
                ON form_drafts(tenant_id, profile_id, updated_at DESC)'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS form_drafts CASCADE');
    }
}
