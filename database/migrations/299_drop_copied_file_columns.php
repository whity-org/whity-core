<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use RuntimeException;
use Whity\Database\Database;

/**
 * DropCopiedFileColumns — the file owners read their file from `stored_files`
 * (merge map plumbing, WHIT-1159, part 2 of migration 298).
 *
 * Migration 298 recorded every stored object in `stored_files` and pointed
 * form_uploads, document_source_files, document_artifacts and chat_attachments
 * at it through `stored_file_id`. Every reader and writer now goes through
 * that link, so the owners' own copies of the file's details go:
 *
 * - form_uploads:          storage_key, content_type, byte_size, checksum_sha256, client_filename
 * - document_source_files: storage_key, content_type, byte_size, checksum_sha256, filename
 * - document_artifacts:    storage_key, content_type, byte_size, checksum_sha256
 * - chat_attachments:      storage_key, content_type, byte_size, filename
 *
 * Before anything is dropped, a row written between 298 and the new code (so
 * with no `stored_file_id`) is linked the same way 298 linked them. If a row is
 * still unlinked after that, the upgrade stops, named, and nothing is dropped.
 *
 * The old UNIQUE (storage_key) on uploads, source files and artifacts goes
 * with the column; `stored_files` keeps UNIQUE (tenant_id, storage_key), and a
 * unique index on each of those three tables' `stored_file_id` keeps "one row
 * per stored object" there.
 *
 * PostgreSQL drops the columns in place. SQLite cannot drop a column that is
 * in a UNIQUE or CHECK constraint, so there each table is rebuilt (new table,
 * copy, drop, rename, indexes back) with foreign keys off, so the drop does not
 * clear `route_finalisations.artifact_id` (migration 234).
 *
 * Idempotent: a table whose columns are already gone is left alone, so a second
 * run changes nothing. Reversible: down() puts the columns back and refills
 * them from `stored_files`. Table names spelled out literally for
 * TenantOwnedTablesTest and CoreTablesTest.
 */
final class DropCopiedFileColumns
{
    /** @var array<string, list<string>> */
    private const DROPPED = [
        'document_artifacts' => ['storage_key', 'content_type', 'byte_size', 'checksum_sha256'],
        'form_uploads' => ['storage_key', 'content_type', 'byte_size', 'checksum_sha256', 'client_filename'],
        'document_source_files' => ['storage_key', 'content_type', 'byte_size', 'checksum_sha256', 'filename'],
        'chat_attachments' => ['storage_key', 'content_type', 'byte_size', 'filename'],
    ];

    /** Tables whose storage_key was UNIQUE, and keep one row per stored file. */
    private const ONE_ROW_PER_FILE = ['document_artifacts', 'form_uploads', 'document_source_files'];

    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $pending = [];
        foreach (array_keys(self::DROPPED) as $table) {
            if (self::columnExists($pdo, $driver, $table, 'storage_key')) {
                $pending[] = $table;
            }
        }

        if ($pending !== []) {
            self::relink($db, $pdo, $pending);

            if ($driver === 'pgsql') {
                self::inTransaction($pdo, static function () use ($db, $pending): void {
                    foreach ($pending as $table) {
                        $db->exec("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_storage_key_key");
                        $db->exec("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_byte_size_check");
                        foreach (self::DROPPED[$table] as $column) {
                            $db->exec("ALTER TABLE {$table} DROP COLUMN IF EXISTS {$column}");
                        }
                    }
                });
            } else {
                self::rebuildSqlite($pdo, $pending);
            }
        }

        foreach (self::ONE_ROW_PER_FILE as $table) {
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_{$table}_stored_file ON {$table} (stored_file_id)");
        }
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        foreach (self::ONE_ROW_PER_FILE as $table) {
            $db->exec("DROP INDEX IF EXISTS ux_{$table}_stored_file");
        }

        $types = [
            'storage_key' => 'VARCHAR(512)',
            'content_type' => 'VARCHAR(128)',
            'byte_size' => 'BIGINT',
            'checksum_sha256' => 'VARCHAR(64)',
            'client_filename' => 'VARCHAR(255)',
            'filename' => 'VARCHAR(255)',
        ];
        foreach (self::DROPPED as $table => $columns) {
            foreach ($columns as $column) {
                if (!self::columnExists($pdo, $driver, $table, $column)) {
                    $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$types[$column]}");
                }
                $source = $column === 'client_filename' ? 'filename' : $column;
                // Unscoped by design (migration): every workspace's rows get their file's details back.
                $db->exec("
                    UPDATE {$table} SET {$column} = (
                        SELECT f.{$source} FROM stored_files f WHERE f.id = {$table}.stored_file_id AND f.tenant_id = {$table}.tenant_id
                    ) WHERE {$column} IS NULL
                ");
            }
        }

        if ($driver !== 'pgsql') {
            // SQLite cannot add constraints to an existing column; the columns
            // come back plain, which is all the older code needs.
            return;
        }

        $notNull = [
            'document_artifacts' => ['storage_key', 'content_type', 'byte_size', 'checksum_sha256'],
            'form_uploads' => ['storage_key', 'content_type', 'byte_size', 'checksum_sha256'],
            'document_source_files' => ['storage_key', 'content_type', 'byte_size', 'checksum_sha256'],
            'chat_attachments' => ['storage_key', 'content_type', 'byte_size'],
        ];
        foreach ($notNull as $table => $columns) {
            foreach ($columns as $column) {
                if (self::count($pdo, "SELECT COUNT(*) FROM {$table} WHERE {$column} IS NULL") === 0) {
                    $db->exec("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
                }
            }
        }
        foreach (self::ONE_ROW_PER_FILE as $table) {
            if (!self::constraintExists($pdo, "{$table}_storage_key_key")) {
                $db->exec("ALTER TABLE {$table} ADD CONSTRAINT {$table}_storage_key_key UNIQUE (storage_key)");
            }
        }
        if (!self::constraintExists($pdo, 'form_uploads_byte_size_check')) {
            $db->exec('ALTER TABLE form_uploads ADD CONSTRAINT form_uploads_byte_size_check CHECK (byte_size > 0)');
        }
    }

    /**
     * Link every row still without a `stored_file_id`, exactly as migration 298
     * did, then stop, named, if any row is left. Runs only on tables that still
     * have their own copy of the details.
     *
     * @param list<string> $tables
     */
    private static function relink(Database $db, PDO $pdo, array $tables): void
    {
        $inserts = [
            // Artifacts first: an upload claimed onto a document shares its key,
            // and the artifact is the record that outlives the upload.
            'document_artifacts' => "
                INSERT INTO stored_files (tenant_id, kind, storage_key, content_type, byte_size, checksum_sha256, filename, created_by_profile_id, created_at)
                SELECT a.tenant_id, 'document_artifact', a.storage_key, a.content_type, a.byte_size, a.checksum_sha256, NULL, a.rendered_by, a.rendered_at
                  FROM document_artifacts a
                 WHERE a.stored_file_id IS NULL AND a.storage_key IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM stored_files f WHERE f.tenant_id = a.tenant_id AND f.storage_key = a.storage_key)
            ",
            'form_uploads' => "
                INSERT INTO stored_files (tenant_id, kind, storage_key, content_type, byte_size, checksum_sha256, filename, created_by_profile_id, created_at)
                SELECT u.tenant_id, 'form_upload', u.storage_key, u.content_type, u.byte_size, u.checksum_sha256, u.client_filename, u.uploaded_by_profile_id, u.created_at
                  FROM form_uploads u
                 WHERE u.stored_file_id IS NULL AND u.storage_key IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM stored_files f WHERE f.tenant_id = u.tenant_id AND f.storage_key = u.storage_key)
            ",
            'document_source_files' => "
                INSERT INTO stored_files (tenant_id, kind, storage_key, content_type, byte_size, checksum_sha256, filename, created_by_profile_id, created_at)
                SELECT s.tenant_id, 'document_source', s.storage_key, s.content_type, s.byte_size, s.checksum_sha256, s.filename, s.uploaded_by_profile_id, s.created_at
                  FROM document_source_files s
                 WHERE s.stored_file_id IS NULL AND s.storage_key IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM stored_files f WHERE f.tenant_id = s.tenant_id AND f.storage_key = s.storage_key)
            ",
            'chat_attachments' => "
                INSERT INTO stored_files (tenant_id, kind, storage_key, content_type, byte_size, checksum_sha256, filename, created_by_profile_id, created_at)
                SELECT c.tenant_id, 'chat_attachment', c.storage_key, c.content_type, c.byte_size, NULL, c.filename, c.uploaded_by_profile_id, c.created_at
                  FROM chat_attachments c
                 WHERE c.stored_file_id IS NULL AND c.storage_key IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM stored_files f WHERE f.tenant_id = c.tenant_id AND f.storage_key = c.storage_key)
            ",
        ];

        // Unscoped by design (migration): every workspace's rows are linked.
        foreach ($tables as $table) {
            $db->exec($inserts[$table]);
        }
        foreach ($tables as $table) {
            $db->exec("
                UPDATE {$table} SET stored_file_id = (
                    SELECT f.id FROM stored_files f WHERE f.tenant_id = {$table}.tenant_id AND f.storage_key = {$table}.storage_key
                ) WHERE stored_file_id IS NULL
            ");
        }

        $unlinked = [];
        foreach ($tables as $table) {
            $count = self::count($pdo, "SELECT COUNT(*) FROM {$table} WHERE stored_file_id IS NULL");
            if ($count > 0) {
                $unlinked[] = "{$count} in {$table}";
            }
        }
        if ($unlinked !== []) {
            throw new RuntimeException(
                'Some files are not linked to stored_files (' . implode(', ', $unlinked) . '), so their details cannot be dropped yet. '
                . 'Nothing was dropped and nothing in file storage was changed. Fix those rows and run the upgrade again.'
            );
        }
    }

    /**
     * SQLite: rebuild each table without the dropped columns. The columns,
     * foreign keys and indexes that stay are read from the table itself, so
     * columns later migrations added come along.
     *
     * @param list<string> $tables
     */
    private static function rebuildSqlite(PDO $pdo, array $tables): void
    {
        $fkOn = self::count($pdo, 'PRAGMA foreign_keys') === 1;
        if ($fkOn && $pdo->inTransaction()) {
            // Dropping a table with foreign keys on clears the rows that point
            // at it, and the pragma cannot change inside a transaction.
            throw new RuntimeException('Migration 299 rebuilds tables on SQLite and must run outside a transaction when foreign keys are on. Nothing was dropped.');
        }
        if ($fkOn) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }

        try {
            self::inTransaction($pdo, static function () use ($pdo, $tables): void {
                foreach ($tables as $table) {
                    self::rebuildSqliteTable($pdo, $table, self::DROPPED[$table]);
                }
            });
        } finally {
            if ($fkOn) {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    }

    /** @param list<string> $drop */
    private static function rebuildSqliteTable(PDO $pdo, string $table, array $drop): void
    {
        $columns = self::rows($pdo, "PRAGMA table_info(\"{$table}\")");
        $foreignKeys = self::rows($pdo, "PRAGMA foreign_key_list(\"{$table}\")");
        $indexes = self::rows($pdo, "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = '{$table}' AND sql IS NOT NULL");

        $references = [];
        foreach ($foreignKeys as $fk) {
            $references[(string) $fk['from']] = sprintf(
                ' REFERENCES %s(%s) ON DELETE %s',
                (string) $fk['table'],
                (string) $fk['to'],
                (string) $fk['on_delete'],
            );
        }

        $definitions = [];
        $kept = [];
        foreach ($columns as $column) {
            $name = (string) $column['name'];
            if (in_array($name, $drop, true)) {
                continue;
            }
            $type = (string) ($column['type'] ?? '');
            $line = '"' . $name . '" ' . ($type !== '' ? $type : 'TEXT');
            if ((int) ($column['pk'] ?? 0) > 0) {
                $line .= ' PRIMARY KEY';
            } else {
                if ((int) ($column['notnull'] ?? 0) > 0) {
                    $line .= ' NOT NULL';
                }
                $default = $column['dflt_value'] ?? null;
                if ($default !== null && (string) $default !== '') {
                    $default = (string) $default;
                    $line .= ' DEFAULT ' . ($default[0] === '(' || $default[0] === "'" ? $default : '(' . $default . ')');
                }
                $line .= $references[$name] ?? '';
            }
            $definitions[] = $line;
            $kept[] = '"' . $name . '"';
        }
        if ($table === 'chat_attachments') {
            $definitions[] = "CONSTRAINT chat_attachments_kind_check CHECK (kind IN ('image', 'video', 'file', 'voice'))";
        }

        $list = implode(', ', $kept);
        $pdo->exec("DROP TABLE IF EXISTS \"{$table}__299\"");
        $pdo->exec("CREATE TABLE \"{$table}__299\" (\n    " . implode(",\n    ", $definitions) . "\n)");
        $pdo->exec("INSERT INTO \"{$table}__299\" ({$list}) SELECT {$list} FROM \"{$table}\"");
        $pdo->exec("DROP TABLE \"{$table}\"");
        $pdo->exec("ALTER TABLE \"{$table}__299\" RENAME TO \"{$table}\"");

        foreach ($indexes as $index) {
            $sql = (string) $index['sql'];
            foreach ($drop as $column) {
                if (preg_match('/\b' . preg_quote($column, '/') . '\b/', $sql) === 1) {
                    continue 2;
                }
            }
            $pdo->exec($sql);
        }
    }

    private static function inTransaction(PDO $pdo, callable $work): void
    {
        if ($pdo->inTransaction()) {
            $work();

            return;
        }
        $pdo->beginTransaction();
        try {
            $work();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function columnExists(PDO $pdo, string $driver, string $table, string $column): bool
    {
        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column'
            );
            $stmt->execute([':table' => $table, ':column' => $column]);
            $found = (int) $stmt->fetchColumn() > 0;
            $stmt->closeCursor();

            return $found;
        }
        foreach (self::rows($pdo, "PRAGMA table_info(\"{$table}\")") as $info) {
            if ((string) $info['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    private static function constraintExists(PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pg_constraint WHERE conname = :name');
        $stmt->execute([':name' => $name]);
        $found = (int) $stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        return $found;
    }

    private static function count(PDO $pdo, string $sql): int
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return 0;
        }
        $value = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $rows;
    }
}
