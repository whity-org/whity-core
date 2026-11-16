<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * One chat, blocks inside messages (design 3.5, UC.5, WHIT-1096).
 *
 * A poll lives in its message's `chat_meta` (the question and the options);
 * the votes are here, one row per person and option, so counts stay live and
 * a person can change their vote. A Whity record in a message needs no table:
 * the message names the record and its status is read when the chat is.
 */
final class CreateChatPollVotes
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS chat_poll_votes (
                tenant_id     INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                message_id    BIGINT    NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
                profile_id    INTEGER   NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                option_index  SMALLINT  NOT NULL,
                created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (message_id, profile_id, option_index)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS chat_poll_votes_tenant_idx ON chat_poll_votes (tenant_id, message_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS chat_poll_votes');
    }
}
