<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddDocumentRevisions (WHIT-888, #1625): a new document that replaces another.
 *
 * Migration 108 names the gap: a corrected document as a second row "points at the
 * superseded thing, with nothing joining the two". A correction stays what 108 made
 * it, more bytes on the same record. A revision is the other case, new values from the
 * record as it is now, so it is a new document and `supersedes_id` is the join.
 *
 * WHY THIS `revision` IS NOT THE ONE 108 REFUSED. 108 refuses a per-document artifact
 * counter computed as max+1, because two concurrent renders race and artifact ids
 * already give the order. Here the unique index on `supersedes_id` settles the race:
 * two people revising one document can't both win, so the next value can't be taken
 * twice. It is stored as a number and shown as a letter (1 = A).
 *
 * A document's number is shared by its revisions, so "one number names one document"
 * becomes "one number and revision". `superseded` stays derived: a document is replaced
 * when another row says it replaces it, with no status column.
 *
 * Idempotent and reversible. down() clears the number on later revisions, because the
 * older index can't hold two rows with one number.
 */
final class AddDocumentRevisions
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS supersedes_id BIGINT REFERENCES documents(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS revision INTEGER NOT NULL DEFAULT 1');
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS revision_reason TEXT');

        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_documents_tenant_supersedes
             ON documents(tenant_id, supersedes_id) WHERE supersedes_id IS NOT NULL'
        );
        $db->exec('DROP INDEX IF EXISTS uq_documents_tenant_number');
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_documents_tenant_number_revision
             ON documents(tenant_id, number, revision) WHERE number IS NOT NULL'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS uq_documents_tenant_number_revision');
        $db->exec('UPDATE documents SET number = NULL WHERE revision > 1');
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_documents_tenant_number
             ON documents(tenant_id, number) WHERE number IS NOT NULL'
        );
        $db->exec('DROP INDEX IF EXISTS uq_documents_tenant_supersedes');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS revision_reason');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS revision');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS supersedes_id');
    }
}
