<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Messages (ADR 0016 §1, WHIT-938): a workspace's conversations with its
 * customers, and the messages in them.
 *
 * One conversation is one thread on one channel with one contact. `channel` is
 * core's `email` or `webchat`, or a plugin's namespaced channel such as
 * `acme:whatsapp`; it is free text here because plugin channels come and go
 * with their plugins and a conversation outlives an uninstall.
 *
 * `contact_address` is the address on that channel (an email address, a phone
 * number, a web chat visitor id). `customer_profile_id` is set when the contact
 * is a signed-in customer of the workspace; there is no contacts table
 * (ADR 0016, alternatives).
 *
 * A message is `inbound` (the customer wrote), `outbound` (the workspace wrote)
 * or `note` (staff only, never sent). `external_id` is the channel's own id
 * (an email Message-ID, a provider's message id): replies and delivery updates
 * are matched on it, so it is unique per workspace and channel conversation.
 * `send_after` holds an agent's reply back for its undo window.
 */
final class CreateConversationsAndMessages
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS conversations (
                id                  BIGSERIAL PRIMARY KEY,
                tenant_id           INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                channel             VARCHAR(140) NOT NULL,
                subject             VARCHAR(255),
                status              VARCHAR(16)  NOT NULL DEFAULT \'open\',
                assigned_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                customer_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                contact_name        VARCHAR(255),
                contact_address     VARCHAR(320) NOT NULL,
                last_message_at     TIMESTAMP    NOT NULL DEFAULT NOW(),
                last_inbound_at     TIMESTAMP,
                created_at          TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMP    NOT NULL DEFAULT NOW(),
                closed_at           TIMESTAMP,
                CONSTRAINT conversations_status_check CHECK (status IN (\'open\', \'pending\', \'closed\'))
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS conversations_inbox_idx ON conversations (tenant_id, status, last_message_at DESC, id DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS conversations_contact_idx ON conversations (tenant_id, channel, contact_address)');
        $db->exec('CREATE INDEX IF NOT EXISTS conversations_assignee_idx ON conversations (tenant_id, assigned_profile_id, status)');
        $db->exec('CREATE INDEX IF NOT EXISTS conversations_customer_idx ON conversations (tenant_id, customer_profile_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS messages (
                id                BIGSERIAL PRIMARY KEY,
                tenant_id         INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                conversation_id   BIGINT       NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
                kind              VARCHAR(16)  NOT NULL,
                author_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                by_agent          BOOLEAN      NOT NULL DEFAULT FALSE,
                source            VARCHAR(64),
                body_text         TEXT         NOT NULL,
                body_html         TEXT,
                external_id       VARCHAR(512),
                in_reply_to       VARCHAR(512),
                status            VARCHAR(16)  NOT NULL,
                status_detail     VARCHAR(500),
                attachments_not_shown INTEGER  NOT NULL DEFAULT 0,
                send_after        TIMESTAMP,
                sent_at           TIMESTAMP,
                created_at        TIMESTAMP    NOT NULL DEFAULT NOW(),
                CONSTRAINT messages_kind_check CHECK (kind IN (\'inbound\', \'outbound\', \'note\')),
                CONSTRAINT messages_status_check CHECK (status IN (\'received\', \'queued\', \'sent\', \'delivered\', \'read\', \'failed\', \'cancelled\', \'noted\'))
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS messages_conversation_idx ON messages (tenant_id, conversation_id, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS messages_external_idx ON messages (tenant_id, external_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS messages_external_unique ON messages (tenant_id, conversation_id, external_id) WHERE external_id IS NOT NULL');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS messages');
        $db->exec('DROP TABLE IF EXISTS conversations');
    }
}
