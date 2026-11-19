<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Who took a person out of a chat (WHIT-1266, #2231), next to `removed_at` on
 * `chat_members`.
 *
 *   removed_by_profile_id  the person who removed them, or their own id when
 *                          they left. NULL on rows still in the chat, and on
 *                          rows removed before this column existed.
 *
 * "Discuss in chat" joins the caller to a record's chat. Someone the chat's
 * starter removed must not walk back in that way; someone who left may. This
 * column is how the two are told apart.
 *
 * A plain id, not a foreign key: it is only compared with the person asking,
 * and a constraint would check every row under a lock (migration lint).
 *
 * Additive and nullable, so nothing to backfill. Idempotent and reversible.
 */
class AddRemovedByToChatMembers
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE chat_members ADD COLUMN IF NOT EXISTS removed_by_profile_id INTEGER');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE chat_members DROP COLUMN IF EXISTS removed_by_profile_id');
    }
}
