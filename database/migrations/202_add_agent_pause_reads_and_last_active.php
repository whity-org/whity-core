<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Pausing an agent, what it may read and when it was last active (WHIT-867,
 * design 2.9 S6; docs/design/24-ai-and-agents.md).
 *
 * - `agents.paused_at`, `paused_by_profile_id`: a paused agent is refused on
 *   every call and its waiting proposals can't run until it is resumed. Kept
 *   apart from `status`, because pausing and archiving are different decisions:
 *   restoring an archived agent must not resume one someone paused.
 * - `agents.may_read`: the document sources the agent uses for reference, as a
 *   list of ids. A shortlist, never a grant: it is filtered by what the person
 *   can read each time it is shown.
 * - `agent_assignments.last_active_at`: the last call an agent made for this
 *   person, reads included, which `agent_actions` never sees. Throttled, as
 *   `mcp_tokens.last_used_at` is.
 *
 * The indexes serve the per-agent counts and the admin activity filter.
 */
class AddAgentPauseReadsAndLastActive
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE agents ADD COLUMN IF NOT EXISTS paused_at TIMESTAMP');
        $db->exec('ALTER TABLE agents ADD COLUMN IF NOT EXISTS paused_by_profile_id INTEGER REFERENCES profiles(id) ON DELETE SET NULL');
        $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS may_read JSONB NOT NULL DEFAULT '[]'::jsonb");
        $db->exec('ALTER TABLE agent_assignments ADD COLUMN IF NOT EXISTS last_active_at TIMESTAMP');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_actions_principal ON agent_actions(tenant_id, agent_principal_id, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_proposals_principal ON agent_proposals(tenant_id, agent_principal_id, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_assignments_agent ON agent_assignments(tenant_id, agent_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_agent_assignments_agent');
        $db->exec('DROP INDEX IF EXISTS idx_agent_proposals_principal');
        $db->exec('DROP INDEX IF EXISTS idx_agent_actions_principal');
        $db->exec('ALTER TABLE agent_assignments DROP COLUMN IF EXISTS last_active_at');
        $db->exec('ALTER TABLE agents DROP COLUMN IF EXISTS may_read');
        $db->exec('ALTER TABLE agents DROP COLUMN IF EXISTS paused_by_profile_id');
        $db->exec('ALTER TABLE agents DROP COLUMN IF EXISTS paused_at');
    }
}
