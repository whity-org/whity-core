<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use RuntimeException;
use Whity\Database\Database;

/**
 * AddFormSubmissionRouteSubjects (WHIT-920, #1724) — a route travels on a form
 * submission, and a submission no longer raises a document when it is sent.
 *
 * A ROUTE WITHOUT A DOCUMENT
 * --------------------------
 * `document_routes`, `document_route_events` and `document_route_recipients`
 * each carried `document_id NOT NULL` since migration 112. A route on a
 * submission has no document until its finalise step makes one (#1725), so the
 * three columns become nullable. Nothing else about them changes: every row
 * written so far keeps its document, every route on a document still names it,
 * and the foreign keys (ON DELETE CASCADE) still hold for every row that has
 * one. A route with no document is read through its subject
 * (`subject_type`, `subject_id`, migration 224).
 *
 *  - PostgreSQL drops the NOT NULL. That reads nothing and rewrites nothing.
 *  - SQLite edits the stored table definition through `writable_schema`, as
 *    migration 226 does for the trail's CHECK. Removing a NOT NULL is one of the
 *    changes SQLite documents as safe that way: it only loosens, so every stored
 *    row still satisfies it. The edit refuses to run when the column is not
 *    spelled the way migration 112 wrote it.
 *
 * EXISTING SUBMISSION ROUTES ARE RE-POINTED
 * -----------------------------------------
 * Every route on a document that a form submission produced now names the
 * submission as its subject (`form_submission`). The documents stay, and the
 * routes keep pointing at them, so a document's own routing tab, its trail and
 * its withdrawal behave as before. Only what the route is ABOUT changes, which
 * is what lets the author edit and resubmit a sent-back submission.
 *
 * SIGN-IN ON A PUBLIC FORM
 * ------------------------
 * `forms.public_requires_sign_in`: when TRUE, the form's public link asks the
 * visitor to sign in, and only an active member or customer of the workspace
 * can send it, under their own name. An anonymous submission has nobody to send
 * it back to, so this is how a public form gets send-back.
 *
 * Idempotent and reversible. down() refuses while any route, trail entry or
 * item has no document, rather than deleting what the trail recorded.
 */
final class AddFormSubmissionRouteSubjects
{
    /** The three tables whose `document_id` becomes nullable. */
    private const TABLES = ['document_routes', 'document_route_events', 'document_route_recipients'];

    public static function up(Database $db): void
    {
        foreach (self::TABLES as $table) {
            self::setDocumentRequired($db, $table, false);
        }

        // Re-point the routes on documents a submission produced. Unscoped by
        // design (migration): every tenant's routes, each matched to a
        // submission of its own tenant.
        $db->exec(
            "UPDATE document_routes
                SET subject_type = 'form_submission',
                    subject_id = (
                        SELECT MIN(s.id) FROM form_submissions s
                         WHERE s.tenant_id = document_routes.tenant_id
                           AND s.document_id = document_routes.document_id
                    )
              WHERE COALESCE(subject_type, 'document') = 'document'
                AND document_id IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM form_submissions s
                     WHERE s.tenant_id = document_routes.tenant_id
                       AND s.document_id = document_routes.document_id
                )"
        );

        $db->exec(
            'ALTER TABLE forms ADD COLUMN IF NOT EXISTS public_requires_sign_in BOOLEAN NOT NULL DEFAULT FALSE'
        );
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();
        foreach (self::TABLES as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE document_id IS NULL");
            $missing = $stmt === false ? 0 : (int) $stmt->fetchColumn();
            // Closed before any DDL: an open read cursor locks the table on SQLite.
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
            if ($missing > 0) {
                throw new RuntimeException(
                    'Cannot roll back migration 233: some routes travel on a form submission that has no '
                    . 'document yet, and the routing trail is never rewritten.'
                );
            }
        }

        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS public_requires_sign_in');

        $db->exec(
            "UPDATE document_routes
                SET subject_type = 'document', subject_id = document_id
              WHERE subject_type = 'form_submission' AND document_id IS NOT NULL"
        );

        foreach (self::TABLES as $table) {
            self::setDocumentRequired($db, $table, true);
        }
    }

    /**
     * Make `document_id` nullable (or required again) on one table. A no-op when
     * it is already that way, so up() and down() can each run twice.
     */
    private static function setDocumentRequired(Database $db, string $table, bool $required): void
    {
        $pdo = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE {$table} ALTER COLUMN document_id " . ($required ? 'SET' : 'DROP') . ' NOT NULL');

            return;
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException("Migration 233 does not know how to change a column on '{$driver}'.");
        }

        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name");
        $stmt->execute([':name' => $table]);
        $sql = $stmt->fetchColumn();
        $stmt->closeCursor();
        if (!is_string($sql)) {
            throw new RuntimeException("Migration 233: {$table} does not exist.");
        }

        $requiredPattern = '/\bdocument_id(\s+)BIGINT(\s+)NOT\s+NULL(\s+)REFERENCES\s+documents\(id\)/i';
        $optionalPattern = '/\bdocument_id(\s+)BIGINT(\s+)REFERENCES\s+documents\(id\)/i';
        $isRequired = preg_match($requiredPattern, $sql) === 1;
        $isOptional = preg_match($optionalPattern, $sql) === 1;

        $alreadyThere = $required ? $isRequired : $isOptional;
        if ($alreadyThere) {
            return;
        }
        $canEdit = $required ? $isOptional : $isRequired;
        if (!$canEdit) {
            throw new RuntimeException(
                "Migration 233: document_id on {$table} is not the column migration 112 wrote, "
                . 'so it is not edited automatically.'
            );
        }

        $edited = $required
            ? preg_replace($optionalPattern, 'document_id$1BIGINT$2NOT NULL REFERENCES documents(id)', $sql, 1)
            : preg_replace($requiredPattern, 'document_id$1BIGINT$2REFERENCES documents(id)', $sql, 1);
        if (!is_string($edited)) {
            throw new RuntimeException("Migration 233: could not edit the definition of {$table}.");
        }

        $version = $pdo->query('PRAGMA schema_version');
        $schemaVersion = $version === false ? 0 : (int) $version->fetchColumn();
        if ($version !== false) {
            $version->closeCursor();
        }

        $pdo->exec('PRAGMA writable_schema = ON');
        try {
            $update = $pdo->prepare("UPDATE sqlite_master SET sql = :sql WHERE type = 'table' AND name = :name");
            $update->execute([':sql' => $edited, ':name' => $table]);
            // Tells every connection, this one included, that the stored schema
            // changed, so the next statement is compiled against the new column.
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
            throw new RuntimeException("Migration 233: SQLite integrity check failed after editing {$table}.");
        }
    }
}
