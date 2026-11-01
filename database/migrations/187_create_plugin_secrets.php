<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Plugin secrets (WHIT-838, SDK 1.60): what a plugin keeps that must never be
 * read back, per workspace.
 *
 * - `plugin`: the plugin's slug, the same that prefixes its permissions.
 * - `secret_key`: the plugin's own name for the secret.
 * - `value`: encrypted with the platform key (`<keyId>:<ciphertext>`, see
 *   EncryptedSecretStore); never the plain value.
 *
 * Not in tenant_settings on purpose: settings are read back on admin screens
 * and over the API, secrets never are.
 */
class CreatePluginSecrets
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plugin_secrets (
                id          BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin      VARCHAR(64)  NOT NULL,
                secret_key  VARCHAR(128) NOT NULL,
                value       TEXT         NOT NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, plugin, secret_key)
            )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_secrets');
    }
}
