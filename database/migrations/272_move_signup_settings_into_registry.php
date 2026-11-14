<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\Db\DbBool;
use Whity\Database\Database;

/**
 * Customer sign-up settings move into the settings registry (WHIT-1035).
 *
 * Each `workspace_signup_settings` row becomes four `tenant_settings` keys:
 * `signup.mode`, `signup.role_id` (0 for none), `signup.allowed_domains` (a
 * JSON list) and `signup.customer_invites` ('true' or 'false'). Values a
 * workspace already holds under those keys win (ON CONFLICT DO NOTHING), so a
 * second run changes nothing.
 *
 * The old table is left in place, unread, for one release so a rollback to the
 * previous build still finds its settings; a later migration drops it.
 *
 * down() removes the four keys; the old table still holds what it held.
 */
final class MoveSignupSettingsIntoRegistry
{
    public static function up(Database $db): void
    {
        $rows = $db->query(
            'SELECT tenant_id, mode, role_id, allowed_domains, customer_invites, updated_at
               FROM workspace_signup_settings'
        )->fetchAll();

        foreach ($rows === false ? [] : $rows as $row) {
            $mode = in_array($row['mode'], ['off', 'approval', 'open'], true) ? (string) $row['mode'] : 'off';
            $domains = json_decode((string) $row['allowed_domains'], true);
            $domains = is_array($domains) ? array_values(array_filter($domains, 'is_string')) : [];

            $values = [
                'signup.mode' => $mode,
                'signup.role_id' => (string) ($row['role_id'] === null ? 0 : (int) $row['role_id']),
                'signup.allowed_domains' => (string) json_encode($domains),
                'signup.customer_invites' => DbBool::of($row['customer_invites']) ? 'true' : 'false',
            ];
            foreach ($values as $key => $value) {
                $db->query(
                    'INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at)
                     VALUES (:tenant_id, :key, :value, :updated_at)
                     ON CONFLICT (tenant_id, setting_key) DO NOTHING',
                    [
                        ':tenant_id' => (int) $row['tenant_id'],
                        ':key' => $key,
                        ':value' => $value,
                        ':updated_at' => (string) $row['updated_at'],
                    ]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        $db->query(
            "DELETE FROM tenant_settings
              WHERE setting_key IN ('signup.mode', 'signup.role_id', 'signup.allowed_domains', 'signup.customer_invites')"
        );
    }
}
