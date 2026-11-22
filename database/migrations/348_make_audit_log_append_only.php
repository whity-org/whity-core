<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * MakeAuditLogAppendOnly (WHIT-1339, readiness gap CO-5).
 *
 * `audit_log` was append-only by convention only: no code path updates or
 * deletes a row, but nothing in the database refused it, and nothing would
 * show that a row had been changed. This migration adds both halves:
 *
 * 1. **Refusal.** Triggers on both engines refuse UPDATE and DELETE on
 *    `audit_log` (and TRUNCATE on PostgreSQL). Migration 347 runs first, so
 *    deleting a workspace no longer cascades into the table.
 *
 * 2. **Evidence.** `audit_log_seals` holds one seal per audit row: the hash of
 *    the previous seal and of the row's own columns, so changing, removing or
 *    reordering any sealed row breaks every seal after it. The seals are
 *    written by the `audit.seal` job ({@see \Whity\Core\Audit\AuditSeals}), not
 *    at insert: hashing at insert would serialise every audit write of the
 *    install behind one lock. `audit_log_chain` keeps where sealing got to (one
 *    row). `whity-cli audit:verify` recomputes the whole chain.
 *
 * The seals table is append-only too. Someone with the database owner's rights
 * can still drop a trigger and rewrite rows and seals together; the chain head
 * the job logs each run is what detects that, once it is kept off the host
 * (docs/security/READINESS.md, CO-5).
 *
 * No backfill here: existing rows are sealed by the job, a batch at a time, so
 * the migration reads and writes no audit row and is safe on a large live
 * table. Idempotent on both engines.
 *
 * What still deletes audit rows, and how (asked before this landed):
 * - Workspace deletion: nothing since 347.
 * - Retention: there is no purge job. One, when the owner sets a period, has to
 *   be a migration that replaces these triggers with a sanctioned archive path.
 * - Tests: the two that cleared the table read past a high-water mark instead.
 * - Restores (`pg_restore`, the restore drill): load with COPY, which these
 *   triggers don't block; `--clean` drops tables rather than deleting rows.
 */
class MakeAuditLogAppendOnly
{
    /** The previous-hash of the first seal: 64 zeros. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const MESSAGE = 'audit records are append-only';

    public static function up(Database $db): void
    {
        $driver = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS audit_log_seals (
                    audit_id BIGINT PRIMARY KEY,
                    version SMALLINT NOT NULL,
                    prev_hash CHAR(64) NOT NULL,
                    row_hash CHAR(64) NOT NULL,
                    sealed_at TIMESTAMP NOT NULL DEFAULT NOW()
                )'
            );
            $db->exec(
                'CREATE TABLE IF NOT EXISTS audit_log_chain (
                    id SMALLINT PRIMARY KEY CHECK (id = 1),
                    last_audit_id BIGINT NOT NULL DEFAULT 0,
                    head_hash CHAR(64) NOT NULL,
                    sealed_count BIGINT NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
                )'
            );
            $db->exec(
                "INSERT INTO audit_log_chain (id, last_audit_id, head_hash, sealed_count)
                 VALUES (1, 0, '" . self::GENESIS . "', 0)
                 ON CONFLICT (id) DO NOTHING"
            );

            $db->exec(
                "CREATE OR REPLACE FUNCTION whity_audit_append_only() RETURNS trigger
                 LANGUAGE plpgsql AS \$fn\$
                 BEGIN
                     RAISE EXCEPTION '" . self::MESSAGE . " (% on %)', TG_OP, TG_TABLE_NAME
                         USING ERRCODE = 'insufficient_privilege';
                 END
                 \$fn\$"
            );
            // Dropped and created, as migration 318 does for inv_moves, so a re-run
            // after a half-applied first run ends in the same place.
            foreach (['audit_log', 'audit_log_seals'] as $table) {
                $db->exec("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
                $db->exec(
                    "CREATE TRIGGER {$table}_append_only
                     BEFORE UPDATE OR DELETE ON {$table}
                     FOR EACH ROW EXECUTE FUNCTION whity_audit_append_only()"
                );
                $db->exec("DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table}");
                $db->exec(
                    "CREATE TRIGGER {$table}_no_truncate
                     BEFORE TRUNCATE ON {$table}
                     FOR EACH STATEMENT EXECUTE FUNCTION whity_audit_append_only()"
                );
            }

            return;
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS audit_log_seals (
                audit_id INTEGER PRIMARY KEY,
                version INTEGER NOT NULL,
                prev_hash TEXT NOT NULL,
                row_hash TEXT NOT NULL,
                sealed_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS audit_log_chain (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                last_audit_id INTEGER NOT NULL DEFAULT 0,
                head_hash TEXT NOT NULL,
                sealed_count INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        $db->exec(
            "INSERT OR IGNORE INTO audit_log_chain (id, last_audit_id, head_hash, sealed_count)
             VALUES (1, 0, '" . self::GENESIS . "', 0)"
        );
        foreach (['audit_log', 'audit_log_seals'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = $table . '_no_' . strtolower($op);
                $db->exec(
                    "CREATE TRIGGER IF NOT EXISTS {$name} BEFORE {$op} ON {$table}
                     BEGIN SELECT RAISE(ABORT, '" . self::MESSAGE . "'); END"
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        $driver = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            foreach (['audit_log', 'audit_log_seals'] as $table) {
                $db->exec("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
                $db->exec("DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table}");
            }
            $db->exec('DROP FUNCTION IF EXISTS whity_audit_append_only()');
        } else {
            foreach (['audit_log', 'audit_log_seals'] as $table) {
                $db->exec("DROP TRIGGER IF EXISTS {$table}_no_update");
                $db->exec("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }
        $db->exec('DROP TABLE IF EXISTS audit_log_chain');
        $db->exec('DROP TABLE IF EXISTS audit_log_seals');
    }
}
