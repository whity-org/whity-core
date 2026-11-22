<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use PDO;

/**
 * Each workspace's stored bytes, in `tenant_storage_usage` (migration 333).
 *
 * {@see \Whity\Core\Storage\StoredFiles} adds a file's size when it records
 * the file and takes it away when it forgets it, so the total is always one
 * row read. {@see rebuild()} sets it again from `stored_files`, which is the
 * truth; `whity-cli usage:storage-rebuild` runs it.
 *
 * Static, like StoredFiles, because the stores that keep files hold only a
 * PDO. Every write swallows its own failure: a file is never refused or lost
 * because its size could not be counted (BM.17).
 */
final class StorageUsage
{
    public static function added(PDO $db, int $tenantId, int $bytes): void
    {
        self::change($db, $tenantId, max(0, $bytes), 1);
    }

    public static function removed(PDO $db, int $tenantId, int $bytes): void
    {
        self::change($db, $tenantId, -max(0, $bytes), -1);
    }

    /** The workspace's stored bytes, 0 when it stores nothing. */
    public static function bytes(PDO $db, int $tenantId): int
    {
        $stmt = $db->prepare('SELECT bytes FROM tenant_storage_usage WHERE tenant_id = :tenant_id');
        $stmt->execute([':tenant_id' => $tenantId]);
        $bytes = $stmt->fetchColumn();

        return $bytes === false ? 0 : max(0, (int) $bytes);
    }

    /**
     * Set one workspace's total from its files. Returns [bytes, files].
     *
     * @return array{0: int, 1: int}
     */
    public static function rebuild(PDO $db, int $tenantId): array
    {
        $sum = $db->prepare('SELECT COALESCE(SUM(byte_size), 0), COUNT(*) FROM stored_files WHERE tenant_id = :tenant_id');
        $sum->execute([':tenant_id' => $tenantId]);
        $row = $sum->fetch(PDO::FETCH_NUM);
        $bytes = is_array($row) ? (int) $row[0] : 0;
        $files = is_array($row) ? (int) $row[1] : 0;

        $db->prepare(
            'INSERT INTO tenant_storage_usage (tenant_id, bytes, files, rebuilt_at, updated_at)
             VALUES (:tenant_id, :bytes, :files, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT (tenant_id) DO UPDATE
                SET bytes = EXCLUDED.bytes, files = EXCLUDED.files,
                    rebuilt_at = EXCLUDED.rebuilt_at, updated_at = EXCLUDED.updated_at'
        )->execute([':tenant_id' => $tenantId, ':bytes' => $bytes, ':files' => $files]);

        return [$bytes, $files];
    }

    /**
     * The workspaces that store anything or have a total, for a rebuild of all.
     *
     * @return list<int>
     */
    public static function tenantIds(PDO $db): array
    {
        $stmt = $db->query(
            // @tenant-guard-ignore: the operator's rebuild of every workspace's total lists only which workspaces to rebuild, then works on each one scoped
            'SELECT tenant_id FROM stored_files UNION SELECT tenant_id FROM tenant_storage_usage ORDER BY 1'
        );

        return $stmt === false ? [] : array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    private static function change(PDO $db, int $tenantId, int $bytes, int $files): void
    {
        if ($tenantId <= 0) {
            return;
        }
        try {
            // A first row starts from what was added (a removal with no row
            // yet starts at 0, and the rebuild puts it right); an existing row
            // moves by the change and never below 0.
            $db->prepare(
                'INSERT INTO tenant_storage_usage (tenant_id, bytes, files, updated_at)
                 VALUES (:tenant_id, :start_bytes, :start_files, CURRENT_TIMESTAMP)
                 ON CONFLICT (tenant_id) DO UPDATE
                    SET bytes = CASE WHEN tenant_storage_usage.bytes + :delta_bytes < 0 THEN 0 ELSE tenant_storage_usage.bytes + :delta_bytes2 END,
                        files = CASE WHEN tenant_storage_usage.files + :delta_files < 0 THEN 0 ELSE tenant_storage_usage.files + :delta_files2 END,
                        updated_at = EXCLUDED.updated_at'
            )->execute([
                ':tenant_id' => $tenantId,
                ':start_bytes' => max(0, $bytes),
                ':start_files' => max(0, $files),
                ':delta_bytes' => $bytes,
                ':delta_bytes2' => $bytes,
                ':delta_files' => $files,
                ':delta_files2' => $files,
            ]);
        } catch (\Throwable $e) {
            error_log(sprintf('[usage] could not count stored bytes for workspace %d: %s', $tenantId, $e->getMessage()));
        }
    }
}
