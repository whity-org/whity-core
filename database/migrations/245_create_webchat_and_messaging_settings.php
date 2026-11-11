<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Web chat visitors and per-workspace Messages settings (ADR 0016 §4-5,
 * WHIT-938).
 *
 * `webchat_visitors`: one row per chat a visitor starts. The browser keeps a
 * random bearer token; only its SHA-256 is stored, so a database read cannot
 * be replayed as a visitor. `profile_id` is set when a signed-in customer
 * chats. `ip_hash` is a keyed hash, for the open-chats-per-address cap, never
 * the address itself.
 *
 * `messaging_settings`: whether web chat is on, the inbound address replies
 * go to, and the inbound webhook secret, encrypted with the instance key
 * (EncryptedSecretStore). A workspace with no row has web chat off and no
 * inbound email.
 */
final class CreateWebchatAndMessagingSettings
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS webchat_visitors (
                id              BIGSERIAL PRIMARY KEY,
                tenant_id       INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                conversation_id BIGINT       NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
                token_hash      CHAR(64)     NOT NULL,
                profile_id      INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                display_name    VARCHAR(120),
                email           VARCHAR(320),
                ip_hash         CHAR(64),
                created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                last_seen_at    TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS webchat_visitors_token_unique ON webchat_visitors (token_hash)');
        $db->exec('CREATE INDEX IF NOT EXISTS webchat_visitors_ip_idx ON webchat_visitors (tenant_id, ip_hash, created_at)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS messaging_settings (
                tenant_id              INTEGER      PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
                webchat_enabled        BOOLEAN      NOT NULL DEFAULT FALSE,
                webchat_greeting       VARCHAR(500),
                inbound_email_address  VARCHAR(320),
                inbound_secret         TEXT,
                updated_at             TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS messaging_settings');
        $db->exec('DROP TABLE IF EXISTS webchat_visitors');
    }
}
