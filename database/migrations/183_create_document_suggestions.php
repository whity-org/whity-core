<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Suggestions on an editor document (editor M5; E.4, E.10, E.29;
 * IMPLEMENTATION-NOTES 3.1 and 3.3).
 *
 * A suggestion is its own row with a patch anchored to block ids and text,
 * never a hidden edit in the draft. Accepting one applies the patch to the
 * draft on the server in one transaction and bumps `draft_version`.
 *
 * - `kind`: text · format · block (what the rail card says it changes).
 * - `summary`: "Replace 30 days with 14 days", stored for the audit log.
 * - `request`: for agents, what they were asked. `permission`: the one
 *   permission the change used. `routine_id`: set when a routine started it (E.32).
 * - Actor columns: `actor_kind` (person · agent · system), the person in
 *   `created_by_profile_id`, and for an agent its principal and the person it
 *   acts for.
 * - `base_version`: the draft version the suggestion was made against.
 * - `patch`: one of insert-blocks · replace-text · format · delete-blocks ·
 *   move-block (Whity\Core\Document\Suggestion\SuggestionPatch).
 * - `status`: open · accepted · rejected · stale.
 *
 * Tenant-owned (CoreTables, TenantOwnedTables): every query binds tenant_id.
 * Idempotent and reversible.
 */
class CreateDocumentSuggestions
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_suggestions (
                id                      BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id               INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                source_id               BIGINT        NOT NULL REFERENCES document_sources(id) ON DELETE CASCADE,
                kind                    VARCHAR(16)   NOT NULL,
                summary                 VARCHAR(500)  NOT NULL,
                request                 TEXT,
                permission              VARCHAR(64)   NOT NULL,
                routine_id              BIGINT,
                created_by_profile_id   INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                actor_kind              VARCHAR(16)   NOT NULL DEFAULT 'person',
                agent_principal_id      VARCHAR(64),
                on_behalf_of_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                base_version            INTEGER       NOT NULL,
                patch                   JSONB         NOT NULL,
                status                  VARCHAR(16)   NOT NULL DEFAULT 'open',
                decided_by_profile_id   INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                decided_at              TIMESTAMP,
                created_at              TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        // The rail and the publish check: a document's suggestions by status.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_suggestions_source ON document_suggestions(tenant_id, source_id, status, id)');
        // GET .../changes: what changed since a cursor.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_suggestions_updated ON document_suggestions(tenant_id, source_id, updated_at)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_suggestions CASCADE');
    }
}
