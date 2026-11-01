<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Agent assignments (WHIT-816; docs/design/AGENT-PERSONAS-AND-WHITY-AGENT.md
 * §4.3): one person's use of one workspace agent.
 *
 * An assignment is the agent's identity and its grant for that person:
 *
 * - Its id gives the stable principal `agent:<id>`. Proposals, actor columns
 *   and the activity list key on it, so an agent stays one principal however
 *   many credentials it has and however often they rotate.
 * - `grant` is `{permission: off|ask|allow}`, read on EVERY call, so a change
 *   applies at once to every credential bound to the assignment. It never
 *   goes above the person: RBAC is checked per call, and only the person
 *   sets a write to Allowed (owner decision Q6).
 * - `revoked_at` set means the person stopped using the agent: its
 *   credentials are refused and its waiting proposals can't be approved.
 *
 * `mcp_tokens.agent_assignment_id` binds a Whity `ai-agent` credential to an
 * assignment; deleting an assignment deletes its credentials with it, so a
 * bound token can never fall back to acting on its own stored scope.
 */
class CreateAgentAssignments
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS agent_assignments (
                id                     BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id              INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                agent_id               BIGINT      NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
                profile_id             INTEGER     NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                grant_modes            JSONB       NOT NULL DEFAULT '{}'::jsonb,
                revoked_at             TIMESTAMP,
                updated_by_profile_id  INTEGER     REFERENCES profiles(id) ON DELETE SET NULL,
                created_at             TIMESTAMP   NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMP   NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_agent_assignments_agent_profile UNIQUE (agent_id, profile_id)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_assignments_person ON agent_assignments(tenant_id, profile_id)');
        $db->exec('ALTER TABLE mcp_tokens ADD COLUMN IF NOT EXISTS agent_assignment_id BIGINT REFERENCES agent_assignments(id) ON DELETE CASCADE');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE mcp_tokens DROP COLUMN IF EXISTS agent_assignment_id');
        $db->exec('DROP INDEX IF EXISTS idx_agent_assignments_person');
        $db->exec('DROP TABLE IF EXISTS agent_assignments');
    }
}
