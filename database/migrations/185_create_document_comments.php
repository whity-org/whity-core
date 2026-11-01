<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * The messages in a comment thread (editor M5; E.6, E.30;
 * IMPLEMENTATION-NOTES 3.1).
 *
 * - Actor columns: a person, or an agent replying for the person it acts for
 *   (E.30), told apart by `actor_kind`.
 * - `body`: the message as the contract's Inline[].
 * - `mentions`: the profile ids @mentioned, each told in-app when they can
 *   read the document.
 * - `edited_at`: set when a message is edited (not yet offered).
 *
 * `tenant_id` is denormalised from the thread so every read binds it directly
 * instead of trusting a join. Tenant-owned (CoreTables, TenantOwnedTables).
 * Idempotent and reversible.
 */
class CreateDocumentComments
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_comments (
                id                      BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id               INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                thread_id               BIGINT       NOT NULL REFERENCES document_comment_threads(id) ON DELETE CASCADE,
                created_by_profile_id   INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                actor_kind              VARCHAR(16)  NOT NULL DEFAULT 'person',
                agent_principal_id      VARCHAR(64),
                on_behalf_of_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                body                    JSONB        NOT NULL,
                mentions                JSONB        NOT NULL DEFAULT '[]'::jsonb,
                created_at              TIMESTAMP    NOT NULL DEFAULT NOW(),
                edited_at               TIMESTAMP
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_comments_thread ON document_comments(tenant_id, thread_id, id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_comments CASCADE');
    }
}
