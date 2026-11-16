<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * One chat, files, photos and voice (design 3.5, UC.10, WHIT-1095).
 *
 * A file is uploaded to a chat first (`message_id` empty) and joins a message
 * when the message is sent, so the person sees progress and can try again
 * without anything being lost. Photos sent together form an album.
 *
 * - `kind`: image, video, file or voice.
 * - `storage_key`: where the bytes are, on the workspace's storage; never shown.
 * - `duration_ms`, `transcript`: a voice message's length and its text (UC.10).
 * - `width`, `height`: a photo's size, so the album keeps its shape while loading.
 */
final class CreateChatAttachments
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_attachments (
                id                     BIGSERIAL    PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                conversation_id        BIGINT       NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
                message_id             BIGINT       REFERENCES messages(id) ON DELETE CASCADE,
                uploaded_by_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                kind                   VARCHAR(8)   NOT NULL,
                storage_key            VARCHAR(512) NOT NULL,
                content_type           VARCHAR(127) NOT NULL,
                byte_size              BIGINT       NOT NULL,
                filename               VARCHAR(255),
                width                  INTEGER,
                height                 INTEGER,
                duration_ms            INTEGER,
                transcript             TEXT,
                created_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                CONSTRAINT chat_attachments_kind_check CHECK (kind IN ('image', 'video', 'file', 'voice'))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS chat_attachments_message_idx ON chat_attachments (tenant_id, message_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS chat_attachments_chat_idx ON chat_attachments (tenant_id, conversation_id, kind)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS chat_attachments');
    }
}
