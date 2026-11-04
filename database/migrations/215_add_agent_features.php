<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Plugin agents' feature flags (SDK 1.64, WHIT-893).
 *
 * `agents.features` holds the host feature flags an agent was created with,
 * copied from the plugin's declaration when a workspace starts an agent from
 * it. Only the flags the host exposes are kept (see AgentFeatures). The column
 * is a copy, like the persona: a later declaration never changes an existing
 * agent.
 */
class AddAgentFeatures
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS features JSONB NOT NULL DEFAULT '{}'::jsonb");
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE agents DROP COLUMN IF EXISTS features');
    }
}
