<?php

/**
 * Child-process entry for {@see \Tests\Integration\CrossTenantRouteProbeRealBootTest}.
 *
 * Boots public/index.php in its single-request mode (the database settings come
 * from the environment the test passes), then hands the booted kernel and
 * router to {@see \Tests\Support\CrossTenantProbe\RouteProbe}. The report goes
 * to the file named by WHITY_PROBE_REPORT.
 *
 * Everything after the boot runs inside a function: the kernel drops any global
 * variable created after its first request, so a top-level script would lose its
 * own variables between requests.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 3);

// Whether a child process's environment reaches $_ENV depends on
// variables_order, and the app reads $_ENV. Copy what the test passed.
foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'JWT_SECRET', 'ENCRYPTION_KEY',
    'INITIAL_ADMIN_PASSWORD', 'INITIAL_USER_PASSWORD', 'INITIAL_SYSTEM_ADMIN_PASSWORD', 'INITIAL_SUPERUSER_PASSWORD',
    'DEMO_SEED_PASSWORD'] as $key) {
    $value = getenv($key);
    if ($value !== false) {
        $_ENV[$key] = $value;
    }
}

// `run.php seed …` seeds the database through the real seed command, the way
// CI's `php public/index.php seed` does, with the environment above in place.
if (($argv[1] ?? null) === 'seed') {
    $argv = array_merge(['index.php'], array_slice($argv, 1));
    require $root . '/public/index.php';
    exit(0);
}

// The probe sends thousands of requests from one address; the limiter has its
// own tests and would otherwise turn most of them into 429s.
$_ENV['RATE_LIMIT_ENABLED'] = '0';
putenv('RATE_LIMIT_ENABLED=0');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/health';
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET = [];

ob_start();
require $root . '/public/index.php';
ob_end_clean();

/**
 * Defined by public/index.php.
 *
 * @var \Whity\Http\HttpKernel $kernel
 * @var \Whity\Core\Router $router
 * @var \Whity\Database\Database $db
 * @var \Whity\Core\Plugin\Tenant\TenantManifestHost $tenantManifestHost
 * @var \Whity\Core\Document\Fields\DocumentFieldRegistry $documentFieldRegistry
 */
whity_cross_tenant_probe(
    $kernel,
    $router,
    $db,
    static function () use ($tenantManifestHost, $documentFieldRegistry, $db): void {
        // The worker loop's per-request reset (public/index.php), minus the hooks.
        \Whity\Core\Tenant\TenantContext::reset();
        \Whity\Core\Audit\AuditContext::reset();
        \Whity\Auth\RoleChecker::clearCache();
        $tenantManifestHost->resetRequest();
        $documentFieldRegistry->resetRequest();
        $db->resetSessionState();
    },
    $root,
);

function whity_cross_tenant_probe(
    \Whity\Http\HttpKernel $kernel,
    \Whity\Core\Router $router,
    \Whity\Database\Database $db,
    \Closure $reset,
    string $root,
): void {
    $probe = new \Tests\Support\CrossTenantProbe\RouteProbe(
        $kernel,
        $router,
        $db->getPdo(),
        (string) $_ENV['JWT_SECRET'],
        $reset,
        $root,
    );
    if (getenv('WHITY_PROBE_DECOYS') === '1') {
        $probe->plantDecoys();
    }
    $report = $probe->run();

    $target = (string) getenv('WHITY_PROBE_REPORT');
    file_put_contents($target, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}
