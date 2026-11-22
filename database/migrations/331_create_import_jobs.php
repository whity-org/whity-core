<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateImportJobs — one import table for every kind of record (WHIT-1322,
 * design section 34 §6, register IM.1-IM.3).
 *
 * - `import_jobs`: one spreadsheet import, of a `kind` (`contacts`,
 *   `inventory.items`, `inventory.opening_stock`, and later accounting's).
 *   The parsed rows are kept on it while the person matches columns and
 *   checks, as `contact_imports` kept them; `options_json` holds the mapping
 *   and choices, `summary_json` what it will do or did.
 * - `import_job_changes`: what an import did to each record, with the values
 *   before, so it can be undone for 24 hours. `entity_type` says which record
 *   (`contact`, `inventory.item`, `inventory.stock`); `extra_json` holds what
 *   the kind needs to undo beyond the values (tags added, a stock batch).
 *
 * The contacts' own import tables (migration 261) are copied in, each import
 * once (matched on workspace, file and the time it was made), with its
 * changes; migration 335 then drops them. Nobody's import older than a day
 * can be undone anyway, but one made today keeps its Undo.
 *
 * Every table is tenant-owned and named literally for TenantOwnedTablesTest
 * and CoreTablesTest. Idempotent and reversible.
 */
final class CreateImportJobs
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS import_jobs (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                kind                  VARCHAR(60)   NOT NULL,
                created_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                file_name             VARCHAR(255)  NOT NULL DEFAULT \'\',
                status                VARCHAR(20)   NOT NULL DEFAULT \'uploaded\',
                header_json           TEXT          NOT NULL,
                rows_json             TEXT          NOT NULL,
                options_json          TEXT          NOT NULL DEFAULT \'{}\',
                summary_json          TEXT          NOT NULL DEFAULT \'{}\',
                imported_at           TIMESTAMP,
                undone_at             TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_import_jobs_tenant ON import_jobs(tenant_id, kind, created_at)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS import_job_changes (
                id          SERIAL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id)     ON DELETE CASCADE,
                job_id      INTEGER      NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE,
                entity_type VARCHAR(40)  NOT NULL,
                -- @reference-lint-ignore: the record an import made or changed; its table depends on entity_type, and a row for a record deleted since is skipped on undo
                entity_id   INTEGER      NOT NULL,
                action      VARCHAR(10)  NOT NULL,
                before_json TEXT,
                extra_json  TEXT         NOT NULL DEFAULT \'{}\',
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_import_job_changes_job ON import_job_changes(tenant_id, job_id)');

        self::copyContactImports($db);
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS import_job_changes');
        $db->exec('DROP TABLE IF EXISTS import_jobs');
    }

    /** Copies migration 261's contact imports in, each once. */
    private static function copyContactImports(Database $db): void
    {
        if (!self::tableExists($db->getPdo(), 'contact_imports')) {
            return;
        }
        // A one-off copy of every workspace's rows between two tenant-owned tables; each row keeps its own tenant_id
        $imports = $db->query(
            'SELECT id, tenant_id, created_by_profile_id, file_name, status, header_json, rows_json, options_json, summary_json,
                    imported_at, undone_at, created_at, updated_at
               FROM contact_imports ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($imports as $import) {
            $already = $db->query(
                "SELECT id FROM import_jobs WHERE tenant_id = :tenant_id AND kind = 'contacts' AND file_name = :file_name AND created_at = :created_at",
                [':tenant_id' => (int) $import['tenant_id'], ':file_name' => (string) $import['file_name'], ':created_at' => (string) $import['created_at']]
            )->fetch(PDO::FETCH_ASSOC);
            if (is_array($already)) {
                continue;
            }
            $jobId = (int) $db->query(
                "INSERT INTO import_jobs (tenant_id, kind, created_by_profile_id, file_name, status, header_json, rows_json, options_json, summary_json,
                        imported_at, undone_at, created_at, updated_at)
                 VALUES (:tenant_id, 'contacts', :actor, :file_name, :status, :header, :rows, :options, :summary, :imported_at, :undone_at, :created_at, :updated_at)
                 RETURNING id",
                [
                    ':tenant_id' => (int) $import['tenant_id'],
                    ':actor' => $import['created_by_profile_id'] === null ? null : (int) $import['created_by_profile_id'],
                    ':file_name' => (string) $import['file_name'],
                    ':status' => (string) $import['status'],
                    ':header' => (string) $import['header_json'],
                    ':rows' => (string) $import['rows_json'],
                    ':options' => (string) $import['options_json'],
                    ':summary' => (string) $import['summary_json'],
                    ':imported_at' => $import['imported_at'],
                    ':undone_at' => $import['undone_at'],
                    ':created_at' => (string) $import['created_at'],
                    ':updated_at' => (string) $import['updated_at'],
                ]
            )->fetchColumn();
            $changes = $db->query(
                'SELECT contact_id, action, before_json, tags_added, created_at FROM contact_import_changes
                  WHERE tenant_id = :tenant_id AND import_id = :import_id ORDER BY id',
                [':tenant_id' => (int) $import['tenant_id'], ':import_id' => (int) $import['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($changes as $change) {
                $tags = json_decode((string) $change['tags_added'], true);
                $db->query(
                    "INSERT INTO import_job_changes (tenant_id, job_id, entity_type, entity_id, action, before_json, extra_json, created_at)
                     VALUES (:tenant_id, :job_id, 'contact', :entity_id, :action, :before, :extra, :created_at)",
                    [
                        ':tenant_id' => (int) $import['tenant_id'],
                        ':job_id' => $jobId,
                        ':entity_id' => (int) $change['contact_id'],
                        ':action' => (string) $change['action'],
                        ':before' => $change['before_json'],
                        ':extra' => (string) json_encode(['tags_added' => is_array($tags) ? array_values($tags) : []]),
                        ':created_at' => (string) $change['created_at'],
                    ]
                );
            }
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        // A failed probe would abort a PostgreSQL transaction, so ask the catalogue.
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :t'
            : "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':t' => $table]);

        return $stmt->fetchColumn() !== false;
    }
}
