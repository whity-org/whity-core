<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Comment threads on an editor document (editor M5; E.6, E.39;
 * IMPLEMENTATION-NOTES 3.1).
 *
 * A thread is anchored to a block and the text it quotes; the editor draws the
 * comment highlight by finding the quote in that block, on screen only, so
 * nothing about a comment is ever stored in the draft or printed. A thread
 * whose text was deleted keeps its quote.
 *
 * `updated_at` moves with every reply, resolve and reopen, so GET .../changes
 * can say which threads changed since a cursor.
 *
 * Tenant-owned (CoreTables, TenantOwnedTables): every query binds tenant_id.
 * Idempotent and reversible.
 */
class CreateDocumentCommentThreads
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_comment_threads (
                id                     BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                source_id              BIGINT       NOT NULL REFERENCES document_sources(id) ON DELETE CASCADE,
                anchor_block_id        VARCHAR(64)  NOT NULL,
                quote                  TEXT         NOT NULL,
                created_by_profile_id  INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                resolved_by_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                resolved_at            TIMESTAMP,
                created_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_comment_threads_source ON document_comment_threads(tenant_id, source_id, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_comment_threads_updated ON document_comment_threads(tenant_id, source_id, updated_at)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_comment_threads CASCADE');
    }
}
