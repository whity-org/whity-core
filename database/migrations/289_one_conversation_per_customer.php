<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * OneConversationPerCustomer — one chat across channels (owner Q35.2, merge
 * map C5, WHIT-1121).
 *
 * A customer who writes on WhatsApp and then by email is one conversation. So:
 *
 *  - **The channel moves to the message.** `messages.channel` and
 *    `messages.channel_address` say how each message came or went.
 *    `conversations.channel` / `contact_address` stay as the channel last used,
 *    for the list's badge and for older readers.
 *  - **`conversation_contacts`** maps an address on a channel to the
 *    conversation it belongs to, and to the contact it is, when known. Filing a
 *    message looks the customer up here first, whatever channel they wrote on.
 *
 * Backfill, safe to re-run:
 *  - every message gets its conversation's channel and address, where it has
 *    none yet;
 *  - each address on each channel maps to its most recent customer
 *    conversation, where it has no mapping yet.
 *
 * Existing conversations are not merged after the fact: two conversations a
 * customer already has stay two. On app.whity.dev that is 10 conversations and
 * 20 messages. Table name spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class OneConversationPerCustomer
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS channel VARCHAR(140)');
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS channel_address VARCHAR(320)');
        $db->exec('
            UPDATE messages SET
                channel = (SELECT c.channel FROM conversations c WHERE c.id = messages.conversation_id AND c.tenant_id = messages.tenant_id),
                channel_address = (SELECT c.contact_address FROM conversations c WHERE c.id = messages.conversation_id AND c.tenant_id = messages.tenant_id)
             WHERE channel IS NULL
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS messages_channel_idx ON messages (tenant_id, conversation_id, channel, id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS conversation_contacts (
                id              SERIAL PRIMARY KEY,
                tenant_id       INTEGER      NOT NULL REFERENCES tenants(id)       ON DELETE CASCADE,
                channel         VARCHAR(140) NOT NULL,
                address_key     VARCHAR(320) NOT NULL,
                conversation_id BIGINT       NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
                contact_id      INTEGER               REFERENCES contacts(id)      ON DELETE SET NULL,
                created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, channel, address_key)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS conversation_contacts_conversation_idx ON conversation_contacts (tenant_id, conversation_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS conversation_contacts_contact_idx ON conversation_contacts (tenant_id, contact_id)');

        $db->exec("
            INSERT INTO conversation_contacts (tenant_id, channel, address_key, conversation_id)
            SELECT c.tenant_id, c.channel, LOWER(c.contact_address), MAX(c.id)
              FROM conversations c
             WHERE c.kind = 'customer' AND c.contact_address <> ''
               AND NOT EXISTS (
                   SELECT 1 FROM conversation_contacts x
                    WHERE x.tenant_id = c.tenant_id AND x.channel = c.channel AND x.address_key = LOWER(c.contact_address))
             GROUP BY c.tenant_id, c.channel, LOWER(c.contact_address)
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS conversation_contacts');
        $db->exec('DROP INDEX IF EXISTS messages_channel_idx');
        $db->exec('ALTER TABLE messages DROP COLUMN IF EXISTS channel_address');
        $db->exec('ALTER TABLE messages DROP COLUMN IF EXISTS channel');
    }
}
