<?php

declare(strict_types=1);

namespace Whity\Database\Migration;

use PDO;
use RuntimeException;
use Whity\Database\BootstrapIdentity;
use Whity\Database\Database;
use Whity\Database\InitialPassword;

/**
 * The schema baseline: the database the numbered migrations up to N leave
 * behind, saved once, so a FRESH install creates it in one step instead of
 * replaying every migration (WHIT-1240).
 *
 * Only an empty database takes the baseline. An install that already has
 * tables keeps running the numbered migrations exactly as before, and every
 * migration newer than the baseline runs on top of it as usual, so a fresh
 * install and an upgraded one end on the same schema. That claim is tested:
 * `MigrationBaselineEquivalenceTest` builds the database both ways, on
 * PostgreSQL and on SQLite, and diffs them.
 *
 * Files, all under database/baseline/:
 *   - manifest.json      which migrations the baseline stands for, and a
 *                        fingerprint of their source. If any of those files
 *                        changes, the fingerprint no longer matches, the
 *                        runner refuses the baseline and replays instead, and
 *                        a test fails until the baseline is regenerated.
 *   - baseline.pgsql.sql the PostgreSQL schema and seed rows (pg_dump, tidied
 *                        by scripts/migration-baseline.php).
 *   - baseline.sqlite.sql the same for the SQLite test engine.
 *
 * Seed rows that differ per install are not in the files as such:
 *   - every timestamp the migrations stamped with "now" is saved as a
 *     placeholder and set to the install's own time on load;
 *   - the bootstrap administrator's password comes from
 *     INITIAL_SYSTEM_ADMIN_PASSWORD (or a printed random one), and its email
 *     from INITIAL_SYSTEM_ADMIN_EMAIL, exactly as migrations 036 and 095 do.
 */
final class Baseline
{
    /** Stored in place of a "now" timestamp; replaced on load. */
    public const NOW_PLACEHOLDER = '1970-01-02 03:04:05';

    /** Stored in place of the bootstrap administrator's password hash. */
    public const PASSWORD_PLACEHOLDER = 'baseline-password-placeholder';

    public const MANIFEST = 'manifest.json';

    /**
     * @param array{
     *     through: int,
     *     last: string,
     *     migrations: list<string>,
     *     fingerprint: string,
     *     files: array<string, string>,
     *     now_columns: array<string, list<array{0: string, 1: string}>>,
     *     sqlite_version: ?string
     * } $manifest
     */
    private function __construct(private readonly string $dir, private readonly array $manifest)
    {
    }

    /** The baseline in `$dir`, or null when there is none. */
    public static function fromDirectory(string $dir): ?self
    {
        $file = $dir . '/' . self::MANIFEST;
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (
            !is_array($decoded)
            || !is_int($decoded['through'] ?? null)
            || !is_string($decoded['last'] ?? null)
            || !is_array($decoded['migrations'] ?? null)
            || !is_string($decoded['fingerprint'] ?? null)
            || !is_array($decoded['files'] ?? null)
        ) {
            throw new RuntimeException("The baseline manifest {$file} is not readable.");
        }

        $decoded['now_columns'] = is_array($decoded['now_columns'] ?? null) ? $decoded['now_columns'] : [];
        $decoded['sqlite_version'] = is_string($decoded['sqlite_version'] ?? null) ? $decoded['sqlite_version'] : null;

        /** @var array{through: int, last: string, migrations: list<string>, fingerprint: string, files: array<string, string>, now_columns: array<string, list<array{0: string, 1: string}>>, sqlite_version: ?string} $decoded */
        return new self($dir, $decoded);
    }

    /** The default location, next to database/migrations. */
    public static function defaultDirectory(): string
    {
        return dirname(__DIR__, 3) . '/database/baseline';
    }

    /** Highest migration number the baseline stands for. */
    public function through(): int
    {
        return $this->manifest['through'];
    }

    /** File stem of the newest migration in the baseline. */
    public function last(): string
    {
        return $this->manifest['last'];
    }

    /** @return list<string> File stems of the migrations the baseline stands for. */
    public function migrations(): array
    {
        return $this->manifest['migrations'];
    }

    public function fingerprint(): string
    {
        return $this->manifest['fingerprint'];
    }

    /** The SQLite version the SQLite baseline was made with, when recorded. */
    public function sqliteVersion(): ?string
    {
        return $this->manifest['sqlite_version'];
    }

    /**
     * Fingerprint of the migration sources the baseline was made from, as they
     * are now. Line endings are normalised so a Windows checkout agrees.
     *
     * @param list<string> $names File stems, in order.
     */
    public static function fingerprintOf(string $migrationDir, array $names): string
    {
        $hash = hash_init('sha256');
        foreach ($names as $name) {
            $file = $migrationDir . '/' . $name . '.php';
            if (!is_file($file)) {
                return 'missing:' . $name;
            }
            hash_update($hash, $name . "\n" . str_replace("\r\n", "\n", (string) file_get_contents($file)) . "\n");
        }

        return hash_final($hash);
    }

    /**
     * Why the baseline can't be used for this database, or null when it can.
     *
     * Reasons are plain sentences: the runner prints them and replays instead.
     */
    public function unusableBecause(PDO $pdo, string $migrationDir): ?string
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $file = $this->manifest['files'][$driver] ?? null;
        if (!is_string($file) || !is_file($this->dir . '/' . $file)) {
            return "there is no baseline for the {$driver} engine";
        }

        if ($driver === 'pgsql') {
            $schema = (string) self::scalar($pdo, 'SELECT current_schema()');
            if ($schema !== 'public') {
                return "the database's schema is {$schema}, and the baseline is for public";
            }
        }

        if (self::fingerprintOf($migrationDir, $this->migrations()) !== $this->fingerprint()) {
            return 'a migration it was made from has changed since (regenerate it with scripts/migration-baseline.php)';
        }

        return null;
    }

    /**
     * Whether the database has no tables yet, apart from an empty migrations
     * table. Only such a database may take the baseline.
     */
    public static function isFreshDatabase(PDO $pdo): bool
    {
        $tables = self::userTables($pdo);
        $others = array_values(array_diff($tables, ['core_schema_migrations']));
        if ($others !== []) {
            return false;
        }

        if (in_array('core_schema_migrations', $tables, true)) {
            return (int) self::scalar($pdo, 'SELECT COUNT(*) FROM core_schema_migrations') === 0;
        }

        return true;
    }

    /** @return list<string> */
    private static function userTables(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'pgsql'
            ? "SELECT table_name FROM information_schema.tables
               WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'"
            : "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";

        return array_map(static fn(mixed $name): string => (string) $name, self::column($pdo, $sql));
    }

    /** The SQL for an engine, as saved. */
    public function sql(string $driver): string
    {
        $file = $this->manifest['files'][$driver] ?? null;
        if (!is_string($file) || !is_file($this->dir . '/' . $file)) {
            throw new RuntimeException("There is no baseline for the {$driver} engine.");
        }

        return (string) file_get_contents($this->dir . '/' . $file);
    }

    /**
     * Load the baseline into a fresh database and record its migrations as
     * applied, in ONE transaction: it all lands or none of it does, so a
     * failed load leaves the database empty and the next run starts over.
     *
     * The caller has checked {@see isFreshDatabase()} and {@see unusableBecause()}.
     */
    public function apply(Database $db, bool $record = true): void
    {
        $pdo = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $this->sql($driver);

        // The saved SQL is already in the engine's own dialect. The base
        // class's exec() is called directly so a PDO that rewrites SQL on its
        // way through (the test suite's SQLite and PostgreSQL wrappers) cannot
        // touch it.
        $verbatim = static function (string $statement) use ($pdo): void {
            (new \ReflectionMethod(PDO::class, 'exec'))->invoke($pdo, $statement);
        };

        $pdo->beginTransaction();
        try {
            if ($driver === 'pgsql') {
                // pg_dump's settings, but only for this transaction.
                $verbatim('SET LOCAL statement_timeout = 0');
                $verbatim('SET LOCAL lock_timeout = 0');
                $verbatim('SET LOCAL check_function_bodies = false');
            } else {
                // Rows go in table by table; a foreign key is checked at commit.
                $verbatim('PRAGMA defer_foreign_keys = ON');
            }

            // An empty tracking table may already be there (the runner makes it
            // up front); the baseline creates it again with its real definition.
            $verbatim('DROP TABLE IF EXISTS core_schema_migrations');

            if ($driver === 'sqlite') {
                // PDO's SQLite driver runs one statement per exec().
                foreach (self::splitSqliteStatements($sql) as $statement) {
                    $verbatim($statement);
                }
            } else {
                $verbatim($sql);
            }

            $this->stampNow($pdo, $driver);
            $this->applyInstallSettings($db);

            if ($record) {
                $insert = $pdo->prepare(
                    'INSERT INTO core_schema_migrations (migration_name, executed_at, execution_time_ms)
                     VALUES (?, CURRENT_TIMESTAMP, 0)
                     ON CONFLICT (migration_name) DO NOTHING'
                );
                foreach ($this->migrations() as $name) {
                    $insert->execute([$name]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new RuntimeException('Loading the schema baseline failed, nothing was kept: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Set every placeholder timestamp to this install's time. */
    private function stampNow(PDO $pdo, string $driver): void
    {
        $now = $driver === 'pgsql' ? 'CURRENT_TIMESTAMP' : "datetime('now')";
        foreach ($this->manifest['now_columns'][$driver] ?? [] as [$table, $column]) {
            $t = self::quoteIdent($table);
            $c = self::quoteIdent($column);
            $stmt = $pdo->prepare("UPDATE {$t} SET {$c} = {$now} WHERE {$c} = ?");
            $stmt->execute([self::NOW_PLACEHOLDER]);
        }
    }

    /**
     * What migrations 036 and 095 read from the environment, applied to the
     * baseline's rows: the bootstrap administrator's password and email.
     */
    private function applyInstallSettings(Database $db): void
    {
        $email = BootstrapIdentity::DEFAULT_EMAIL;
        $hash = InitialPassword::hashFor('INITIAL_SYSTEM_ADMIN_PASSWORD', $email);

        // Unscoped by design (migration): profiles and profile_emails are sanctioned GLOBAL tables (ADR 0005)
        $db->query(
            'UPDATE profiles SET password_hash = :hash
             WHERE password_hash = :placeholder
               AND id IN (SELECT profile_id FROM profile_emails WHERE email = :email)',
            [':hash' => $hash, ':placeholder' => self::PASSWORD_PLACEHOLDER, ':email' => $email]
        );

        BootstrapIdentity::applyConfiguredEmail($db);
    }

    /**
     * Split the SQLite baseline into statements. The generator ends every
     * statement with `;` and a newline, and triggers with `END;`. A line that
     * ends in `;` inside a string literal is told apart by counting quotes.
     *
     * @return list<string>
     */
    public static function splitSqliteStatements(string $sql): array
    {
        $out = [];
        $buffer = '';
        foreach (explode("\n", str_replace("\r\n", "\n", $sql)) as $line) {
            if ($buffer === '' && (trim($line) === '' || str_starts_with($line, '--'))) {
                continue;
            }
            $buffer .= ($buffer === '' ? '' : "\n") . $line;
            if (str_ends_with(rtrim($line), ';') && self::isCompleteSqliteStatement($buffer)) {
                $out[] = $buffer;
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $out[] = $buffer;
        }

        return $out;
    }

    private static function isCompleteSqliteStatement(string $statement): bool
    {
        // A trigger body holds inner `;` lines; it is complete at its END;
        if (preg_match('/^\s*CREATE\s+(TEMP\w*\s+)?TRIGGER\b/i', $statement) === 1) {
            return preg_match('/\bEND\s*;\s*$/i', $statement) === 1;
        }

        // Inside an open string literal (odd number of quotes): not complete.
        return substr_count($statement, "'") % 2 === 0;
    }

    /** The first column of the first row of a query (false when there are no rows). */
    public static function scalar(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException("Query failed: {$sql}");
        }
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value;
    }

    /** @return list<mixed> The first column of every row of a query. */
    public static function column(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException("Query failed: {$sql}");
        }

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
