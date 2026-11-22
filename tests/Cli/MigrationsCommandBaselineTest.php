<?php

declare(strict_types=1);

namespace Whity\Tests\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\MigrationsCommand;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\Database\Database;
use Whity\Database\Migration\Baseline;

/**
 * `migrate run` and `migrate plan` around the schema baseline, on SQLite with
 * a small fixture: two migrations in the baseline, one after it
 * (WHIT-1240). The real baseline's equivalence with replay is proven in
 * MigrationBaselineEquivalenceTest; this is about the runner's choices.
 */
final class MigrationsCommandBaselineTest extends TestCase
{
    private static string $root;
    private static string $migrations;
    private static string $plugins;

    private PDO $pdo;
    private Database $db;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/whity_baseline_runner_' . uniqid();
        self::$migrations = self::$root . '/migrations';
        self::$plugins = self::$root . '/plugins';
        mkdir(self::$migrations, 0o755, true);
        mkdir(self::$root . '/baseline', 0o755, true);
        mkdir(self::$plugins, 0o755, true);

        $migration = static function (string $name, string $class, string $up): void {
            file_put_contents(self::$migrations . "/{$name}.php", <<<PHP
<?php

declare(strict_types=1);

namespace Database\\Migrations;

use Whity\\Database\\Database;

final class {$class}
{
    public static function up(Database \$db): void
    {
        {$up}
    }

    public static function down(Database \$db): void
    {
    }
}
PHP);
        };

        $migration('001_bl_create_profiles', 'BlCreateProfiles',
            "\$db->exec('CREATE TABLE IF NOT EXISTS profiles (id INTEGER PRIMARY KEY, password_hash TEXT NOT NULL, auth_method TEXT NOT NULL DEFAULT \'local\')');
        \$db->exec('CREATE TABLE IF NOT EXISTS profile_emails (profile_id INTEGER NOT NULL, email TEXT NOT NULL UNIQUE)');");
        $migration('002_bl_create_widgets', 'BlCreateWidgets',
            "\$db->exec('CREATE TABLE IF NOT EXISTS widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL, created_at TEXT)');");
        $migration('003_bl_add_widget_colour', 'BlAddWidgetColour',
            "\$db->exec('ALTER TABLE widgets ADD COLUMN colour TEXT');");

        file_put_contents(self::$root . '/baseline/baseline.sqlite.sql', implode("\n", [
            '-- fixture baseline',
            'CREATE TABLE core_schema_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration_name VARCHAR(255) NOT NULL UNIQUE, executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, execution_time_ms INTEGER);',
            "CREATE TABLE profiles (id INTEGER PRIMARY KEY, password_hash TEXT NOT NULL, auth_method TEXT NOT NULL DEFAULT 'local');",
            'CREATE TABLE profile_emails (profile_id INTEGER NOT NULL, email TEXT NOT NULL UNIQUE);',
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL, created_at TEXT);',
            "INSERT INTO profiles (id, password_hash) VALUES (1, '" . Baseline::PASSWORD_PLACEHOLDER . "');",
            "INSERT INTO profile_emails (profile_id, email) VALUES (1, 'system@whity.local');",
            "INSERT INTO widgets (id, name, created_at) VALUES",
            "(1, 'semi;",
            "colon', '" . Baseline::NOW_PLACEHOLDER . "');",
            '',
        ]));
        self::writeManifest(['001_bl_create_profiles', '002_bl_create_widgets']);
    }

    /** @param list<string> $names */
    private static function writeManifest(array $names, ?string $fingerprint = null): void
    {
        file_put_contents(self::$root . '/baseline/manifest.json', json_encode([
            'through' => (int) end($names),
            'last' => end($names),
            'fingerprint' => $fingerprint ?? Baseline::fingerprintOf(self::$migrations, $names),
            'files' => ['sqlite' => 'baseline.sqlite.sql'],
            'now_columns' => ['sqlite' => [['widgets', 'created_at']]],
            'migrations' => $names,
        ]));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['migrations', 'baseline', 'plugins'] as $dir) {
            foreach (glob(self::$root . "/{$dir}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir(self::$root . "/{$dir}");
        }
        rmdir(self::$root);
    }

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        $pdo = $this->pdo;
        $this->db = Database::withFactory(static fn(): PDO => $pdo, 86400, 86400);
        putenv('INITIAL_SYSTEM_ADMIN_PASSWORD=fixture-password');
        $_ENV['INITIAL_SYSTEM_ADMIN_PASSWORD'] = 'fixture-password';
    }

    protected function tearDown(): void
    {
        putenv('INITIAL_SYSTEM_ADMIN_PASSWORD');
        unset($_ENV['INITIAL_SYSTEM_ADMIN_PASSWORD']);
        self::writeManifest(['001_bl_create_profiles', '002_bl_create_widgets']);
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string}
     */
    private function migrate(array $argv): array
    {
        $command = new MigrationsCommand(
            $this->db,
            new PluginLoader(self::$plugins, new Router(''), null, new HookManager()),
            self::$migrations
        );
        ob_start();
        $exit = $command->execute($argv);
        $output = (string) ob_get_clean();

        return [$exit, (string) preg_replace('/\033\[[0-9;]*m/', '', $output)];
    }

    /** @return list<string> */
    private function recorded(): array
    {
        return array_map('strval', Baseline::column($this->pdo, 'SELECT migration_name FROM core_schema_migrations ORDER BY id'));
    }

    public function testFreshDatabaseLoadsTheBaselineThenRunsTheRest(): void
    {
        [$exit, $output] = $this->migrate(['run']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Fresh database: loading the schema baseline (migrations 001 to 002)', $output);
        $this->assertMatchesRegularExpression('/Running: 003_bl_add_widget_colour\.\.\. ✓ \d+ ms/', $output);
        $this->assertStringNotContainsString('Running: 001', $output);
        $this->assertStringContainsString('Loaded the baseline (2 migrations) and ran 1 more migration(s)', $output);
        $this->assertSame(['001_bl_create_profiles', '002_bl_create_widgets', '003_bl_add_widget_colour'], $this->recorded());

        $statement = $this->pdo->prepare('SELECT name, created_at, colour FROM widgets');
        $statement->execute();
        /** @var array{name: string, created_at: string, colour: ?string} $widget */
        $widget = $statement->fetch();
        $this->assertSame("semi;\ncolon", $widget['name'], 'A statement split inside a string literal would break this row.');
        $this->assertNotSame(Baseline::NOW_PLACEHOLDER, $widget['created_at'], 'Placeholder timestamps get the install time.');
        $this->assertNull($widget['colour']);

        $hash = (string) Baseline::scalar($this->pdo, 'SELECT password_hash FROM profiles WHERE id = 1');
        $this->assertTrue(password_verify('fixture-password', $hash), 'The admin password comes from INITIAL_SYSTEM_ADMIN_PASSWORD.');

        [$exit, $again] = $this->migrate(['run']);
        $this->assertSame(0, $exit, $again);
        $this->assertStringContainsString('All migrations already executed', $again);
    }

    public function testExistingDatabaseNeverTakesTheBaseline(): void
    {
        $this->pdo->exec('CREATE TABLE something_already_here (id INTEGER)');

        [$exit, $output] = $this->migrate(['run']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString('baseline', $output);
        $this->assertStringContainsString('Running: 001_bl_create_profiles', $output);
        $this->assertCount(3, $this->recorded());
    }

    public function testNoBaselineFlagReplaysEverything(): void
    {
        [$exit, $output] = $this->migrate(['run', '--no-baseline']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString('loading the schema baseline', $output);
        $this->assertStringContainsString('Running: 001_bl_create_profiles', $output);
    }

    public function testChangedMigrationMakesTheRunnerReplayInstead(): void
    {
        self::writeManifest(['001_bl_create_profiles', '002_bl_create_widgets'], str_repeat('0', 64));

        [$exit, $output] = $this->migrate(['run']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('not using the baseline because a migration it was made from has changed', $output);
        $this->assertStringContainsString('Running: 001_bl_create_profiles', $output);
    }

    public function testPlanChangesNothing(): void
    {
        [$exit, $output] = $this->migrate(['plan']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Migration plan (nothing is changed)', $output);
        $this->assertStringContainsString('the baseline would create migrations 001 to 002 in one step', $output);
        $this->assertStringContainsString('Would run: 003_bl_add_widget_colour', $output);
        $this->assertStringNotContainsString('Would run: 001', $output);
        $this->assertStringContainsString('1 migration(s) would run after the baseline', $output);
        $this->assertSame([], Baseline::column($this->pdo, "SELECT name FROM sqlite_master WHERE type = 'table'"), 'plan must not create anything');

        [$exit, $dry] = $this->migrate(['run', '--dry-run']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Migration plan (nothing is changed)', $dry);
    }

    public function testFailureSaysWhichMigrationWhyAndWhatNext(): void
    {
        // A table 003 can't alter: the run fails on it, after the baseline.
        $broken = self::$migrations . '/004_bl_broken.php';
        file_put_contents($broken, <<<'PHP'
<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

final class BlBroken
{
    public static function up(Database $db): void
    {
        $db->exec('CREATE TABLE widgets (id INTEGER)');
    }

    public static function down(Database $db): void
    {
    }
}
PHP);

        try {
            [$exit, $output] = $this->migrate(['run']);
        } finally {
            unlink($broken);
        }

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('✗ Migration failed', $output);
        $this->assertStringContainsString('Migration:  004_bl_broken', $output);
        $this->assertMatchesRegularExpression('/After:\s+\d+ ms/', $output);
        $this->assertStringContainsString('already exists', $output);
        $this->assertStringContainsString("core migrations don't run in one transaction", $output);
        $this->assertStringContainsString('Done:       1 migration(s) ran', $output);
        $this->assertStringContainsString('migrate plan', $output);
        $this->assertNotContains('004_bl_broken', $this->recorded());
    }

    public function testStatusOutputKeepsTheLinesTheDeployScriptReads(): void
    {
        $this->migrate(['run']);
        file_put_contents(self::$migrations . '/005_bl_pending.php', "<?php\nnamespace Database\\Migrations;\nfinal class BlPending { public static function up(\$db): void {} public static function down(\$db): void {} }\n");

        try {
            [$exit, $output] = $this->migrate(['status']);
        } finally {
            unlink(self::$migrations . '/005_bl_pending.php');
        }

        // deploy-develop.ps1: `-notmatch 'Executed'`, then `^\s*(\S+)\s+Pending\b` per line.
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Executed', $output);
        preg_match_all('/^\s*(\S+)\s+Pending\b/m', $output, $m);
        $this->assertSame(['005_bl_pending'], $m[1]);
    }
}
