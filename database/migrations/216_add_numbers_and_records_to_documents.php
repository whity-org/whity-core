<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddNumbersAndRecordsToDocuments (WHIT-684, #1624): what people quote a document
 * by, what it was issued from, and how many pages it has.
 *
 * `number` is what a customer reads off the paper. It is stored and never
 * recomputed: the year in it is the year it was handed out. `number_pattern` is
 * the rule in force when the document was raised, so a document waiting for final
 * approval gets the number its rule promised even if the template's rule changes
 * while it waits, and `number_assigned_at` is the claim that makes handing one out
 * happen exactly once ({@see \Whity\Core\Document\Numbering\DocumentNumbering}).
 *
 * A document with a pattern and no number is waiting for final approval. That is a
 * fact about numbering, not a stored status: migration 108's reasoning stands, and
 * a document's state stays derived from the routing trail
 * ({@see \Whity\Core\Document\DocumentState}).
 *
 * `record_type` and `record_id` are the business record the document was issued
 * from, kept apart from `plugin_document_links` (migration 214), which says which
 * plugin issued it. `slot` is the template slot it was issued through.
 *
 * `document_sources.numbering` is a template's own rule. `document_artifacts.pages`
 * is the page count the render service reported for those bytes.
 *
 * Every column is nullable and nothing is backfilled: a document issued before
 * this has no number and no record, and keeps working.
 *
 * Idempotent and reversible.
 */
final class AddNumbersAndRecordsToDocuments
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS number VARCHAR(128)');
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS number_pattern VARCHAR(64)');
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS number_assigned_at TIMESTAMP');
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS record_type VARCHAR(160)');
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS record_id VARCHAR(64)');
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS slot VARCHAR(160)');
        $db->exec('ALTER TABLE document_artifacts ADD COLUMN IF NOT EXISTS pages INTEGER');
        $db->exec('ALTER TABLE document_sources ADD COLUMN IF NOT EXISTS numbering JSONB');

        // The backstop under the counter: a number names one document.
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_documents_tenant_number
             ON documents(tenant_id, number) WHERE number IS NOT NULL'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_documents_tenant_record
             ON documents(tenant_id, record_type, record_id) WHERE record_id IS NOT NULL'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_documents_tenant_slot
             ON documents(tenant_id, slot) WHERE slot IS NOT NULL'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_documents_tenant_source
             ON documents(tenant_id, document_source_id) WHERE document_source_id IS NOT NULL'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_documents_tenant_source');
        $db->exec('DROP INDEX IF EXISTS idx_documents_tenant_slot');
        $db->exec('DROP INDEX IF EXISTS idx_documents_tenant_record');
        $db->exec('DROP INDEX IF EXISTS uq_documents_tenant_number');
        $db->exec('ALTER TABLE document_sources DROP COLUMN IF EXISTS numbering');
        $db->exec('ALTER TABLE document_artifacts DROP COLUMN IF EXISTS pages');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS slot');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS record_id');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS record_type');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS number_assigned_at');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS number_pattern');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS number');
    }
}
