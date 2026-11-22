<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateTenantStorageUsage: each workspace's stored bytes, kept as files come
 * and go (business model, section 33 §5).
 *
 * `storage.quota_bytes` was seeded in migration 152 and never read, because
 * nothing knew how much a workspace stored. Every file core keeps has a
 * `stored_files` row (migration 298), so the total is the sum of their sizes;
 * it is kept here rather than summed on every read, and
 * {@see \Whity\Core\Storage\StoredFiles} adds and takes away as it records and
 * forgets a file. `whity-cli usage:storage-rebuild` sets it from the sum
 * again, for a workspace or all of them, if the two ever drift.
 *
 * The backfill is the rebuild for every workspace that stores anything, so it
 * is safe to run twice: it sets, never adds. Table name spelled out literally
 * for TenantOwnedTablesTest and CoreTablesTest.
 */
final class CreateTenantStorageUsage
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS tenant_storage_usage (
                tenant_id   INTEGER   NOT NULL PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
                bytes       BIGINT    NOT NULL DEFAULT 0,
                files       INTEGER   NOT NULL DEFAULT 0,
                rebuilt_at  TIMESTAMP NULL,
                updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Unscoped by design (migration): every workspace's total is set.
        $db->exec('
            INSERT INTO tenant_storage_usage (tenant_id, bytes, files, rebuilt_at, updated_at)
            SELECT tenant_id, COALESCE(SUM(byte_size), 0), COUNT(*), NOW(), NOW()
              FROM stored_files
             GROUP BY tenant_id
            ON CONFLICT (tenant_id) DO UPDATE
               SET bytes = EXCLUDED.bytes, files = EXCLUDED.files,
                   rebuilt_at = EXCLUDED.rebuilt_at, updated_at = EXCLUDED.updated_at
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS tenant_storage_usage');
    }
}
