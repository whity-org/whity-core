<?php

declare(strict_types=1);

namespace Whity\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Database\Database;
use Whity\Database\Migration\Baseline;

/**
 * The schema baseline is only allowed to exist because it is the SAME database
 * the numbered migrations build (WHIT-1240). This test builds it both
 * ways on each engine and diffs everything a migration can leave behind:
 * tables, columns (in order), defaults, constraints, indexes, triggers,
 * functions, views, sequences and every seeded row.
 *
 * Values that differ per install by design are left out of the row diff:
 * timestamps (each install's own "now"), and the bootstrap administrator's
 * password hash (salted; both are checked to verify against the same
 * password instead).
 *
 * SQLite runs everywhere; PostgreSQL runs in the dialect shards, where
 * PHPUNIT_PG_DSN is set. There the replay side is SchemaFromMigrations' own
 * template, which replays every migration from scratch.
 */
final class MigrationBaselineEquivalenceTest extends TestCase
{
    private const PASSWORD = 'baseline-equivalence-password';

    private static string $root;
    private ?string $previousPassword = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        $previous = getenv('INITIAL_SYSTEM_ADMIN_PASSWORD');
        $this->previousPassword = is_string($previous) ? $previous : null;
        putenv('INITIAL_SYSTEM_ADMIN_PASSWORD=' . self::PASSWORD);
        $_ENV['INITIAL_SYSTEM_ADMIN_PASSWORD'] = self::PASSWORD;
    }

    protected function tearDown(): void
    {
        if ($this->previousPassword === null) {
            putenv('INITIAL_SYSTEM_ADMIN_PASSWORD');
            unset($_ENV['INITIAL_SYSTEM_ADMIN_PASSWORD']);
        } else {
            putenv('INITIAL_SYSTEM_ADMIN_PASSWORD=' . $this->previousPassword);
            $_ENV['INITIAL_SYSTEM_ADMIN_PASSWORD'] = $this->previousPassword;
        }
    }

    private function baseline(): Baseline
    {
        $baseline = Baseline::fromDirectory(self::$root . '/database/baseline');
        $this->assertNotNull($baseline, 'database/baseline/manifest.json is missing.');

        return $baseline;
    }

    public function testBaselineStillMatchesTheMigrationsItStandsFor(): void
    {
        $baseline = $this->baseline();
        $migrationDir = self::$root . '/database/migrations';

        $onDisk = array_map(
            static fn(string $f): string => pathinfo($f, PATHINFO_FILENAME),
            glob($migrationDir . '/*.php') ?: []
        );
        sort($onDisk);
        $expected = array_values(array_filter($onDisk, static fn(string $n): bool => (int) $n <= $baseline->through()));

        $this->assertSame($expected, $baseline->migrations(), 'The baseline must stand for exactly the migrations numbered up to ' . $baseline->through() . '.');
        $this->assertSame($baseline->last(), end($expected));
        $this->assertSame(
            $baseline->fingerprint(),
            Baseline::fingerprintOf($migrationDir, $baseline->migrations()),
            'A migration the baseline stands for has changed. Migrations that have shipped must not change; '
            . 'if this one really must, regenerate the baseline (docs/ops/migrations.md).'
        );
    }

    public function testSqliteBaselineBuildsTheSameDatabaseAsReplay(): void
    {
        $replay = SchemaFromMigrations::replaySqlite();

        $fromBaseline = SchemaFromMigrations::emptyTranslatingSqlite();
        $db = Database::withFactory(static fn(): PDO => $fromBaseline, 86400, 86400);
        $this->assertNull($this->baseline()->unusableBecause($fromBaseline, self::$root . '/database/migrations'));
        ob_start();
        try {
            $this->baseline()->apply($db, record: false);
        } finally {
            ob_end_clean();
        }
        $this->runMigrationsAfter($db, $this->baseline()->through(), sqlite: true);

        // What a migration can do on SQLite depends on its version (3.53 can
        // add a CHECK constraint to a table; 3.46, like CI's, can't). The
        // baseline is right for the version it was made with; on another, a
        // difference is reported but is not this baseline's fault.
        $made = $this->baseline()->sqliteVersion();
        $running = (string) Baseline::scalar($replay, 'SELECT sqlite_version()');
        try {
            $this->assertSnapshotsEqual(self::sqliteSnapshot($replay), self::sqliteSnapshot($fromBaseline));
        } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
            if ($made !== null && self::minorVersion($made) !== self::minorVersion($running)) {
                $this->markTestSkipped("SQLite {$running} builds a different schema from SQLite {$made}, which the baseline was made with: "
                    . strtok($e->getMessage(), "\n"));
            }
            throw $e;
        }
        $this->assertAdminPasswordVerifies($replay);
        $this->assertAdminPasswordVerifies($fromBaseline);
    }

    public function testPostgresBaselineBuildsTheSameDatabaseAsReplay(): void
    {
        if (!extension_loaded('pdo_pgsql') || !is_string($_ENV['PHPUNIT_PG_DSN'] ?? getenv('PHPUNIT_PG_DSN') ?: null)) {
            $this->markTestSkipped('PostgreSQL only: set PHPUNIT_PG_DSN (the dialect shards do).');
        }

        $replay = SchemaFromMigrations::make();
        $fromBaseline = SchemaFromMigrations::emptyPostgres();
        if ($fromBaseline === null) {
            $this->markTestSkipped('Could not create an empty PostgreSQL database.');
        }

        $db = Database::withFactory(static fn(): PDO => $fromBaseline, 86400, 86400);
        $this->assertNull($this->baseline()->unusableBecause($fromBaseline, self::$root . '/database/migrations'));
        ob_start();
        try {
            $this->baseline()->apply($db, record: false);
            $this->runMigrationsAfter($db, $this->baseline()->through(), sqlite: false);
        } finally {
            ob_end_clean();
        }

        $this->assertSnapshotsEqual(self::postgresSnapshot($replay), self::postgresSnapshot($fromBaseline));
        $this->assertAdminPasswordVerifies($replay);
        $this->assertAdminPasswordVerifies($fromBaseline);
    }

    /** Migrations newer than the baseline, run on top of it as `migrate run` would. */
    private function runMigrationsAfter(Database $db, int $through, bool $sqlite): void
    {
        SchemaFromMigrations::loadMigrationClasses();
        $files = glob(self::$root . '/database/migrations/*.php') ?: [];
        sort($files);
        ob_start();
        try {
            foreach ($files as $file) {
                if ((int) basename($file) <= $through) {
                    continue;
                }
                $parts = explode('_', pathinfo($file, PATHINFO_FILENAME));
                array_shift($parts);
                $class = 'Database\\Migrations\\' . implode('', array_map('ucfirst', $parts));
                try {
                    $class::up($db);
                } catch (\Throwable $e) {
                    // The replay skips the same PostgreSQL-only DDL on SQLite.
                    $message = strtolower($e->getMessage());
                    if (!$sqlite || (!str_contains($message, 'alter sequence') && !str_contains($message, 'near "type": syntax error'))) {
                        throw $e;
                    }
                }
            }
        } finally {
            ob_end_clean();
        }
    }

    private function assertAdminPasswordVerifies(PDO $pdo): void
    {
        $hash = self::queryVerbatim($pdo,
            "SELECT p.password_hash FROM profiles p JOIN profile_emails e ON e.profile_id = p.id
             WHERE e.email = 'system@whity.local'"
        );
        $this->assertCount(1, $hash);
        $this->assertTrue(
            password_verify(self::PASSWORD, (string) array_values($hash[0])[0]),
            'The bootstrap administrator must get INITIAL_SYSTEM_ADMIN_PASSWORD on both paths.'
        );
    }

    /**
     * @param array<string, mixed> $replay
     * @param array<string, mixed> $baseline
     */
    private function assertSnapshotsEqual(array $replay, array $baseline): void
    {
        $this->assertSame(array_keys($replay), array_keys($baseline));
        $differences = [];
        foreach ($replay as $section => $items) {
            foreach (array_keys(array_diff_key($items, $baseline[$section])) as $key) {
                $differences[] = "{$section} '{$key}': in the replay, not the baseline";
            }
            foreach (array_keys(array_diff_key($baseline[$section], $items)) as $key) {
                $differences[] = "{$section} '{$key}': in the baseline, not the replay";
            }
            foreach ($items as $key => $value) {
                if (array_key_exists($key, $baseline[$section]) && $value !== $baseline[$section][$key]) {
                    $differences[] = "{$section} '{$key}':\n    replay:   " . json_encode($value)
                        . "\n    baseline: " . json_encode($baseline[$section][$key]);
                }
            }
        }
        $this->assertSame([], $differences, "The baseline and the replay differ:\n" . implode("\n", $differences));
    }

    // ── Snapshots ──────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private static function postgresSnapshot(PDO $pdo): array
    {
        $q = static fn(string $sql): array => self::queryVerbatim($pdo, $sql);
        $snapshot = ['columns' => [], 'constraints' => [], 'indexes' => [], 'triggers' => [], 'functions' => [], 'views' => [], 'sequences' => [], 'extensions' => [], 'rows' => []];

        $byTable = [];
        foreach ($q("SELECT c.table_name, c.column_name, c.data_type, c.udt_name, c.character_maximum_length,
                            c.numeric_precision, c.numeric_scale, c.is_nullable, c.column_default, c.is_identity, c.is_generated
                     FROM information_schema.columns c
                     JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
                     WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE'
                     ORDER BY c.table_name, c.ordinal_position") as $row) {
            $table = (string) $row['table_name'];
            unset($row['table_name']);
            // `migrate run` makes the tracking table itself before migration
            // 003 can (DEFAULT CURRENT_TIMESTAMP), and the baseline was made
            // the same way; this replay runs 003 first (DEFAULT now()). Same
            // function in PostgreSQL.
            if ($table === 'core_schema_migrations' && $row['column_default'] === 'now()') {
                $row['column_default'] = 'CURRENT_TIMESTAMP';
            }
            $byTable[$table][] = implode(' | ', array_map(static fn($v): string => $v === null ? 'NULL' : (string) $v, $row));
        }
        foreach ($byTable as $table => $columns) {
            $snapshot['columns'][$table] = $columns;
        }

        foreach ($q("SELECT rel.relname AS tbl, con.conname, pg_get_constraintdef(con.oid) AS def
                     FROM pg_constraint con
                     JOIN pg_class rel ON rel.oid = con.conrelid
                     JOIN pg_namespace n ON n.oid = rel.relnamespace
                     WHERE n.nspname = current_schema()") as $row) {
            $snapshot['constraints'][$row['tbl'] . '.' . $row['conname']] = self::expression((string) $row['def']);
        }
        foreach ($q("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema()") as $row) {
            $snapshot['indexes'][(string) $row['indexname']] = $row['indexdef'];
        }
        foreach ($q("SELECT c.relname || '.' || t.tgname AS name, pg_get_triggerdef(t.oid) AS def
                     FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
                     JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE NOT t.tgisinternal AND n.nspname = current_schema()") as $row) {
            $snapshot['triggers'][(string) $row['name']] = $row['def'];
        }
        foreach ($q("SELECT p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')' AS name, pg_get_functiondef(p.oid) AS def
                     FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                     WHERE n.nspname = current_schema() AND p.prokind IN ('f', 'p')
                       AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')") as $row) {
            $snapshot['functions'][(string) $row['name']] = $row['def'];
        }
        foreach ($q("SELECT viewname, definition FROM pg_views WHERE schemaname = current_schema()") as $row) {
            $snapshot['views'][(string) $row['viewname']] = $row['definition'];
        }
        foreach ($q("SELECT sequencename, data_type, start_value, increment_by, min_value, max_value, last_value
                     FROM pg_sequences WHERE schemaname = current_schema()") as $row) {
            $name = (string) $row['sequencename'];
            if ($name === 'core_schema_migrations_id_seq') {
                continue; // the replay side records no migrations
            }
            unset($row['sequencename']);
            $snapshot['sequences'][$name] = implode(' | ', array_map(static fn($v): string => (string) $v, $row));
        }
        foreach ($q("SELECT extname, extversion FROM pg_extension") as $row) {
            $snapshot['extensions'][(string) $row['extname']] = $row['extversion'];
        }

        foreach (array_keys($snapshot['columns']) as $table) {
            if ($table === 'core_schema_migrations') {
                continue;
            }
            $kept = [];
            foreach ($q("SELECT column_name, data_type FROM information_schema.columns
                         WHERE table_schema = current_schema() AND table_name = " . $pdo->quote($table) . "
                         ORDER BY ordinal_position") as $col) {
                if (self::isPerInstall($table, (string) $col['column_name'], (string) $col['data_type'])) {
                    continue;
                }
                $kept[] = Baseline::quoteIdent((string) $col['column_name']);
            }
            $list = $kept === [] ? '1' : implode(', ', $kept);
            $t = Baseline::quoteIdent($table);
            // CR stripped: a Windows checkout's migrations seed CRLF text.
            $row = $q("SELECT COUNT(*) AS n,
                              md5(COALESCE(string_agg(replace(r::text, E'\\r', ''), E'\\n' ORDER BY replace(r::text, E'\\r', '')), '')) AS h
                       FROM (SELECT {$list} FROM {$t}) r")[0];
            $snapshot['rows'][$table] = $row['n'] . ' rows, ' . $row['h'];
        }

        return $snapshot;
    }

    /** @return array<string, array<string, mixed>> */
    private static function sqliteSnapshot(PDO $pdo): array
    {
        $snapshot = ['objects' => [], 'rows' => [], 'sequences' => []];
        foreach (self::queryVerbatim($pdo, "SELECT type, name, sql FROM sqlite_master
                 WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name") as $row) {
            // A Windows checkout's migrations carry CRLF into the SQL they store.
            $snapshot['objects'][$row['type'] . ' ' . $row['name']] = str_replace("\r\n", "\n", (string) $row['sql']);
        }

        foreach (self::queryVerbatim($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'core_schema_migrations' ORDER BY name") as $table) {
            $name = (string) $table['name'];
            $columns = self::queryVerbatim($pdo, 'PRAGMA table_info(' . Baseline::quoteIdent($name) . ')');
            $lines = [];
            foreach (self::queryVerbatim($pdo, 'SELECT * FROM ' . Baseline::quoteIdent($name)) as $row) {
                $cells = [];
                foreach ($columns as $col) {
                    $column = (string) $col['name'];
                    $value = $row[$column] ?? null;
                    if (self::isPerInstall($name, $column, (string) $col['type'])
                        || (is_string($value) && preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d/', $value) === 1)) {
                        $value = '<per-install>';
                    }
                    $cells[] = $value === null ? 'NULL' : str_replace("\r\n", "\n", (string) $value);
                }
                $lines[] = implode("\t", $cells);
            }
            sort($lines);
            $snapshot['rows'][$name] = count($lines) . ' rows, ' . md5(implode("\n", $lines));
        }

        if (self::queryVerbatim($pdo, "SELECT 1 FROM sqlite_master WHERE name = 'sqlite_sequence'") !== []) {
            foreach (self::queryVerbatim($pdo, 'SELECT name, seq FROM sqlite_sequence') as $row) {
                if ($row['name'] !== 'core_schema_migrations') {
                    $snapshot['sequences'][(string) $row['name']] = (int) $row['seq'];
                }
            }
            ksort($snapshot['sequences']);
        }

        return $snapshot;
    }

    /**
     * An expression with casts, parentheses and spacing taken out. PostgreSQL
     * deparses `x IN ('a', 'b')` differently once it has been through
     * pg_dump (the cast moves inside the ARRAY), which means the same thing;
     * comparing without casts and brackets keeps everything that can differ in
     * meaning and drops the wording.
     */
    private static function expression(string $sql): string
    {
        $sql = (string) preg_replace('/::[a-z_ ]+?(\[\])?(?=[\s,()\]]|$)/i', '', $sql);

        return (string) preg_replace('/[\s()]+/', '', $sql);
    }

    private static function minorVersion(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /** Columns that hold each install's own value: timestamps and the admin password. */
    private static function isPerInstall(string $table, string $column, string $type): bool
    {
        if ($table === 'profiles' && $column === 'password_hash') {
            return true;
        }

        return preg_match('/^(timestamp|date)/i', $type) === 1;
    }

    /** @return list<array<string, mixed>> */
    private static function queryVerbatim(PDO $pdo, string $sql): array
    {
        /** @var \PDOStatement $statement */
        $statement = (new \ReflectionMethod(PDO::class, 'query'))->invoke($pdo, $sql);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
