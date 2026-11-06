<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\TenantPredicateGuard;

/**
 * #1262: the plugin conformance check covers every plugin, and migrations are
 * left out of tenant scoping by PATH, not by per-statement tags.
 *
 * Both CI scripts are run here the way the workflow runs them, as a separate
 * `php` process, against small plugin trees written to a temp directory. A
 * check that only ever ran over the bundled plugins could not show that it
 * FAILS a bad one.
 */
final class PluginTenantConformanceScriptTest extends TestCase
{
    private const CONFORMANCE = 'scripts/ci-plugin-tenant-conformance.php';
    private const GUARD = 'scripts/ci-tenant-predicate-guard.php';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/whity-1262-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testEveryBundledPluginIsCheckedAndPasses(): void
    {
        [$code, $out] = $this->runScript(self::CONFORMANCE);

        self::assertSame(0, $code, $out);
        $bundled = array_map('basename', glob(dirname(__DIR__, 2) . '/plugins/*', GLOB_ONLYDIR) ?: []);
        self::assertNotEmpty($bundled);
        foreach ($bundled as $plugin) {
            self::assertStringContainsString($plugin, $out, "Bundled plugin {$plugin} must be checked.");
        }
    }

    public function testUnscopedQueryOnThePluginsOwnTenantTableFails(): void
    {
        // The table is never named to the script: it is tenant-owned because the
        // plugin's own migration gives it a tenant_id column.
        $this->writePlugin('Leaky', [
            'Migrations/CreateLeakyRows.php' => $this->migration('CREATE TABLE IF NOT EXISTS leaky_rows (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)'),
            'Api/LeakyApiHandler.php' => $this->handler('SELECT * FROM leaky_rows WHERE id = :id'),
        ]);
        $this->writePlugin('Tidy', [
            'Api/TidyApiHandler.php' => $this->handler('SELECT * FROM memberships WHERE tenant_id = :tenant_id'),
        ]);

        [$code, $out] = $this->runScript(self::CONFORMANCE, $this->root);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[Leaky handler] Leaky/Api/LeakyApiHandler.php', $out);
        self::assertStringContainsString('leaky_rows', $out);
        self::assertStringNotContainsString('[Tidy', $out);
    }

    public function testUnscopedQueryOutsideApiAndJobsFails(): void
    {
        $this->writePlugin('Sneaky', [
            'Services/Report.php' => $this->handler('DELETE FROM memberships WHERE id = :id'),
        ]);

        [$code, $out] = $this->runScript(self::CONFORMANCE, $this->root);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('Sneaky/Services/Report.php', $out);
    }

    public function testTenantTableWithoutTenantIdFailsTheLinter(): void
    {
        $this->writePlugin('Columnless', [
            'Migrations/CreateNotes.php' => $this->migration('CREATE TABLE IF NOT EXISTS columnless_notes (id INTEGER PRIMARY KEY, body TEXT)'),
        ]);

        [$code, $out] = $this->runScript(self::CONFORMANCE, $this->root);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[Columnless migration] columnless_notes', $out);
    }

    public function testManifestPluginMayDeclareAGlobalTable(): void
    {
        $this->writePlugin('Counter', [
            'plugin.json' => json_encode(['tables' => ['counter_seq' => 'global']], JSON_THROW_ON_ERROR),
            'Migrations/CreateCounter.php' => $this->migration('CREATE TABLE IF NOT EXISTS counter_seq (seq BIGINT NOT NULL)'),
        ]);

        [$code, $out] = $this->runScript(self::CONFORMANCE, $this->root);

        self::assertSame(0, $code, $out);
    }

    public function testUnscopedStatementInsideAPluginMigrationIsNotAHandlerViolation(): void
    {
        $this->writePlugin('Backfill', [
            'Migrations/BackfillRows.php' => $this->handler('UPDATE memberships SET is_primary = 1 WHERE is_primary IS NULL'),
        ]);

        [$code, $out] = $this->runScript(self::CONFORMANCE, $this->root);

        self::assertSame(0, $code, $out);
    }

    public function testNothingToCheckIsAFailureNotAPass(): void
    {
        [$code, $out] = $this->runScript(self::CONFORMANCE, $this->root);

        self::assertSame(2, $code, $out);
    }

    public function testGuardStillCatchesViolationsOutsideMigrations(): void
    {
        $this->writeFile('core/src/Api/ThingApiHandler.php', $this->handler('SELECT * FROM memberships WHERE id = :id'));

        [$code, $out] = $this->runScript(self::GUARD, $this->root . '/core');

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('ThingApiHandler.php', $out);
    }

    public function testGuardLeavesMigrationsOutByPath(): void
    {
        $this->writeFile('core/database/migrations/200_backfill.php', $this->handler('UPDATE memberships SET is_primary = 1 WHERE is_primary IS NULL'));

        [$code, $out] = $this->runScript(self::GUARD, $this->root . '/core');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('migration', $out);
    }

    /**
     * Migrations are exempt by path, so an ignore tag inside one does nothing.
     * A tag that does nothing still reads as a reviewed exception; keep the
     * reason as a plain comment instead.
     */
    public function testNoMigrationCarriesAnIgnoreTag(): void
    {
        $repo = dirname(__DIR__, 2);
        $files = array_merge(
            glob($repo . '/database/migrations/*.php') ?: [],
            glob($repo . '/plugins/*/Migrations/*.php') ?: []
        );
        self::assertNotEmpty($files);

        $tagged = [];
        foreach ($files as $file) {
            if (preg_match('#^\s*//\s*' . preg_quote(TenantPredicateGuard::IGNORE_TAG, '#') . '#m', (string) file_get_contents($file)) === 1) {
                $tagged[] = substr($file, strlen($repo) + 1);
            }
        }

        self::assertSame([], $tagged, 'Migrations are exempt from the tenant-predicate guard by path (#1262); '
            . 'write the reason as a plain comment, without the ' . TenantPredicateGuard::IGNORE_TAG . ' tag.');
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runScript(string $script, string ...$args): array
    {
        $repo = dirname(__DIR__, 2);
        $command = [PHP_BINARY, $repo . '/' . $script, ...array_values($args)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }

    /**
     * @param array<string, string> $files path within the plugin => contents
     */
    private function writePlugin(string $name, array $files): void
    {
        foreach ($files as $path => $contents) {
            $this->writeFile($name . '/' . $path, $contents);
        }
    }

    private function writeFile(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function handler(string $sql): string
    {
        return "<?php\n\nfinal class Probe\n{\n    public function run(\$db): void\n    {\n"
            . "        \$db->query('{$sql}', []);\n    }\n}\n";
    }

    private function migration(string $sql): string
    {
        return "<?php\n\nfinal class ProbeMigration\n{\n    public function up(\\PDO \$pdo): void\n    {\n"
            . "        \$pdo->exec('{$sql}');\n    }\n}\n";
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
