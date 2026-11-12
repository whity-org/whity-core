<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Saved blocks (editor M7; E.21-E.24, E.38; IMPLEMENTATION-NOTES 3.1).
 *
 * Several blocks saved as one, to insert again from the / menu. These are new
 * tables: the old designer's `document_blocks` was dropped by migration 165.
 *
 * `saved_blocks`
 * - `mode`: synced (editing it once updates every document that uses it) or
 *   copy (inserts ordinary blocks with no link), E.21.
 * - `sharing`: me (only the owner) or workspace (everyone in it), E.22.
 * - `blocks`: the WhityDocument contract Block[]; `per_document_keys`: the
 *   keys of the parts each document fills itself (E.23).
 * - `version`: starts at 1 and goes up by one on every change of `blocks`.
 * - `archived_at`: set when it is deleted; the row stays so documents and
 *   published templates that use it still find its versions.
 *
 * `saved_block_versions`: one INSERT-ONLY row per version with its blocks, so
 * a published template keeps printing the version it was published with
 * until a person publishes it again (E.38).
 *
 * Tenant-owned (CoreTables, TenantOwnedTables): every query binds tenant_id.
 * Idempotent and reversible.
 */
class CreateSavedBlocks
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS saved_blocks (
                id                 BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id          INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name               VARCHAR(120)  NOT NULL,
                description        TEXT,
                mode               VARCHAR(16)   NOT NULL,
                sharing            VARCHAR(16)   NOT NULL DEFAULT 'me',
                owner_profile_id   INTEGER       REFERENCES profiles(id) ON DELETE SET NULL,
                blocks             JSONB         NOT NULL,
                per_document_keys  JSONB         NOT NULL DEFAULT '[]',
                version            INTEGER       NOT NULL DEFAULT 1,
                archived_at        TIMESTAMP,
                created_at         TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMP     NOT NULL DEFAULT NOW(),
                CHECK (mode IN ('synced', 'copy')),
                CHECK (sharing IN ('me', 'workspace'))
            )
        ");
        // The / menu and the library: a workspace's live blocks, by owner and sharing.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_saved_blocks_tenant ON saved_blocks(tenant_id, archived_at, sharing, owner_profile_id)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS saved_block_versions (
                id              BIGSERIAL  NOT NULL PRIMARY KEY,
                tenant_id       INTEGER    NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                saved_block_id  BIGINT     NOT NULL REFERENCES saved_blocks(id) ON DELETE CASCADE,
                version         INTEGER    NOT NULL,
                blocks          JSONB      NOT NULL,
                created_at      TIMESTAMP  NOT NULL DEFAULT NOW(),
                UNIQUE (saved_block_id, version)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_saved_block_versions_tenant ON saved_block_versions(tenant_id, saved_block_id, version)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS saved_block_versions CASCADE');
        $db->exec('DROP TABLE IF EXISTS saved_blocks CASCADE');
    }
}
