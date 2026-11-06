<?php

declare(strict_types=1);

/**
 * CI plugin tenant-isolation conformance (WC-194, #1262): run the SDK
 * conformance kit — the migration linter + the tenant-predicate scanner — over
 * EVERY plugin under the plugins directory, the way an out-of-repo plugin
 * (which depends only on whity/plugin-sdk) runs it in its own CI.
 *
 * Until #1262 this checked one hardcoded plugin (HelloWorld) and only its Api/
 * and Jobs/ folders, so a second bundled plugin, or one dropped into plugins/
 * at install time, was never looked at. Now each plugin directory is checked on
 * its own, with nothing about it named here:
 *
 *  1. Which tables are the plugin's own tenant tables is READ FROM ITS
 *     MIGRATIONS: every `CREATE TABLE` that declares a `tenant_id` column. A
 *     manifest plugin's `plugin.json` `tables` are added on top. The host
 *     registry is merged in, so an unscoped query on a CORE tenant table fails
 *     too.
 *  2. The migration linter runs over the plugin's Migrations/ folder: every
 *     table a plugin creates must carry `tenant_id`, or be declared global.
 *  3. The scanner runs over every PHP file in the plugin EXCEPT migration
 *     folders — Api/, Jobs/, the plugin class, anything else. Migrations are
 *     left out by the same path rule as scripts/ci-tenant-predicate-guard.php:
 *     they run once, from the CLI, across every tenant, so "bind a tenant_id
 *     predicate" does not apply to them.
 *
 * Plugin code is only READ, never required or run. An installed plugin is
 * untrusted code, and a lint has no reason to execute it.
 *
 * Standalone, no HTTP/DB; exits 1 on any violation and 2 when there is nothing
 * to check, so an empty or mistyped directory can't pass by checking nothing.
 *
 * Usage:  php scripts/ci-plugin-tenant-conformance.php [plugins-dir ...]
 *         (defaults to the repository's plugins/)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Core\Tenant\CoreTenantTableRegistry;
use Whity\Sdk\Tenant\MigrationTenantColumnLinter;
use Whity\Sdk\Tenant\TenantPredicateScanner;
use Whity\Sdk\Tenant\TenantTableRegistry;

/**
 * Tables a bundled plugin created in a migration that has ALREADY SHIPPED and
 * has since retired. The old `CREATE TABLE` stays in that migration (and in the
 * retirement's rollback) because applied migrations are not rewritten, and the
 * linter reads it. Each entry needs a reason; keep this list short.
 */
const RETIRED_GLOBAL_TABLES = [
    'demo_catalog_change_seq' => 'DemoCatalog\'s retired one-row change counter; held no tenant data and is '
        . 'dropped by RetireDemoCatalogChangeSeqTable. The changes-feed cursor now comes from the host allocator.',
];

$roots = array_slice($argv, 1);
if ($roots === []) {
    $roots = [dirname(__DIR__) . '/plugins'];
}

$isMigrationPath = static function (string $file): bool {
    return (bool) preg_match('#(^|/)([Mm]igrations)/#', str_replace('\\', '/', $file));
};

/**
 * The tables a plugin's migrations create WITH a `tenant_id` column.
 *
 * @return list<string>
 */
$tenantTablesFromMigrations = static function (string $migrationsDir): array {
    $tables = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($migrationsDir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s*\(/i', $source, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[1] as $i => [$name]) {
            // Body = from the opening paren to its matching close.
            $open = $m[0][$i][1] + strlen($m[0][$i][0]) - 1;
            $depth = 0;
            $body = '';
            for ($p = $open, $len = strlen($source); $p < $len; $p++) {
                $depth += $source[$p] === '(' ? 1 : ($source[$p] === ')' ? -1 : 0);
                if ($depth === 0) {
                    $body = substr($source, $open + 1, $p - $open - 1);
                    break;
                }
            }
            if (preg_match('/\btenant_id\b\s+(?:INT|INTEGER|BIGINT|SMALLINT|SERIAL|BIGSERIAL)/i', $body) === 1) {
                $tables[] = strtolower($name);
            }
        }
    }

    return array_values(array_unique($tables));
};

/**
 * `tables` from a manifest plugin's plugin.json, split by scope.
 *
 * @return array{0: array<string, string>, 1: array<string, string>} [tenant, global]
 */
$manifestTables = static function (string $pluginDir): array {
    $path = $pluginDir . '/plugin.json';
    if (!is_file($path)) {
        return [[], []];
    }
    $data = json_decode((string) file_get_contents($path), true);
    $tenant = [];
    $global = [];
    foreach ((is_array($data) && is_array($data['tables'] ?? null)) ? $data['tables'] : [] as $table => $scope) {
        if ($scope === 'global') {
            $global[(string) $table] = 'declared global in plugin.json';
        } elseif ($scope === 'tenant') {
            $tenant[(string) $table] = 'declared tenant in plugin.json';
        }
    }

    return [$tenant, $global];
};

$hostRegistry = CoreTenantTableRegistry::build();
$checked = [];
$failures = [];

foreach ($roots as $root) {
    if (!is_dir($root)) {
        fwrite(STDERR, "FAIL: not a directory: {$root}\n");
        exit(2);
    }

    $pluginDirs = glob(rtrim($root, '/\\') . '/*', GLOB_ONLYDIR) ?: [];
    sort($pluginDirs);

    foreach ($pluginDirs as $pluginDir) {
        $name = basename($pluginDir);
        if (str_starts_with($name, '.')) {
            continue;
        }
        $checked[] = $name;

        [$tenant, $global] = $manifestTables($pluginDir);
        $migrationsDir = $pluginDir . '/Migrations';
        if (is_dir($migrationsDir)) {
            foreach ($tenantTablesFromMigrations($migrationsDir) as $table) {
                $tenant[$table] ??= 'created with a tenant_id column by this plugin\'s migrations';
            }
        }
        $registry = TenantTableRegistry::for($tenant, $global + RETIRED_GLOBAL_TABLES)->merge($hostRegistry);

        if (is_dir($migrationsDir)) {
            foreach ((new MigrationTenantColumnLinter($registry))->lintDirectory($migrationsDir) as $v) {
                $failures[] = sprintf('  [%s migration] %s: %s', $name, $v['table'], $v['reason']);
            }
        }

        foreach ((new TenantPredicateScanner($registry))->scanDirectory($pluginDir) as $v) {
            if ($isMigrationPath((string) $v['file'])) {
                continue;
            }
            $relative = str_replace('\\', '/', substr((string) $v['file'], strlen(rtrim($root, '/\\')) + 1));
            $failures[] = sprintf('  [%s handler] %s:%d [%s]  %s', $name, $relative, $v['line'], implode(', ', $v['tables']), $v['sql']);
        }
    }
}

if ($checked === []) {
    fwrite(STDERR, 'FAIL: no plugin directories found under ' . implode(', ', $roots) . "; nothing was checked.\n");
    exit(2);
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: plugin tenant-isolation conformance (' . implode(', ', $checked) . ").\n\n");
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'OK: ' . count($checked) . ' plugin(s) pass the SDK tenant-isolation conformance kit (migration linter + handler scanner): '
    . implode(', ', $checked) . ".\n";
exit(0);
