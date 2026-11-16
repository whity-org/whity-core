<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * One chat, bots, blocking and mute (design 3.5, UC.4, UC.8, UC.12,
 * WHIT-1097).
 *
 * - `chat_bots`: the rules a workspace sets in Settings › Chat. A bot follows
 *   its rule (an away reply, a welcome message, handing new conversations to
 *   whoever is next) and never decides anything.
 * - `chat_blocked_senders`: a customer's number or address that an admin
 *   blocked. What they send is dropped before it reaches anyone; past
 *   messages stay. `address_key` is the address as compared (lower case).
 * - `chat_person_state.muted`: a person muted this chat; mentions still reach
 *   them.
 */
final class CreateChatBotsAndBlocking
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_bots (
                tenant_id             INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                kind                  VARCHAR(16) NOT NULL,
                enabled               BOOLEAN     NOT NULL DEFAULT FALSE,
                settings              TEXT        NOT NULL DEFAULT '{}',
                updated_by_profile_id INTEGER     REFERENCES profiles(id) ON DELETE SET NULL,
                updated_at            TIMESTAMP   NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, kind),
                CONSTRAINT chat_bots_kind_check CHECK (kind IN ('away', 'welcome', 'handover'))
            )
        ");

        $db->exec('
            CREATE TABLE IF NOT EXISTS chat_blocked_senders (
                id                    BIGSERIAL    PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                channel               VARCHAR(140) NOT NULL,
                address               VARCHAR(320) NOT NULL,
                address_key           VARCHAR(320) NOT NULL,
                name                  VARCHAR(255),
                reported_spam         BOOLEAN      NOT NULL DEFAULT FALSE,
                blocked_by_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS chat_blocked_senders_unique ON chat_blocked_senders (tenant_id, channel, address_key)');

        $db->exec('ALTER TABLE chat_person_state ADD COLUMN IF NOT EXISTS muted BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE chat_person_state DROP COLUMN IF EXISTS muted');
        $db->exec('DROP TABLE IF EXISTS chat_blocked_senders');
        $db->exec('DROP TABLE IF EXISTS chat_bots');
    }
}
