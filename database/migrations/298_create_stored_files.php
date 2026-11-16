<?php

declare(strict_types=1);

namespace Database\Migrations;

use RuntimeException;
use Whity\Database\Database;

/**
 * CreateStoredFiles — the one file store (merge map plumbing, WHIT-1159).
 *
 * `stored_files` records every object core keeps in file storage: its
 * workspace, which feature owns it (`kind`), its storage key, type, size,
 * SHA-256, the person's own file name and who stored it. Form uploads, document
 * source files, document artifacts and chat attachments each get a
 * `stored_file_id` pointing at their file.
 *
 * Backfill, safe to re-run, and it fails loudly if any row is left
 * without a stored_file_id: one `stored_files` row per stored object already
 * recorded (an upload claimed onto a document is one object, one row), then
 * each owner row is pointed at it. Files don't move. On app.whity.dev that is
 * 4 uploads, 2 source files, 11 artifacts and no chat attachments.
 *
 * The owners' own copies of the file's details stay for now and go in a
 * later migration, once every reader reads them here. Table name spelled out
 * literally for TenantOwnedTablesTest and CoreTablesTest. Idempotent and
 * reversible.
 */
final class CreateStoredFiles
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS stored_files (
                id                    BIGSERIAL     PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                kind                  VARCHAR(32)   NOT NULL,
                storage_key           VARCHAR(512)  NOT NULL,
                content_type          VARCHAR(128)  NOT NULL,
                byte_size             BIGINT        NOT NULL,
                checksum_sha256       VARCHAR(64),
                filename              VARCHAR(255),
                created_by_profile_id INTEGER       REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, storage_key)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_stored_files_tenant_kind ON stored_files (tenant_id, kind, created_at)');

        $db->exec('ALTER TABLE form_uploads ADD COLUMN IF NOT EXISTS stored_file_id BIGINT REFERENCES stored_files(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE document_source_files ADD COLUMN IF NOT EXISTS stored_file_id BIGINT REFERENCES stored_files(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE document_artifacts ADD COLUMN IF NOT EXISTS stored_file_id BIGINT REFERENCES stored_files(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE chat_attachments ADD COLUMN IF NOT EXISTS stored_file_id BIGINT REFERENCES stored_files(id) ON DELETE SET NULL');

        // Unscoped by design (migration): every workspace's files are recorded.
        // Artifacts first: an upload claimed onto a document shares its key,
        // and the artifact is the record that outlives the upload.
        $db->exec("
            INSERT INTO stored_files (tenant_id, kind, storage_key, content_type, byte_size, checksum_sha256, filename, created_by_profile_id, created_at)
            SELECT a.tenant_id, 'document_artifact', a.storage_key, a.content_type, a.byte_size, a.checksum_sha256, NULL, a.rendered_by, a.rendered_at
              FROM document_artifacts a
             WHERE NOT EXISTS (SELECT 1 FROM stored_files f WHERE f.tenant_id = a.tenant_id AND f.storage_key = a.storage_key)
        ");
        $db->exec("
            INSERT INTO stored_files (tenant_id, kind, storage_key, content_type, byte_size, checksum_sha256, filename, created_by_profile_id, created_at)
            SELECT u.tenant_id, 'form_upload', u.storage_key, u.content_type, u.byte_size, u.checksum_sha256, u.client_filename, u.uploaded_by_profile_id, u.created_at
              FROM form_uploads u
             WHERE NOT EXISTS (SELECT 1 FROM stored_files f WHERE f.tenant_id = u.tenant_id AND f.storage_key = u.storage_key)
        ");
        $db->exec("
            INSERT INTO stored_files (tenant_id, kind, storage_key, content_type, byte_size, checksum_sha256, filename, created_by_profile_id, created_at)
            SELECT s.tenant_id, 'document_source', s.storage_key, s.content_type, s.byte_size, s.checksum_sha256, s.filename, s.uploaded_by_profile_id, s.created_at
              FROM document_source_files s
             WHERE NOT EXISTS (SELECT 1 FROM stored_files f WHERE f.tenant_id = s.tenant_id AND f.storage_key = s.storage_key)
        ");
        $db->exec("
            INSERT INTO stored_files (tenant_id, kind, storage_key, content_type, byte_size, checksum_sha256, filename, created_by_profile_id, created_at)
            SELECT c.tenant_id, 'chat_attachment', c.storage_key, c.content_type, c.byte_size, NULL, c.filename, c.uploaded_by_profile_id, c.created_at
              FROM chat_attachments c
             WHERE NOT EXISTS (SELECT 1 FROM stored_files f WHERE f.tenant_id = c.tenant_id AND f.storage_key = c.storage_key)
        ");

        foreach (['document_artifacts', 'form_uploads', 'document_source_files', 'chat_attachments'] as $table) {
            $db->exec("
                UPDATE {$table} SET stored_file_id = (
                    SELECT f.id FROM stored_files f WHERE f.tenant_id = {$table}.tenant_id AND f.storage_key = {$table}.storage_key
                ) WHERE stored_file_id IS NULL
            ");
        }

        // Every row must now point at its file. One that doesn't stops the
        // upgrade here, named, rather than leaving a file only half recorded
        // for migration 299 to drop the details of.
        $unlinked = [];
        foreach (['document_artifacts', 'form_uploads', 'document_source_files', 'chat_attachments'] as $table) {
            $left = $db->getPdo()->query("SELECT COUNT(*) FROM {$table} WHERE stored_file_id IS NULL");
            $count = $left !== false ? (int) $left->fetchColumn() : 0;
            if ($left !== false) {
                $left->closeCursor();
            }
            if ($count > 0) {
                $unlinked[] = "{$count} in {$table}";
            }
        }
        if ($unlinked !== []) {
            throw new RuntimeException(
                'Some stored files could not be recorded in stored_files (' . implode(', ', $unlinked) . '). '
                . 'Nothing in file storage was changed. Fix those rows and run the upgrade again.'
            );
        }
    }

    public static function down(Database $db): void
    {
        foreach (['form_uploads', 'document_source_files', 'document_artifacts', 'chat_attachments'] as $table) {
            $db->exec("ALTER TABLE {$table} DROP COLUMN IF EXISTS stored_file_id");
        }
        $db->exec('DROP TABLE IF EXISTS stored_files');
    }
}
