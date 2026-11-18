<?php

declare(strict_types=1);

/**
 * Make or check the schema baseline (database/baseline), see docs/ops/migrations.md.
 *
 *   php scripts/migration-baseline.php check
 *       Exit 1 when a migration the baseline stands for has changed since it
 *       was made. Needs no database.
 *
 *   php scripts/migration-baseline.php generate [--through=NNN]
 *       Replay migrations 001..NNN (default: the newest on disk) into a scratch
 *       PostgreSQL database and a scratch SQLite one, save both, and write the
 *       manifest. Needs a PostgreSQL 15 server and pg_dump of the same major:
 *
 *         BASELINE_PG_DSN       pgsql:host=127.0.0.1;port=5432;dbname=postgres
 *         BASELINE_PG_USER      a role that may CREATE DATABASE (default whity)
 *         BASELINE_PG_PASSWORD  (default whity_dev)
 *         BASELINE_PG_DUMP      command that runs pg_dump against that server,
 *                               e.g. "docker exec -i my_postgres pg_dump -U whity"
 *                               (default "pg_dump"); the database name is appended
 *
 *       The INITIAL_* and render variables are cleared first, so nothing about
 *       the machine that made it ends up in the baseline.
 */

use Tests\Support\SchemaFromMigrations;
use Whity\Database\Database;
use Whity\Database\Migration\Baseline;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$migrationDir = $root . '/database/migrations';
$baselineDir = $root . '/database/baseline';
$action = $argv[1] ?? 'check';

if ($action === 'check') {
    $baseline = Baseline::fromDirectory($baselineDir);
    if ($baseline === null) {
        fwrite(STDOUT, "No baseline in database/baseline.\n");
        exit(0);
    }
    if (Baseline::fingerprintOf($migrationDir, $baseline->migrations()) !== $baseline->fingerprint()) {
        fwrite(STDERR, "A migration the baseline stands for (001 to {$baseline->through()}) has changed.\n"
            . "Migrations that have shipped must not change; if this change is really meant,\n"
            . "regenerate the baseline: php scripts/migration-baseline.php generate --through={$baseline->through()}\n");
        exit(1);
    }
    fwrite(STDOUT, "The baseline matches migrations 001 to {$baseline->through()}.\n");
    exit(0);
}

if ($action !== 'generate') {
    fwrite(STDERR, "Usage: php scripts/migration-baseline.php check|generate [--through=NNN]\n");
    exit(2);
}

// ── Inputs ──────────────────────────────────────────────────────────────────
$through = null;
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--through=(\d+)$/', $arg, $m) === 1) {
        $through = (int) $m[1];
    }
}

$files = glob($migrationDir . '/*.php') ?: [];
sort($files);
$names = [];
foreach ($files as $file) {
    $number = (int) basename($file);
    if ($through === null || $number <= $through) {
        $names[] = pathinfo($file, PATHINFO_FILENAME);
    }
}
if ($names === []) {
    fwrite(STDERR, "No migrations to put in the baseline.\n");
    exit(1);
}
$last = $names[count($names) - 1];
$through = (int) $last;

foreach ([
    'INITIAL_SYSTEM_ADMIN_PASSWORD', 'INITIAL_ADMIN_PASSWORD', 'INITIAL_USER_PASSWORD',
    'INITIAL_SUPERUSER_PASSWORD', 'INITIAL_SYSTEM_ADMIN_EMAIL',
    'RENDER_SERVICE_URL', 'RENDER_SHARED_SECRET', 'COMPOSE_PROFILES',
] as $var) {
    putenv($var);
    unset($_ENV[$var]);
}

$pgDsn = getenv('BASELINE_PG_DSN') ?: '';
$pgUser = getenv('BASELINE_PG_USER') ?: 'whity';
$pgPassword = getenv('BASELINE_PG_PASSWORD') ?: 'whity_dev';
$pgDump = getenv('BASELINE_PG_DUMP') ?: 'pg_dump';
if ($pgDsn === '') {
    fwrite(STDERR, "Set BASELINE_PG_DSN (see the top of this script).\n");
    exit(2);
}

$say = static function (string $line): void {
    fwrite(STDOUT, $line . "\n");
};

// ── PostgreSQL ──────────────────────────────────────────────────────────────
$admin = new PDO($pgDsn, $pgUser, $pgPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$scratch = 'whity_baseline_' . bin2hex(random_bytes(4));
$admin->exec("CREATE DATABASE \"{$scratch}\" TEMPLATE template0");
$say("Replaying 001 to {$through} into PostgreSQL database {$scratch}...");

try {
    $dsn = (string) preg_replace('/\bdbname=[^;]*/i', 'dbname=' . $scratch, $pgDsn);
    $pdo = new PDO($dsn, $pgUser, $pgPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET synchronous_commit = off');
    $pgVersion = (string) $pdo->query('SHOW server_version')->fetchColumn();
    $start = (string) $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();

    $db = Database::withFactory(static fn(): PDO => $pdo, 86400, 86400);
    $db->forceConnect();
    $started = microtime(true);
    replay($db, $migrationDir, $names);
    $say(sprintf('  replayed in %.1f s', microtime(true) - $started));

    $pgNowColumns = placeholdersPostgres($pdo, $start);
    unset($db, $pdo);

    $dump = runCommand($pgDump . ' --schema-only --no-owner --no-privileges ' . escapeshellarg($scratch))
        . runCommand($pgDump . ' --data-only --no-owner --no-privileges --column-inserts --rows-per-insert=200'
            . ' --exclude-table-data=core_schema_migrations ' . escapeshellarg($scratch));
    $pgSql = tidyPgDump($dump);
} finally {
    $admin->exec("DROP DATABASE IF EXISTS \"{$scratch}\" WITH (FORCE)");
}

// ── SQLite ──────────────────────────────────────────────────────────────────
$say("Replaying 001 to {$through} into SQLite...");
// The translator's NOW() is local time and datetime('now') is UTC: take the earlier.
$sqliteStart = min(gmdate('Y-m-d H:i:s', time() - 5), date('Y-m-d H:i:s', time() - 5));
$started = microtime(true);
$sqlite = SchemaFromMigrations::replaySqlite($through);
$say(sprintf('  replayed in %.1f s', microtime(true) - $started));
$sqliteNowColumns = placeholdersSqlite($sqlite, $sqliteStart);
$sqliteSql = dumpSqlite($sqlite);

// ── Write ───────────────────────────────────────────────────────────────────
if (!is_dir($baselineDir)) {
    mkdir($baselineDir, 0o755, true);
}
file_put_contents($baselineDir . '/baseline.pgsql.sql', $pgSql);
file_put_contents($baselineDir . '/baseline.sqlite.sql', $sqliteSql);
file_put_contents($baselineDir . '/' . Baseline::MANIFEST, json_encode([
    'through' => $through,
    'last' => $last,
    'fingerprint' => Baseline::fingerprintOf($migrationDir, $names),
    'files' => ['pgsql' => 'baseline.pgsql.sql', 'sqlite' => 'baseline.sqlite.sql'],
    'postgres_version' => $pgVersion,
    'sqlite_version' => (string) $sqlite->query('SELECT sqlite_version()')->fetchColumn(),
    'now_columns' => ['pgsql' => $pgNowColumns, 'sqlite' => $sqliteNowColumns],
    'migrations' => $names,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$say("Baseline written for 001 to {$through} ({$last}).");
exit(0);

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Run each migration's up() in order, as `migrate run` does, with the tracking
 * table made first. Output (generated-password notices) is swallowed: the
 * password is replaced by a placeholder anyway.
 *
 * @param list<string> $names
 */
function replay(Database $db, string $migrationDir, array $names): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS core_schema_migrations (
        id SERIAL PRIMARY KEY,
        migration_name VARCHAR(255) NOT NULL UNIQUE,
        executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        execution_time_ms INTEGER
    )');

    ob_start();
    try {
        foreach ($names as $name) {
            require_once $migrationDir . '/' . $name . '.php';
            $parts = explode('_', $name);
            array_shift($parts);
            $class = 'Database\\Migrations\\' . implode('', array_map('ucfirst', $parts));
            $class::up($db);
        }
    } finally {
        ob_end_clean();
    }
}

/**
 * Put the placeholders in: every timestamp written during the replay, and the
 * bootstrap administrator's password. Triggers are off meanwhile, so an
 * "updated_at = now()" trigger can't stamp the rows again.
 *
 * @return list<array{0: string, 1: string}> The (table, column) pairs that held "now".
 */
function placeholdersPostgres(PDO $pdo, string $start): array
{
    $pdo->exec("SET session_replication_role = replica");
    $columns = $pdo->query(
        "SELECT c.table_name, c.column_name
         FROM information_schema.columns c
         JOIN information_schema.tables t
           ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
         WHERE c.table_schema = 'public'
           AND c.data_type IN ('timestamp without time zone', 'timestamp with time zone')
           AND c.table_name <> 'core_schema_migrations'
         ORDER BY c.table_name, c.column_name"
    )->fetchAll(PDO::FETCH_NUM);

    $found = [];
    foreach ($columns as [$table, $column]) {
        $t = Baseline::quoteIdent((string) $table);
        $c = Baseline::quoteIdent((string) $column);
        $stmt = $pdo->prepare("UPDATE {$t} SET {$c} = :placeholder WHERE {$c} >= :start::timestamptz - interval '5 seconds'");
        $stmt->execute([':placeholder' => Baseline::NOW_PLACEHOLDER, ':start' => $start]);
        if ($stmt->rowCount() > 0) {
            $found[] = [(string) $table, (string) $column];
        }
    }

    passwordPlaceholder($pdo);
    $pdo->exec("SET session_replication_role = DEFAULT");

    return $found;
}

/** @return list<array{0: string, 1: string}> */
function placeholdersSqlite(PDO $pdo, string $start): array
{
    $verbatimQuery = static fn(string $sql): array => (new ReflectionMethod(PDO::class, 'query'))
        ->invoke($pdo, $sql)->fetchAll(PDO::FETCH_NUM);
    $verbatimExec = static fn(string $sql) => (new ReflectionMethod(PDO::class, 'exec'))->invoke($pdo, $sql);

    $found = [];
    $triggers = $verbatimQuery("SELECT name, sql FROM sqlite_master WHERE type = 'trigger'");
    foreach ($triggers as [$name]) {
        $verbatimExec('DROP TRIGGER ' . Baseline::quoteIdent((string) $name));
    }

    foreach ($verbatimQuery("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'core_schema_migrations' ORDER BY name") as [$table]) {
        foreach ($verbatimQuery('PRAGMA table_info(' . Baseline::quoteIdent((string) $table) . ')') as $info) {
            $column = (string) $info[1];
            $t = Baseline::quoteIdent((string) $table);
            $c = Baseline::quoteIdent($column);
            // A timestamp is text that looks like one, and is no older than the replay.
            $count = (int) $verbatimExec(
                "UPDATE {$t} SET {$c} = " . $pdo->quote(Baseline::NOW_PLACEHOLDER)
                . " WHERE typeof({$c}) = 'text' AND {$c} GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][ T][0-9][0-9]:[0-9][0-9]:[0-9][0-9]*'"
                . " AND {$c} >= " . $pdo->quote($start)
            );
            if ($count > 0) {
                $found[] = [(string) $table, $column];
            }
        }
    }

    passwordPlaceholder($pdo);

    foreach ($triggers as [, $sql]) {
        $verbatimExec((string) $sql);
    }

    return $found;
}

function passwordPlaceholder(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'UPDATE profiles SET password_hash = :placeholder
         WHERE id IN (SELECT profile_id FROM profile_emails WHERE email = :email)'
    );
    $stmt->execute([':placeholder' => Baseline::PASSWORD_PLACEHOLDER, ':email' => 'system@whity.local']);
}

/**
 * Keep what builds the schema and drop what belongs to pg_dump's own session:
 * comments, SET lines, the search_path reset (it would outlive the load on a
 * pooled connection), psql meta-commands, and the extension comment only an
 * owner may write.
 */
function tidyPgDump(string $dump): string
{
    $out = [];
    $blank = false;
    foreach (explode("\n", str_replace("\r\n", "\n", $dump)) as $line) {
        if (
            str_starts_with($line, '--')
            || str_starts_with($line, 'SET ')
            || str_starts_with($line, 'SELECT pg_catalog.set_config(')
            || str_starts_with($line, '\\')
            || str_starts_with($line, 'COMMENT ON EXTENSION')
        ) {
            continue;
        }
        if (trim($line) === '') {
            if (!$blank) {
                $out[] = '';
            }
            $blank = true;
            continue;
        }
        $blank = false;
        $out[] = $line;
    }

    return "-- Whity schema baseline. Generated by scripts/migration-baseline.php; do not edit.\n"
        . trim(implode("\n", $out)) . "\n";
}

/**
 * The SQLite database as SQL: tables and indexes in creation order, then the
 * rows, then the AUTOINCREMENT counters, then triggers (so no trigger fires
 * while the rows go in).
 */
function dumpSqlite(PDO $pdo): string
{
    $query = static fn(string $sql): array => (new ReflectionMethod(PDO::class, 'query'))
        ->invoke($pdo, $sql)->fetchAll(PDO::FETCH_ASSOC);

    $objects = $query("SELECT type, name, sql FROM sqlite_master
        WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY rowid");

    $schema = $rows = $triggers = '';
    foreach ($objects as $object) {
        $statement = $object['sql'] . ";\n";
        if ($object['type'] === 'trigger') {
            $triggers .= $statement;
            continue;
        }
        $schema .= $statement;
        if ($object['type'] !== 'table' || $object['name'] === 'core_schema_migrations') {
            continue;
        }
        $t = Baseline::quoteIdent((string) $object['name']);
        // Up to 200 rows per INSERT, one row per line.
        foreach (array_chunk($query("SELECT * FROM {$t} ORDER BY rowid"), 200) as $chunk) {
            $columns = implode(', ', array_map(static fn($c) => Baseline::quoteIdent((string) $c), array_keys($chunk[0])));
            $lines = [];
            foreach ($chunk as $row) {
                $lines[] = '(' . implode(', ', array_map(static function ($v) use ($pdo): string {
                    if ($v === null) {
                        return 'NULL';
                    }
                    if (is_int($v) || is_float($v)) {
                        return (string) $v;
                    }
                    return $pdo->quote((string) $v);
                }, array_values($row))) . ')';
            }
            $rows .= "INSERT INTO {$t} ({$columns}) VALUES\n" . implode(",\n", $lines) . ";\n";
        }
    }

    $sequences = '';
    if ($query("SELECT 1 FROM sqlite_master WHERE name = 'sqlite_sequence'") !== []) {
        // The rows above moved each counter only as far as the largest id.
        $sequences .= "DELETE FROM sqlite_sequence;\n";
        foreach ($query('SELECT name, seq FROM sqlite_sequence ORDER BY name') as $seq) {
            if ($seq['name'] === 'core_schema_migrations') {
                continue;
            }
            $sequences .= 'INSERT INTO sqlite_sequence (name, seq) VALUES ('
                . $pdo->quote((string) $seq['name']) . ', ' . (int) $seq['seq'] . ");\n";
        }
    }

    return "-- Whity schema baseline (SQLite test engine). Generated by scripts/migration-baseline.php; do not edit.\n"
        . $schema . $rows . $sequences . $triggers;
}

function runCommand(string $command): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("Could not start: {$command}");
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("Failed: {$command}\n{$err}");
    }

    return $out;
}
