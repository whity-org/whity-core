<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Agent notifications by email (A12, WHIT-969, card `TplAgentNotify`).
 *
 * `agents.notify_by_email`: whether the person an agent acts for hears by email
 * when something it proposed waits for them. On by default, turned off on the
 * agent's page in admin/agents. The person can still turn the kind off for
 * themselves in Preferences › Notifications.
 */
class AddAgentNotifyByEmail
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE agents ADD COLUMN IF NOT EXISTS notify_by_email BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE agents DROP COLUMN IF EXISTS notify_by_email');
    }
}
