<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Web push: the devices a person turned notifications on for (WHIT-1073).
 *
 * One row is one browser on one device, for one person in one workspace. The
 * browser's push service gives an `endpoint` URL and two keys: `p256dh` (the
 * browser's public key, 65 bytes) and `auth` (a 16-byte secret). A message is
 * encrypted to those keys (RFC 8291) and posted to the endpoint; nobody but
 * that browser can read it, and the push service only sees ciphertext.
 *
 * The same browser can be on in two workspaces, so the endpoint is unique per
 * workspace, not across the table. It is matched by `endpoint_hash` (SHA-256
 * hex) because an endpoint can be longer than an index entry allows.
 *
 * `last_success_at` / `last_failure_at` / `failures` let the sender drop a
 * device the push service says is gone (404 or 410) and show the person when
 * each device last got one. Nothing is created here: rows appear only when a
 * person turns notifications on, so a deploy sends nothing for past events.
 *
 * Tenant-owned; the name is spelled out literally because TenantOwnedTablesTest
 * and CoreTablesTest re-derive their registries by scanning this source.
 * Idempotent and reversible.
 */
final class CreatePushSubscriptions
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id               SERIAL PRIMARY KEY,
                tenant_id        INTEGER       NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                profile_id       INTEGER       NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                endpoint         VARCHAR(2048) NOT NULL,
                endpoint_hash    CHAR(64)      NOT NULL,
                p256dh           VARCHAR(128)  NOT NULL,
                auth             VARCHAR(64)   NOT NULL,
                user_agent       VARCHAR(512)  NOT NULL DEFAULT \'\',
                failures         INTEGER       NOT NULL DEFAULT 0,
                last_success_at  TIMESTAMP,
                last_failure_at  TIMESTAMP,
                created_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_push_subscriptions_tenant_endpoint ON push_subscriptions(tenant_id, endpoint_hash)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_push_subscriptions_tenant_profile ON push_subscriptions(tenant_id, profile_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS push_subscriptions');
    }
}
