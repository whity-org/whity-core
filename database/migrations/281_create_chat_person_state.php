<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * One chat (design 3.5, UC.1, WHIT-1093): each person's own state on any
 * conversation in Chat, team chats and customer conversations alike.
 *
 * - `pinned_at`: the chat sits under Pinned at the top of that person's list.
 * - `last_read_message_id`: how far that person has read a CUSTOMER
 *   conversation. Team chats keep theirs on `chat_members`, where the agents'
 *   read-from marks also live; customer conversations have no members, so the
 *   unread count of the one list needs a mark per person here.
 *
 * A row exists only once a person pins or reads; no row means not pinned and
 * nothing read.
 */
final class CreateChatPersonState
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS chat_person_state (
                tenant_id            INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                conversation_id      BIGINT    NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
                profile_id           INTEGER   NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                pinned_at            TIMESTAMP,
                -- @reference-lint-ignore: a read mark, a position in the conversation\'s message ids, not a message
                last_read_message_id BIGINT    NOT NULL DEFAULT 0,
                updated_at           TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (conversation_id, profile_id)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS chat_person_state_profile_idx ON chat_person_state (tenant_id, profile_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS chat_person_state');
    }
}
