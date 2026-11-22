<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * KeepAuditLogWhenWorkspaceDeleted (WHIT-1339, readiness gap CO-6).
 *
 * Migration 016 gave `audit_log.tenant_id` a foreign key with ON DELETE
 * CASCADE, "consistent with the other tenant-scoped tables". For the audit
 * trail that is the wrong rule: deleting a workspace deleted its whole history,
 * including the row saying who deleted it, and a workspace's own user can delete
 * it once it has no active members. Evidence must outlive its subject; the same
 * reasoning 016 already applied to `actor_user_id`.
 *
 * After this migration `tenant_id` is a plain column. Workspace ids come from a
 * sequence and are never reused, so a deleted workspace's rows stay its own and
 * no live workspace can read them (every read binds the caller's tenant).
 * Retention is a separate decision (docs/security/READINESS.md, CO-6): nothing
 * deletes audit rows today, and migration 348 makes the table refuse it.
 *
 * - PostgreSQL: drop the constraint, found by what it is rather than by name,
 *   so an install whose constraint was named differently is handled too.
 * - SQLite (the test engine): a foreign key can't be dropped in place, so the
 *   table is rebuilt from its own DDL with the REFERENCES clause removed. No
 *   other table references audit_log, so the rename is safe.
 *
 * Idempotent: with no such foreign key left, both paths do nothing. No row is
 * read or changed, so it is safe on a large live table: on PostgreSQL dropping a
 * foreign key is a catalogue change under a brief lock.
 *
 * down() puts the foreign key back on PostgreSQL only where every row still has
 * its workspace (NOT VALID, so existing orphans don't block it); it does not
 * bring the cascade back on SQLite.
 */
class KeepAuditLogWhenWorkspaceDeleted
{
    private const SCRATCH = 'audit_log_pre_347';

    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            foreach (self::pgTenantForeignKeys($pdo) as $name) {
                $db->exec('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS "' . str_replace('"', '""', $name) . '"');
            }

            return;
        }

        self::sqliteRebuildWithoutForeignKey($db, $pdo);
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return;
        }
        if (self::pgTenantForeignKeys($pdo) !== []) {
            return;
        }
        $db->exec(
            'ALTER TABLE audit_log ADD CONSTRAINT audit_log_tenant_id_fkey
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE NOT VALID'
        );
    }

    /**
     * Foreign keys from audit_log.tenant_id to tenants, by name.
     *
     * @return list<string>
     */
    private static function pgTenantForeignKeys(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT c.conname
               FROM pg_constraint c
               JOIN pg_class t ON t.oid = c.conrelid
               JOIN pg_class r ON r.oid = c.confrelid
               JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
              WHERE c.contype = 'f'
                AND t.relname = 'audit_log'
                AND r.relname = 'tenants'
                AND a.attname = 'tenant_id'
                AND t.relnamespace = to_regnamespace(current_schema())"
        );

        return $stmt === false ? [] : array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function sqliteRebuildWithoutForeignKey(Database $db, PDO $pdo): void
    {
        $ddl = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'audit_log'")?->fetchColumn();
        if (!is_string($ddl)) {
            return;
        }
        $stripped = preg_replace('/\s+REFERENCES\s+tenants\s*\(\s*id\s*\)(\s+ON\s+DELETE\s+CASCADE)?/i', '', $ddl);
        if (!is_string($stripped) || $stripped === $ddl) {
            return; // already without the foreign key
        }

        $indexes = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'audit_log' AND sql IS NOT NULL"
        )?->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // A table rebuild with foreign keys enforced would trip over nothing here
        // (no table references audit_log), but the pragma is per connection and
        // must be restored as found.
        $fk = (int) $pdo->query('PRAGMA foreign_keys')?->fetchColumn();
        if ($fk === 1) {
            $db->exec('PRAGMA foreign_keys = OFF');
        }

        try {
            $ownTransaction = !$pdo->inTransaction();
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }
            try {
                $db->exec('DROP TABLE IF EXISTS ' . self::SCRATCH);
                $db->exec('ALTER TABLE audit_log RENAME TO ' . self::SCRATCH);
                $db->exec($stripped);
                $db->exec('INSERT INTO audit_log SELECT * FROM ' . self::SCRATCH);
                $db->exec('DROP TABLE IF EXISTS ' . self::SCRATCH);
                foreach ($indexes as $sql) {
                    $db->exec((string) $sql);
                }
                if ($ownTransaction) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            if ($fk === 1) {
                $db->exec('PRAGMA foreign_keys = ON');
            }
        }
    }
}
