<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Which documents use which saved block (editor M7; E.21, E.38;
 * IMPLEMENTATION-NOTES 3.1).
 *
 * One row per document and saved block, with the version the document shows.
 * Backs `usedIn` ("used in 12 documents"), the usage list, and the 409 when a
 * synced block still in use is deleted. A document's rows are replaced as a
 * whole when its draft is saved (SavedBlockRepository::recordUses), and go
 * with the document.
 *
 * Tenant-owned (CoreTables, TenantOwnedTables): every query binds tenant_id.
 * Idempotent and reversible.
 */
class CreateSavedBlockUses
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS saved_block_uses (
                id              BIGSERIAL  NOT NULL PRIMARY KEY,
                tenant_id       INTEGER    NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                saved_block_id  BIGINT     NOT NULL REFERENCES saved_blocks(id) ON DELETE CASCADE,
                source_id       BIGINT     NOT NULL REFERENCES document_sources(id) ON DELETE CASCADE,
                version         INTEGER    NOT NULL,
                created_at      TIMESTAMP  NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, saved_block_id, source_id)
            )
        ");
        // A document's rows, replaced on each draft save.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_saved_block_uses_source ON saved_block_uses(tenant_id, source_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS saved_block_uses CASCADE');
    }
}
