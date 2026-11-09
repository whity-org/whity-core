<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use RuntimeException;
use Whity\Database\Database;

/**
 * AddRouteSendBackAndVersions (WHIT-919, #1723) — send back for edits,
 * resubmit, and the versions of what was reviewed.
 *
 * WIDENING THE TRAIL'S ACTION CHECK, ONCE
 * ---------------------------------------
 * Migrations 119, 125 and 201 each declined to widen
 * `document_route_events.action` and added a column instead, because on SQLite
 * the only documented way to change an inline CHECK was the twelve-step
 * rebuild: copy every row of the append-only system of record into a new table.
 * Those three facts really were modifiers of an existing verb (a verdict on an
 * acknowledgement, a delivery close, a withdrawal note). The routes plan adds
 * acts that are verbs of their own: `sent_back` and `resubmitted` here, and
 * `signed`, `stamped` and `declined` for the signing steps. Spelling them as
 * flags on `returned` or `noted` would make the trail say a person did
 * something they did not, so the vocabulary is widened, and widened once for
 * the whole plan.
 *
 * Neither engine rewrites a row to do it:
 *  - PostgreSQL drops the old CHECK and adds the wider one NOT VALID, then
 *    validates it. Validation reads the table; it changes nothing.
 *  - SQLite edits the stored table definition through `writable_schema`. That
 *    is SQLite's documented procedure for loosening a CHECK, and widening an
 *    IN list only loosens it: every stored row already satisfies the wider
 *    rule, so no row is touched and none needs to be. The edit replaces the
 *    exact text migration 112 wrote, and refuses to run if that text is not
 *    there, so a hand-patched schema fails loudly instead of being half-edited.
 *
 * THE REST
 * --------
 *  - Trail entries record the subject version and its SHA-256
 *    (`subject_version`, `content_hash`), so the trail shows which version each
 *    approval was given on. NULL on every earlier row: those acts were not
 *    recorded against a version, and inventing one would be rewriting history.
 *  - `to_author` marks a note (and every send-back) as meant for the author.
 *    Internal notes stay FALSE and never reach them.
 *  - `resume_from` on a `sent_back` records the sender's choice: `sender` (back
 *    to the sending step, resolved again) or `start` (step 1).
 *  - `allow_send_back` on `document_route_steps` and on a design's
 *    `document_route_template_steps`, TRUE by default, so a route design can
 *    turn send-back off for a step.
 *  - `document_route_recipients.role`: `step` for every item a step opened,
 *    `author` for the one item a send-back opens for the subject's author.
 *  - `route_subject_versions`: one immutable row per submit or resubmit of a
 *    subject. Insert-only, like the trail.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down(). down() narrows the
 * CHECK again only when no row uses a new verb, and refuses otherwise rather
 * than deleting trail rows.
 */
final class AddRouteSendBackAndVersions
{
    private const NARROW = "CHECK (action IN ('issued', 'forwarded', 'acknowledged', 'returned', 'noted'))";

    private const WIDE = "CHECK (action IN ('issued', 'forwarded', 'acknowledged', 'returned', 'noted', "
        . "'sent_back', 'resubmitted', 'signed', 'stamped', 'declined'))";

    /** The verbs this migration adds; down() refuses while any row uses one. */
    private const NEW_VERBS = ['sent_back', 'resubmitted', 'signed', 'stamped', 'declined'];

    public static function up(Database $db): void
    {
        self::replaceActionCheck($db, self::NARROW, self::WIDE);

        $db->exec('ALTER TABLE document_route_events ADD COLUMN IF NOT EXISTS subject_version INTEGER');
        $db->exec('ALTER TABLE document_route_events ADD COLUMN IF NOT EXISTS content_hash VARCHAR(64)');
        $db->exec(
            'ALTER TABLE document_route_events ADD COLUMN IF NOT EXISTS to_author BOOLEAN NOT NULL DEFAULT FALSE'
        );
        $db->exec(
            "ALTER TABLE document_route_events ADD COLUMN IF NOT EXISTS resume_from VARCHAR(16)
                 CHECK (resume_from IS NULL OR resume_from IN ('sender', 'start'))"
        );

        $db->exec(
            'ALTER TABLE document_route_steps ADD COLUMN IF NOT EXISTS allow_send_back BOOLEAN NOT NULL DEFAULT TRUE'
        );
        // The same switch on a route DESIGN's stages, carried onto every route
        // issued from it, as `satisfied_by` is.
        $db->exec(
            'ALTER TABLE document_route_template_steps
                 ADD COLUMN IF NOT EXISTS allow_send_back BOOLEAN NOT NULL DEFAULT TRUE'
        );

        $db->exec(
            "ALTER TABLE document_route_recipients ADD COLUMN IF NOT EXISTS role VARCHAR(16) NOT NULL DEFAULT 'step'
                 CHECK (role IN ('step', 'author'))"
        );

        $db->exec("
            CREATE TABLE IF NOT EXISTS route_subject_versions (
                id           BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id    INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                subject_type VARCHAR(64)  NOT NULL,
                subject_id   BIGINT       NOT NULL,
                version      INTEGER      NOT NULL,
                content_hash VARCHAR(64)  NOT NULL,
                data         TEXT         NOT NULL,
                created_by   INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at   TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, subject_type, subject_id, version)
            )
        ");
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();
        $placeholders = implode(', ', array_fill(0, count(self::NEW_VERBS), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_route_events WHERE action IN ({$placeholders})");
        $stmt->execute(self::NEW_VERBS);
        $used = (int) $stmt->fetchColumn();
        // Closed before any DDL: an open read cursor locks the table on SQLite.
        $stmt->closeCursor();
        if ($used > 0) {
            throw new RuntimeException(
                'Cannot roll back migration 226: the routing trail already holds acts it added '
                . '(sent back, resubmitted, signed, stamped or declined), and the trail is never rewritten.'
            );
        }

        $db->exec('DROP TABLE IF EXISTS route_subject_versions');
        $db->exec('ALTER TABLE document_route_recipients DROP COLUMN IF EXISTS role');
        $db->exec('ALTER TABLE document_route_template_steps DROP COLUMN IF EXISTS allow_send_back');
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS allow_send_back');
        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS resume_from');
        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS to_author');
        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS content_hash');
        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS subject_version');

        self::replaceActionCheck($db, self::WIDE, self::NARROW);
    }

    /**
     * Swap the trail's action CHECK from one verb list to another without
     * rewriting a row. A no-op when the target is already in place, so up()
     * can run twice.
     */
    private static function replaceActionCheck(Database $db, string $from, string $to): void
    {
        $pdo = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $stmt = $pdo->query(
                "SELECT conname, pg_get_constraintdef(oid) AS def
                   FROM pg_constraint
                  WHERE conrelid = 'document_route_events'::regclass AND contype = 'c'"
            );
            $checks = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
            foreach ($checks as $check) {
                $def = (string) $check['def'];
                // The action CHECK is the one naming the column `action`; the
                // verdict and resume_from CHECKs name their own columns.
                if (preg_match('/\(action\)/', $def) === 1 || preg_match('/\baction\b\s*(=|IN)/i', $def) === 1) {
                    $pdo->exec('ALTER TABLE document_route_events DROP CONSTRAINT "' . str_replace('"', '""', (string) $check['conname']) . '"');
                }
            }
            $pdo->exec("ALTER TABLE document_route_events ADD CONSTRAINT document_route_events_action_check {$to} NOT VALID");
            $pdo->exec('ALTER TABLE document_route_events VALIDATE CONSTRAINT document_route_events_action_check');

            return;
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException("Migration 226 does not know how to widen a CHECK on '{$driver}'.");
        }

        $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'document_route_events'");
        $sql = $stmt === false ? false : $stmt->fetchColumn();
        if ($stmt !== false) {
            $stmt->closeCursor();
        }
        if (!is_string($sql)) {
            throw new RuntimeException('Migration 226: document_route_events does not exist.');
        }
        if (str_contains($sql, $to)) {
            return;
        }
        if (substr_count($sql, $from) !== 1) {
            throw new RuntimeException(
                'Migration 226: the action CHECK on document_route_events is not the one migration 112 wrote, '
                . 'so it is not edited automatically.'
            );
        }

        $version = $pdo->query('PRAGMA schema_version');
        $schemaVersion = $version === false ? 0 : (int) $version->fetchColumn();
        if ($version !== false) {
            $version->closeCursor();
        }

        $pdo->exec('PRAGMA writable_schema = ON');
        try {
            $update = $pdo->prepare(
                "UPDATE sqlite_master SET sql = :sql WHERE type = 'table' AND name = 'document_route_events'"
            );
            $update->execute([':sql' => str_replace($from, $to, $sql)]);
            // Tells every connection, this one included, that the stored schema
            // changed, so the new CHECK is what the next statement is compiled
            // against.
            $pdo->exec('PRAGMA schema_version = ' . ($schemaVersion + 1));
        } finally {
            $pdo->exec('PRAGMA writable_schema = OFF');
        }

        $check = $pdo->query('PRAGMA integrity_check');
        $result = $check === false ? null : $check->fetchColumn();
        if ($check !== false) {
            $check->closeCursor();
        }
        if ($result !== 'ok') {
            throw new RuntimeException('Migration 226: SQLite integrity check failed after widening the action CHECK.');
        }
    }
}
