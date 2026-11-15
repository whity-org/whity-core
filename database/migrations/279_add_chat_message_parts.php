<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * One chat, the parts of a message (design 3.5, UC.6–UC.12, WHIT-1094).
 *
 * On `messages` (team chat lines; customer messages leave them empty):
 * - `reply_to_message_id`: the message this one answers, quoted above it.
 * - `thread_root_id`: the message whose thread this reply is in; `in_chat` is
 *   false for a thread reply that was not also sent to the chat.
 * - `edited_at`, `deleted_at`: "edited" and "This message was deleted". A
 *   deleted message keeps its row and loses its words.
 * - `pinned_at`, `pinned_by_profile_id`: pinned to the top of the chat, for
 *   everyone in it.
 * - `changed_at`: when anything above, a reaction or the thread under it last
 *   changed, so an open chat picks up changes to older messages.
 *
 * `chat_message_reactions`: one row per person, message and emoji.
 *
 * `profiles.chat_layout`: bubbles (the default) or compact rows, each person's
 * own choice (UC.7).
 */
final class AddChatMessageParts
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS reply_to_message_id BIGINT REFERENCES messages(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS thread_root_id BIGINT REFERENCES messages(id) ON DELETE CASCADE');
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS in_chat BOOLEAN NOT NULL DEFAULT TRUE');
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS edited_at TIMESTAMP');
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP');
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS pinned_at TIMESTAMP');
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS pinned_by_profile_id INTEGER REFERENCES profiles(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS changed_at TIMESTAMP');
        $db->exec('CREATE INDEX IF NOT EXISTS messages_thread_idx ON messages (tenant_id, thread_root_id, id) WHERE thread_root_id IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS messages_changed_idx ON messages (tenant_id, conversation_id, changed_at) WHERE changed_at IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS messages_pinned_idx ON messages (tenant_id, conversation_id) WHERE pinned_at IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS chat_message_reactions (
                tenant_id   INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                message_id  BIGINT      NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
                profile_id  INTEGER     NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                emoji       VARCHAR(32) NOT NULL,
                created_at  TIMESTAMP   NOT NULL DEFAULT NOW(),
                PRIMARY KEY (message_id, profile_id, emoji)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS chat_message_reactions_tenant_idx ON chat_message_reactions (tenant_id, message_id)');

        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS chat_layout VARCHAR(8) NOT NULL DEFAULT 'bubbles'");
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS chat_layout');
        $db->exec('DROP TABLE IF EXISTS chat_message_reactions');
        $db->exec('DROP INDEX IF EXISTS messages_pinned_idx');
        $db->exec('DROP INDEX IF EXISTS messages_changed_idx');
        $db->exec('DROP INDEX IF EXISTS messages_thread_idx');
        foreach (['changed_at', 'pinned_by_profile_id', 'pinned_at', 'deleted_at', 'edited_at', 'in_chat', 'thread_root_id', 'reply_to_message_id'] as $column) {
            $db->exec("ALTER TABLE messages DROP COLUMN IF EXISTS {$column}");
        }
    }
}
