<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Ask an agent, from inside the editor (editor M6; E.30, E.31; owner's
 * answer Q3 in IMPLEMENTATION-NOTES).
 *
 * Whity hosts no model. A person's request waits here until the person's own
 * agent, connected over MCP, picks it up with `document_agent_requests_next`,
 * works with the document tools (which only ever write suggestions and
 * comments), and reports how it is going with `document_agent_request_update`.
 * The editor polls the request and shows it as an AgentRunStatus, with Stop.
 *
 * - `agent_principal_id`: the agent asked (`agent:<assignment id>`), or null
 *   for "any agent I have connected".
 * - `claimed_by_principal_id`: the agent that picked it up.
 * - `status`: queued · running · done · failed · stopped.
 * - `title`: what it is doing now, in plain words ("Reading the terms").
 * - `progress_done` / `progress_total`: when the agent knows the count.
 * - `detail`: what it did (done) or what went wrong (failed).
 * - `stop_requested_at`: the person pressed Stop; the next update says so.
 *
 * Tenant-owned (CoreTables, TenantOwnedTables): every query binds tenant_id.
 * Idempotent and reversible.
 */
final class CreateDocumentAgentRequests
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_agent_requests (
                id                      BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id               INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                source_id               BIGINT        NOT NULL REFERENCES document_sources(id) ON DELETE CASCADE,
                requested_by_profile_id INTEGER       NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                agent_principal_id      VARCHAR(64),
                claimed_by_principal_id VARCHAR(64),
                request                 TEXT          NOT NULL,
                anchor_block_id         VARCHAR(64),
                quote                   TEXT,
                status                  VARCHAR(16)   NOT NULL DEFAULT 'queued',
                title                   VARCHAR(300),
                progress_done           INTEGER,
                progress_total          INTEGER,
                detail                  TEXT,
                stop_requested_at       TIMESTAMP,
                claimed_at              TIMESTAMP,
                finished_at             TIMESTAMP,
                created_at              TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        // The agent's queue: a person's waiting requests, oldest first.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_agent_requests_queue ON document_agent_requests(tenant_id, requested_by_profile_id, status, id)');
        // The editor: a document's requests, newest first.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_agent_requests_source ON document_agent_requests(tenant_id, source_id, id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_agent_requests CASCADE');
    }
}
