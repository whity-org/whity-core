<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Agent context grants (WHIT-993, SDK 1.78).
 *
 * The code plugins whose MCP prompts and resources the operator lets reach AI
 * agents. A row means granted; revoking deletes it, and the audit trail keeps
 * the history. Only {@see \Whity\Core\Plugin\Trust\AgentContextGrants} writes
 * here.
 *
 * Install-wide, with no tenant_id: a code plugin's PHP is installed for the
 * whole deployment, and only the operator (the system tenant) grants this.
 *
 * Idempotent and reversible.
 */
final class CreatePluginAgentContextGrants
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS plugin_agent_context_grants (
                id                    SERIAL       PRIMARY KEY,
                plugin_name           VARCHAR(255) NOT NULL UNIQUE,
                granted_by_profile_id INTEGER      NULL REFERENCES profiles(id) ON DELETE SET NULL,
                granted_at            TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_agent_context_grants');
    }
}
