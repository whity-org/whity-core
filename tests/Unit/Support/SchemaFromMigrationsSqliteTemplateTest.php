<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;

/**
 * The SQLite template has to be invisible: a copy must be the same database a
 * real migration run produces, and no copy may see another's writes. Most of the
 * real-engine suite stands on this, so a difference here would quietly change
 * what all of those tests prove.
 */
final class SchemaFromMigrationsSqliteTemplateTest extends TestCase
{
    /** Every connection setting worth comparing, not only the ones the copy sets. */
    private const PRAGMAS = [
        'foreign_keys',
        'recursive_triggers',
        'legacy_alter_table',
        'defer_foreign_keys',
        'ignore_check_constraints',
        'user_version',
        'application_id',
        'automatic_index',
        'reverse_unordered_selects',
        'query_only',
        'trusted_schema',
        'encoding',
    ];

    protected function setUp(): void
    {
        if (($_ENV['PHPUNIT_PG_DSN'] ?? getenv('PHPUNIT_PG_DSN') ?: null) !== null) {
            self::markTestSkipped('The SQLite template is not used on the PostgreSQL path.');
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV['PHPUNIT_SQLITE_NO_TEMPLATE'], $_ENV['INITIAL_SYSTEM_ADMIN_EMAIL']);
        putenv('PHPUNIT_SQLITE_NO_TEMPLATE');
        putenv('INITIAL_SYSTEM_ADMIN_EMAIL');
    }

    public function testACopyIsTheSameDatabaseAsARealMigrationRun(): void
    {
        SchemaFromMigrations::make();
        // A second make(), so this one really comes from the template.
        $copy = SchemaFromMigrations::make();

        $_ENV['PHPUNIT_SQLITE_NO_TEMPLATE'] = '1';
        $migrated = SchemaFromMigrations::make();
        unset($_ENV['PHPUNIT_SQLITE_NO_TEMPLATE']);

        self::assertSame($this->objects($migrated), $this->objects($copy));
        self::assertSame($this->pragmas($migrated), $this->pragmas($copy));

        $tables = array_column(
            $this->all($migrated, "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"),
            'name'
        );
        self::assertNotEmpty($tables);

        foreach ($tables as $table) {
            self::assertSame(
                $this->rows($migrated, (string) $table),
                $this->rows($copy, (string) $table),
                "Table {$table} differs from a real migration run."
            );
        }
    }

    public function testOneCopysWritesAreNeverVisibleToAnother(): void
    {
        $first  = SchemaFromMigrations::make();
        $second = SchemaFromMigrations::make();

        $before = $this->rows($second, 'permissions');
        self::assertNotEmpty($before);

        $first->exec('DELETE FROM permissions');
        $first->exec('DROP TABLE languages');
        $first->exec('CREATE TABLE only_in_first (id INTEGER)');

        $third = SchemaFromMigrations::make();

        foreach ([$second, $third] as $other) {
            self::assertSame($before, $this->rows($other, 'permissions'));
            self::assertNotEmpty($this->rows($other, 'languages'));
            self::assertArrayNotHasKey('only_in_first', $this->objects($other));
        }
    }

    public function testACopyDoesNotStayAttachedToTheTemplate(): void
    {
        $databases = $this->all(SchemaFromMigrations::make(), 'PRAGMA database_list');

        self::assertSame(['main'], array_column($databases, 'name'));
        self::assertSame('', $databases[0]['file']);
    }

    public function testACopyKeepsTheStringifyFetchesChoice(): void
    {
        $sql = 'SELECT id FROM tenants ORDER BY id LIMIT 1';

        self::assertIsInt($this->one(SchemaFromMigrations::make(), $sql));
        self::assertIsString($this->one(SchemaFromMigrations::make(true), $sql));
    }

    public function testACopyStillTranslatesPostgresSql(): void
    {
        $pdo = SchemaFromMigrations::make();

        $pdo->exec('CREATE TABLE translated_probe (id SERIAL PRIMARY KEY, meta JSONB, at TIMESTAMP DEFAULT NOW())');
        $pdo->exec("INSERT INTO translated_probe (meta) VALUES ('{}'::jsonb)");

        self::assertSame('{}', $this->one($pdo, 'SELECT meta FROM translated_probe'));
        self::assertNotNull($this->one($pdo, 'SELECT NOW()'));
    }

    /** An input the migrations read must not be served from another input's template. */
    public function testAnEnvironmentInputTheMigrationsReadGetsItsOwnTemplate(): void
    {
        $email = 'templated-admin@example.test';
        $sql   = "SELECT COUNT(*) FROM profile_emails WHERE email = '{$email}'";

        self::assertSame(0, (int) $this->one(SchemaFromMigrations::make(), $sql));

        $_ENV['INITIAL_SYSTEM_ADMIN_EMAIL'] = $email;
        self::assertSame(1, (int) $this->one(SchemaFromMigrations::make(), $sql));

        unset($_ENV['INITIAL_SYSTEM_ADMIN_EMAIL']);
        self::assertSame(0, (int) $this->one(SchemaFromMigrations::make(), $sql));
    }

    /** Tests clean up the temp directory; losing the template must not fail anything. */
    public function testALostTemplateFileIsRebuilt(): void
    {
        SchemaFromMigrations::make();
        $files = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'whity-phpunit-sqlite-' . getmypid() . '-*.db') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            unlink($file);
        }

        $pdo = SchemaFromMigrations::make();

        self::assertGreaterThan(0, (int) $this->one($pdo, 'SELECT COUNT(*) FROM permissions'));
    }

    /**
     * NOW() holds still for a whole statement, as PostgreSQL's does (WHIT-949).
     *
     * Migration 121 writes `NOW(), NOW()` as created_at and updated_at, and
     * migration 124 claims only rows where updated_at <= created_at. A NOW()
     * that read the clock on every call gave a row two different seconds
     * whenever the clock ticked between the calls, and that row came out of the
     * migrations unclaimed. Here the clock is made to tick between the calls on
     * purpose, through both exec() and prepare().
     */
    public function testNowHoldsStillForAWholeStatement(): void
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->sqliteCreateFunction('whity_wait_for_next_second', static function (): int {
            $start = time();
            while (time() === $start) {
                usleep(20_000);
            }

            return 1;
        }, 0);

        $pdo->exec('CREATE TABLE clock_probe (created_at TEXT, waited INTEGER, updated_at TEXT)');
        $pdo->exec('INSERT INTO clock_probe VALUES (NOW(), whity_wait_for_next_second(), NOW())');
        $pdo->prepare('INSERT INTO clock_probe SELECT NOW(), whity_wait_for_next_second(), NOW() WHERE :seed = \'catalogue\'')
            ->execute([':seed' => 'catalogue']);

        $rows = $this->all($pdo, 'SELECT created_at, updated_at FROM clock_probe');
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($row['created_at'], $row['updated_at']);
        }

        // Text that says NOW() is not a call.
        self::assertSame('NOW()', $this->one($pdo, "SELECT 'NOW()'"));
    }

    /**
     * A template built after a test changed the process is still exactly what a
     * process nothing has touched gets from running the migrations (WHIT-949).
     *
     * The template is built the first time any test in a process asks for a
     * database, so whatever the tests before it changed is there when it is
     * built. This test changes what tests commonly change (the timezone and an
     * environment variable the migrations do not read), forces a rebuild, and
     * compares the copy with a migration run in a separate, clean PHP process.
     */
    public function testATemplateBuiltAfterTheProcessChangedMatchesACleanProcess(): void
    {
        $timezone = date_default_timezone_get();
        $env = [];
        foreach (SchemaFromMigrations::PG_TEMPLATE_ENV_INPUTS as $name) {
            $value = $_ENV[$name] ?? getenv($name);
            if (is_string($value)) {
                $env[] = $name . '=' . $value;
            }
        }

        try {
            date_default_timezone_set('Pacific/Kiritimati');
            $_ENV['WHITY_TEMPLATE_PROBE'] = 'changed';
            putenv('WHITY_TEMPLATE_PROBE=changed');

            // Lose this process's template files so the next make() builds anew.
            foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'whity-phpunit-sqlite-' . getmypid() . '-*.db') ?: [] as $file) {
                unlink($file);
            }
            SchemaFromMigrations::make();
            $copy = SchemaFromMigrations::make();
        } finally {
            date_default_timezone_set($timezone);
            unset($_ENV['WHITY_TEMPLATE_PROBE']);
            putenv('WHITY_TEMPLATE_PROBE');
        }

        $clean = $this->cleanProcessMigrationRun($env);

        self::assertSame(
            array_column($clean['objects'], null, 'name'),
            array_column($this->all($copy, 'SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY name'), null, 'name')
        );
        self::assertNotEmpty($clean['tables']);
        foreach ($clean['tables'] as $table => $rows) {
            self::assertSame(
                $this->mask($rows),
                $this->rows($copy, (string) $table),
                "Table {$table} differs from a migration run in a clean process."
            );
        }
    }

    /**
     * Every file a migration run reads is part of the template's key, so an
     * edit to a catalogue or to a class a migration calls builds a new one.
     */
    public function testTheTemplateKeyCoversEveryFileTheMigrationsRead(): void
    {
        $files = SchemaFromMigrations::fingerprintFiles();

        foreach ([
            'database/migrations/124_add_translation_source_provenance.php',
            'database/migrations/135_sync_permission_descriptions.php',
            'database/i18n/admin.json',
            'src/Core/i18n/TranslationSync.php',
            'src/Core/i18n/TranslationCatalog.php',
            'src/Core/RBAC/CorePermissionDescriptions.php',
            'src/Core/RBAC/CorePermissions.php',
        ] as $expected) {
            self::assertContains($expected, $files);
        }
        self::assertNotEmpty(preg_grep('#^database/i18n/[^/]+/[^/]+\.json$#', $files), 'Locale catalogues are part of the key.');

        foreach (['RENDER_SERVICE_URL', 'RENDER_SHARED_SECRET', 'COMPOSE_PROFILES', 'INITIAL_SYSTEM_ADMIN_EMAIL'] as $name) {
            self::assertContains($name, SchemaFromMigrations::PG_TEMPLATE_ENV_INPUTS);
        }
    }

    /**
     * @param list<string> $env NAME=value pairs the migrations read
     * @return array{objects: list<array<string, mixed>>, tables: array<string, list<array<string, mixed>>>}
     */
    private function cleanProcessMigrationRun(array $env): array
    {
        $command = array_merge(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/sqlite-clean-migration-dump.php'],
            $env
        );
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);

        $result = unserialize($output, ['allowed_classes' => false]);
        self::assertIsArray($result, $errors);

        /** @var array{objects: list<array<string, mixed>>, tables: array<string, list<array<string, mixed>>>} */
        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private function objects(PDO $pdo): array
    {
        $objects = [];
        foreach ($this->all($pdo, 'SELECT type, name, tbl_name, sql FROM sqlite_master') as $row) {
            $objects[(string) $row['name']] = $row;
        }
        ksort($objects);

        return $objects;
    }

    /** @return array<string, mixed> */
    private function pragmas(PDO $pdo): array
    {
        $values = [];
        foreach (self::PRAGMAS as $pragma) {
            $values[$pragma] = $this->one($pdo, 'PRAGMA ' . $pragma);
        }

        return $values;
    }

    /**
     * Every row, with its rowid and its exact types.
     *
     * Two kinds of value are masked because they differ between any two real
     * migration runs: the clock at the moment of seeding, and salted hashes.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $table): array
    {
        // SQLite's own counter table: what counts is each counter's value. Its
        // rowids have holes after a table drop, which VACUUM closes up.
        $query = $table === 'sqlite_sequence'
            ? 'SELECT name, seq FROM sqlite_sequence ORDER BY name'
            : 'SELECT rowid AS whity_rowid, * FROM "' . $table . '" ORDER BY rowid';

        return $this->mask($this->all($pdo, $query));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function mask(array $rows): array
    {
        return array_map(static fn(array $row): array => array_map(
            static function (mixed $value): mixed {
                if (is_string($value) && preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d/', $value) === 1) {
                    return '<timestamp>';
                }
                if (is_string($value) && preg_match('/^\$(2[aby]|argon2)/', $value) === 1) {
                    return '<salted hash>';
                }

                return $value;
            },
            $row
        ), $rows);
    }

    /** @return list<array<string, mixed>> */
    private function all(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function one(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }
}
