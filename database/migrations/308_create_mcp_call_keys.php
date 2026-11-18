<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateMcpCallKeys (WHIT-931, #1798): retry protection for MCP tools/call.
 *
 * `mcp_call_keys` holds one row per idempotency key a client sent with a
 * tools/call, scoped to the workspace, the principal (the agent and the person
 * it acts for, or the person) and the tool. The row is written BEFORE the call
 * runs, so a retry that arrives while the first call is still running finds it
 * and is told so instead of running the write again.
 *
 * - `arguments_hash`: SHA-256 of the arguments in a canonical form; the same key
 *   with other arguments is refused.
 * - `status`: running · done.
 * - `result`: the tools/call result, replayed to a retry once done.
 * - Rows older than 24 hours are deleted by `schedule:run`.
 *
 * `agent_proposals.arguments_hash` lets an Ask-first call sent again without a
 * key find the identical proposal still waiting, and the partial unique index
 * makes that hold when two arrive at once. Older proposals have none, and
 * proposals made other ways (approvals' tests, plugins) leave it empty.
 *
 * Table names spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Safe to run twice.
 */
final class CreateMcpCallKeys
{
    public static function up(Database $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS mcp_call_keys (
                id              BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id       INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                principal       VARCHAR(191) NOT NULL,
                tool            VARCHAR(128) NOT NULL,
                idempotency_key VARCHAR(255) NOT NULL,
                arguments_hash  VARCHAR(64)  NOT NULL,
                status          VARCHAR(16)  NOT NULL DEFAULT \'running\',
                result          TEXT         NULL,
                created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                finished_at     TIMESTAMP    NULL,
                UNIQUE (tenant_id, principal, tool, idempotency_key)
            )'
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_mcp_call_keys_created ON mcp_call_keys(created_at)');

        $db->exec('ALTER TABLE agent_proposals ADD COLUMN IF NOT EXISTS arguments_hash VARCHAR(64)');
        $db->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS uq_agent_proposals_waiting_call
                 ON agent_proposals(tenant_id, agent_principal_id, on_behalf_of_profile_id, tool, arguments_hash)
              WHERE status = 'waiting' AND arguments_hash IS NOT NULL"
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS uq_agent_proposals_waiting_call');
        $db->exec('ALTER TABLE agent_proposals DROP COLUMN IF EXISTS arguments_hash');
        $db->exec('DROP INDEX IF EXISTS idx_mcp_call_keys_created');
        $db->exec('DROP TABLE IF EXISTS mcp_call_keys');
    }
}
