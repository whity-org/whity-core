<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\Db\DbBool;
use Whity\Database\Database;

/**
 * Notification sender settings move into the settings registry (WHIT-1036).
 *
 * Each `tenant_notification_settings` row becomes the `tenant_settings` keys
 * `notifications.<channel>.<field>`: enabled ('true' or 'false'), and, when
 * set, transport, from_address, from_name, reply_to, config (a JSON object)
 * and credentials_encrypted (copied as EncryptedSecretStore wrote it, never
 * decrypted here). A key the workspace already holds wins (ON CONFLICT DO
 * NOTHING), so a second run changes nothing.
 *
 * The old table is left in place, unread, for one release so a rollback to
 * the previous build still finds its settings; a later migration drops it.
 * down() removes the keys; the old table still holds what it held.
 */
final class MoveNotificationSettingsIntoRegistry
{
    public static function up(Database $db): void
    {
        $rows = $db->query(
            'SELECT tenant_id, channel, transport, from_address, from_name, reply_to, config, credentials_encrypted, enabled, updated_at
               FROM tenant_notification_settings'
        )->fetchAll();

        foreach ($rows as $row) {
            $channel = (string) $row['channel'];
            if (preg_match('/^[a-z0-9_]{1,64}$/i', $channel) !== 1) {
                continue;
            }
            $config = json_decode((string) ($row['config'] ?? '{}'), true);
            $values = [
                'enabled'               => DbBool::of($row['enabled']) ? 'true' : 'false',
                'transport'             => self::text($row['transport']),
                'from_address'          => self::text($row['from_address']),
                'from_name'             => self::text($row['from_name']),
                'reply_to'              => self::text($row['reply_to']),
                'config'                => json_encode(is_array($config) && $config !== [] ? $config : new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                'credentials_encrypted' => self::text($row['credentials_encrypted']),
            ];

            foreach ($values as $field => $value) {
                if ($value === null) {
                    continue;
                }
                $db->query(
                    'INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at)
                     VALUES (:tenant_id, :key, :value, :updated_at)
                     ON CONFLICT (tenant_id, setting_key) DO NOTHING',
                    [
                        ':tenant_id'  => (int) $row['tenant_id'],
                        ':key'        => "notifications.{$channel}.{$field}",
                        ':value'      => $value,
                        ':updated_at' => (string) $row['updated_at'],
                    ]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        $db->query("DELETE FROM tenant_settings WHERE setting_key LIKE 'notifications.%'");
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
