<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * The undo log: what an agent did that can still be undone (WHIT-812; register
 * 7.5c Undo; MCP-TOOL-SURFACE §5.11).
 *
 * When an agent runs an operation that declares an undo, by an Allowed call or
 * an approved proposal, a row records what the inverse needs. The person it
 * acted for can undo it until `undoable_until`; undo runs the inverse as them,
 * after every check runs again.
 *
 * - `agent_principal_id`, `on_behalf_of_profile_id`: the agent and the person
 *   it acted for, the only one who may undo it.
 * - `proposal_id`: the approved proposal it ran from; null for an Allowed call.
 * - `tool`, `arguments`, `summary`: what ran.
 * - `inverse`: the operation that reverses it and its resolved arguments, plus
 *   the read and the values it left, to tell whether anything changed since.
 * - `undo_window_seconds`, `undoable_until`: the operation's undo window, from
 *   when it ran.
 * - `status`: done · undone.
 * - `undone_by_profile_id`, `undone_at`, `undo_result`: who undid it, when, and
 *   what the inverse answered.
 */
class CreateAgentActions
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS agent_actions (
                id                      BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id               INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                agent_principal_id      VARCHAR(64)  NOT NULL,
                on_behalf_of_profile_id INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                proposal_id             BIGINT       REFERENCES agent_proposals(id) ON DELETE SET NULL,
                tool                    VARCHAR(128) NOT NULL,
                arguments               JSONB        NOT NULL DEFAULT '{}'::jsonb,
                summary                 TEXT         NOT NULL DEFAULT '',
                inverse                 JSONB        NOT NULL,
                undo_window_seconds     INTEGER      NOT NULL,
                undoable_until          TIMESTAMP    NOT NULL,
                status                  VARCHAR(16)  NOT NULL DEFAULT 'done',
                undone_by_profile_id    INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                undone_at               TIMESTAMP,
                undo_result             JSONB,
                created_at              TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");
        // A person's activity, newest first; and the action a proposal ran.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_actions_person ON agent_actions(tenant_id, on_behalf_of_profile_id, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_actions_proposal ON agent_actions(proposal_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_agent_actions_proposal');
        $db->exec('DROP INDEX IF EXISTS idx_agent_actions_person');
        $db->exec('DROP TABLE IF EXISTS agent_actions');
    }
}
