<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * MessagingSettingsIntoRegistry — a workspace's Messages settings join the
 * settings registry (merge map, plumbing; WHIT-1047).
 *
 * `messaging_settings` held four values per workspace beside every other
 * setting. They move into `tenant_settings`:
 *  - webchat_enabled → `messaging.webchat_enabled` ('true' / 'false');
 *  - webchat_greeting → `messaging.webchat_greeting`;
 *  - inbound_email_address → `messaging.inbound_email_address`;
 *  - inbound_secret (already encrypted) → `messaging.inbound_secret_encrypted`,
 *    an unregistered key that no settings read returns.
 * A value already in `tenant_settings` is kept, so re-running changes nothing.
 * Then the table goes (owner, 2026-11-16: no customers to carry). On
 * app.whity.dev that is 2 rows.
 *
 * Idempotent; down() brings the table back empty.
 */
final class MessagingSettingsIntoRegistry
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();
        $exists = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? $pdo->query("SELECT to_regclass('messaging_settings') IS NOT NULL")
            : $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'messaging_settings'");
        $found = $exists !== false ? $exists->fetchColumn() : false;
        if ($exists !== false) {
            $exists->closeCursor();
        }
        if (!in_array($found, [true, 1, '1', 't', 'true'], true)) {
            return;
        }

        // Unscoped by design (migration): every workspace's row moves.
        $rows = $pdo->query('SELECT tenant_id, webchat_enabled, webchat_greeting, inbound_email_address, inbound_secret FROM messaging_settings');
        $all = $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($rows !== false) {
            $rows->closeCursor();
        }
        $rows = null;
        if ($all !== []) {
            $put = $pdo->prepare(
                'INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at)
                 VALUES (:tenant_id, :key, :value, NOW())
                 ON CONFLICT (tenant_id, setting_key) DO NOTHING'
            );
            foreach ($all as $row) {
                $values = [
                    'messaging.webchat_enabled' => in_array($row['webchat_enabled'], [true, 1, '1', 't', 'true'], true) ? 'true' : 'false',
                    'messaging.webchat_greeting' => (string) ($row['webchat_greeting'] ?? ''),
                    'messaging.inbound_email_address' => (string) ($row['inbound_email_address'] ?? ''),
                    'messaging.inbound_secret_encrypted' => (string) ($row['inbound_secret'] ?? ''),
                ];
                foreach ($values as $key => $value) {
                    if ($value === '' && $key !== 'messaging.webchat_enabled') {
                        continue;
                    }
                    $put->execute([':tenant_id' => (int) $row['tenant_id'], ':key' => $key, ':value' => $value]);
                }
            }
        }

        $db->exec('DROP TABLE IF EXISTS messaging_settings');
    }

    public static function down(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS messaging_settings (
                tenant_id             INTEGER      PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
                webchat_enabled       BOOLEAN      NOT NULL DEFAULT FALSE,
                webchat_greeting      VARCHAR(500),
                inbound_email_address VARCHAR(320),
                inbound_secret        TEXT,
                updated_at            TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
    }
}
