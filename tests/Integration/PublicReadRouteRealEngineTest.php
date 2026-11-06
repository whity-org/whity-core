<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\PluginPublicRoutes;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\Plugin\PublicForm\PublicFormKeyRepository;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\CsrfGuard;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Http\Middleware\PublicFormGate;
use Whity\Sdk\Http\Response;

/**
 * A plugin's public GET (WHIT-704, #522), through the real gate, CSRF guard, tenant
 * middleware, plugin loader and migration-built schema, on SQLite and, with
 * PHPUNIT_PG_DSN set, PostgreSQL. The handler records what it saw, so "never
 * reaches the handler" is checked on every refusal.
 */
final class PublicReadRouteRealEngineTest extends TestCase
{
    private const SITE = 'https://www.example-bakery.test';

    private PDO $pdo;
    private string $dir;
    private Router $router;
    private PublicFormKeyRepository $keys;
    private DatabaseSharedStore $store;
    private BotCheck $bot;
    private EnforceTenantIsolation $tenantMiddleware;
    private string $plugin;
    private string $other;
    private string $key;

    protected function setUp(): void
    {
        PluginPublicRoutes::reset();
        TenantContext::reset();
        RoleChecker::clearCache();
        $GLOBALS['__public_read_seen'] = [];

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe') ON CONFLICT DO NOTHING");

        $this->keys = new PublicFormKeyRepository($this->pdo);
        $this->store = new DatabaseSharedStore($this->pdo);
        // No bot check is set up: a read must not depend on one.
        $this->bot = new BotCheck(new GlobalSettingsRepository($this->pdo), new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'), new \Whity\Core\Http\HttpFetcher());

        $this->dir = sys_get_temp_dir() . '/whity_reads_' . bin2hex(random_bytes(4));
        $this->plugin = 'Reada' . bin2hex(random_bytes(4));
        $this->other = 'Readb' . bin2hex(random_bytes(4));
        $this->writePlugin($this->plugin);
        $this->writePlugin($this->other);

        $this->router = new Router('/v1');
        (new PluginLoader($this->dir, $this->router, new PermissionRegistry(), new HookManager()))->load();

        $this->tenantMiddleware = new EnforceTenantIsolation(new JwtParser(str_repeat('k', 40)), null, null, $this->router);
        $this->key = $this->issue(1, $this->slug($this->plugin), 'hours');
    }

    protected function tearDown(): void
    {
        PluginPublicRoutes::reset();
        TenantContext::reset();
        RoleChecker::clearCache();
        foreach ([$this->plugin, $this->other] as $name) {
            @unlink($this->dir . '/' . $name . '/Plugin.php');
            @rmdir($this->dir . '/' . $name);
        }
        @rmdir($this->dir);
        unset($GLOBALS['__public_read_seen']);
        $_COOKIE = [];
        unset($_SERVER['HTTP_COOKIE']);
    }

    // --- The route's shape ---------------------------------------------------------

    /** @return iterable<string, array{string, string}> */
    public static function refusedShapes(): iterable
    {
        yield 'not GET' => ['POST', '/public/nope/{key}'];
        yield 'no key parameter' => ['GET', '/public/nope'];
        yield 'a differently named parameter' => ['GET', '/public/nope/{slug}'];
        yield 'a bad name' => ['GET', '/public/Bad_Name/{key}'];
        yield 'a deeper path' => ['GET', '/public/a/b/{key}'];
        yield 'not under public' => ['GET', '/things/{key}'];
    }

    #[DataProvider('refusedShapes')]
    public function testAnyOtherShapeIsRefused(string $method, string $tail): void
    {
        self::assertNotNull(PluginPublicRoutes::readRefusal($this->plugin, $method, '/api/' . $this->slug($this->plugin) . $tail, null, null));
    }

    public function testAnotherPluginsPathAPermissionOrARoleIsRefused(): void
    {
        $path = '/api/' . $this->slug($this->plugin) . '/public/hours/{key}';
        self::assertNull(PluginPublicRoutes::readRefusal($this->plugin, 'GET', $path, null, null));
        self::assertNotNull(PluginPublicRoutes::readRefusal($this->other, 'GET', $path, null, null));
        self::assertNotNull(PluginPublicRoutes::readRefusal($this->plugin, 'GET', $path, 'x:read', null));
        self::assertNotNull(PluginPublicRoutes::readRefusal($this->plugin, 'GET', $path, null, 'admin'));
    }

    public function testTheLoaderRegistersTheGoodReadAndFailsTheOthersClosed(): void
    {
        $slug = $this->slug($this->plugin);
        foreach (['gated', 'roled', 'Bad_Name', 'typo'] as $name) {
            self::assertNull($this->router->match($this->get($this->readPath(str_repeat('0', 64), $name))), "{$name} must not be registered");
        }
        self::assertContains(['plugin' => $slug, 'form' => 'hours'], PluginPublicRoutes::forms());
        self::assertTrue(PluginPublicRoutes::allows('GET', '/api/v1/' . $slug . '/public/hours/{key}'));
        self::assertFalse(PluginPublicRoutes::allows('POST', '/api/v1/' . $slug . '/public/hours/{key}'));
        // The form kept its name; the read that wanted it too was registered private.
        self::assertContains(['plugin' => $slug, 'form' => 'enquiry'], PluginPublicRoutes::forms());
        self::assertFalse(PluginPublicRoutes::allows('GET', '/api/v1/' . $slug . '/public/enquiry/{key}'));
    }

    public function testAGoneAwayPluginTakesItsReadsWithIt(): void
    {
        PluginPublicRoutes::removeOwner($this->plugin);
        self::assertNotContains(['plugin' => $this->slug($this->plugin), 'form' => 'hours'], PluginPublicRoutes::forms());
        $this->assertRefused(401, $this->read(true));
    }

    // --- Off by default ------------------------------------------------------------

    public function testWithReadsOffAnAnonymousReadMustSignIn(): void
    {
        $this->assertRefused(401, $this->read(false));
    }

    public function testWithReadsOffEvenASignedInCallerIsRefusedByTheWrapper(): void
    {
        $request = $this->get($this->readPath($this->key));
        $route = $this->router->match($request);
        self::assertNotNull($route);

        $this->assertRefused(404, ($route['handler'])($request, $route['params']));
    }

    public function testTheOperatorSwitchIsOffUnlessSaidOn(): void
    {
        self::assertFalse(PublicFormGate::readsEnabledFromEnv([]));
        self::assertFalse(PublicFormGate::readsEnabledFromEnv(['PLUGIN_PUBLIC_READS' => '']));
        self::assertFalse(PublicFormGate::readsEnabledFromEnv(['PLUGIN_PUBLIC_READS' => 'off']));
        self::assertTrue(PublicFormGate::readsEnabledFromEnv(['PLUGIN_PUBLIC_READS' => 'on']));
        self::assertTrue(PublicFormGate::readsEnabledFromEnv(['PLUGIN_PUBLIC_READS' => 'TRUE']));
    }

    // --- An undeclared route still needs a sign-in -----------------------------------

    public function testAnUndeclaredRouteOfTheSameShapeStillNeedsSignIn(): void
    {
        // Same shape, a key issued for its name, reads switched on: not declared public.
        $key = $this->issue(1, $this->slug($this->plugin), 'private');
        $this->assertRefused(401, $this->read(true, [], $key, 'private'));
    }

    public function testAnOrdinaryPluginGetStillNeedsSignIn(): void
    {
        $this->assertRefused(401, $this->through($this->gate(true), $this->get('/api/v1/' . $this->slug($this->plugin) . '/items')));
    }

    public function testAPostToTheReadsPathIsNotAdmitted(): void
    {
        $request = new Request('POST', $this->readPath($this->key), ['Content-Type' => 'application/json'], '{}');
        self::assertFalse(PluginPublicRoutes::admits($request, $this->router, $this->readPath($this->key)));
    }

    // --- A good request ------------------------------------------------------------

    public function testAGoodReadReachesTheHandlerWithTheKeysTenant(): void
    {
        $response = $this->read(true);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame(1, $this->seen()['tenant_context']);
        self::assertSame(1, $this->seen()['public_read']->tenantId);
        self::assertSame($this->slug($this->plugin), $this->seen()['public_read']->plugin);
        self::assertSame('hours', $this->seen()['public_read']->name);
        self::assertSame('', $this->seen()['public_read']->origin);
        self::assertArrayNotHasKey('access-control-allow-origin', array_change_key_case($response->getHeaders()));
    }

    public function testAnAllowedSiteMayReadTheAnswerWithoutCredentials(): void
    {
        $response = $this->read(true, ['Origin' => self::SITE]);
        $headers = array_change_key_case($response->getHeaders());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::SITE, $headers['access-control-allow-origin']);
        self::assertArrayNotHasKey('access-control-allow-credentials', $headers);
        self::assertSame(self::SITE, $this->seen()['public_read']->origin);
    }

    public function testCookiesAndCredentialsAreGoneAndNoCookieIsSet(): void
    {
        $_COOKIE = ['access_token' => 'a-real-session'];
        $_SERVER['HTTP_COOKIE'] = 'access_token=a-real-session';

        $response = $this->read(true, ['Cookie' => 'access_token=a-real-session', 'Authorization' => 'Bearer a-real-token']);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame([], $_COOKIE);
        $headers = array_change_key_case($this->seen()['headers']);
        self::assertArrayNotHasKey('cookie', $headers);
        self::assertArrayNotHasKey('authorization', $headers);
        self::assertArrayNotHasKey('set-cookie', array_change_key_case($response->getHeaders()));
    }

    // --- Refusals --------------------------------------------------------------------

    public function testAnUnknownOrMalformedKeyIsRefused(): void
    {
        $this->assertRefused(404, $this->read(true, [], str_repeat('a', 64)));
        $this->assertRefused(404, $this->read(true, [], 'not-a-key'));
    }

    public function testARevokedKeyIsRefused(): void
    {
        $id = (int) $this->scalar('SELECT MAX(id) FROM public_form_keys');
        self::assertTrue($this->keys->revoke(1, $id, null));

        $this->assertRefused(404, $this->read(true));
    }

    public function testAKeyForAnotherPluginOrAnotherNameIsRefused(): void
    {
        $this->assertRefused(404, $this->read(true, [], $this->issue(1, $this->slug($this->other), 'hours')));
        $this->assertRefused(404, $this->read(true, [], $this->issue(1, $this->slug($this->plugin), 'enquiry')));
    }

    public function testASiteNotOnEitherListIsRefused(): void
    {
        $this->assertRefused(403, $this->read(true, ['Origin' => 'https://elsewhere.example.test']));
        $this->assertRefused(403, $this->read(true, ['Origin' => 'null']));
    }

    public function testSpoofingTheTenantChangesNothing(): void
    {
        $second = $this->issue(2, $this->slug($this->plugin), 'hours');
        $_GET = ['tenant' => '1', 'tenant_id' => '1'];
        $response = $this->read(true, ['X-Tenant-Id' => '1', 'Host' => 'example-bakery.whity.test'], $second);
        $_GET = [];

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame(2, $this->seen()['tenant_context']);
        self::assertSame(2, $this->seen()['public_read']->tenantId);
    }

    public function testOneAddressHitsItsLimitBeforeTheKeyIsLookedAt(): void
    {
        $bucket = 'pluginread:ip:' . $this->slug($this->plugin) . ':hours:203.0.113.7';
        for ($i = 0; $i < PublicFormGate::READ_ROUTE_IP_MAX; $i++) {
            $this->store->increment($bucket, PublicFormGate::WINDOW_SECONDS);
        }
        $ip = ['X-Whity-Client-Ip' => '203.0.113.7'];

        $this->assertRefused(429, $this->read(true, $ip));
        $this->assertRefused(429, $this->read(true, $ip, str_repeat('a', 64)));
        self::assertArrayHasKey('retry-after', array_change_key_case($this->read(true, $ip)->getHeaders()));
        self::assertSame(200, $this->read(true, ['X-Whity-Client-Ip' => '203.0.113.8'])->getStatusCode());
    }

    public function testTheCeilingHoldsAcrossAddressesAndOnlyForItsTenant(): void
    {
        $second = $this->issue(2, $this->slug($this->plugin), 'hours');
        $bucket = 'pluginread:read:1:' . $this->slug($this->plugin) . ':hours';
        for ($i = 0; $i < PublicFormGate::READ_ROUTE_MAX - 1; $i++) {
            $this->store->increment($bucket, PublicFormGate::WINDOW_SECONDS);
        }

        self::assertSame(200, $this->read(true, ['X-Whity-Client-Ip' => '198.51.100.1'])->getStatusCode());
        $GLOBALS['__public_read_seen'] = [];
        $this->assertRefused(429, $this->read(true, ['X-Whity-Client-Ip' => '198.51.100.2']));
        self::assertSame(200, $this->read(true, ['X-Whity-Client-Ip' => '198.51.100.3'], $second)->getStatusCode());
        self::assertSame(2, $this->seen()['tenant_context']);
    }

    // --- Helpers ---------------------------------------------------------------------

    private function assertRefused(int $status, Response $response): void
    {
        self::assertSame($status, $response->getStatusCode(), $response->getBody());
        self::assertSame([], $GLOBALS['__public_read_seen'], 'A refusal must never reach the handler');
    }

    /** @return array<string, mixed> */
    private function seen(): array
    {
        return $GLOBALS['__public_read_seen'];
    }

    private function issue(int $tenantId, string $plugin, string $name): string
    {
        return (string) $this->keys->issue($tenantId, $plugin, $name, 'Website', [self::SITE], null)['key'];
    }

    private function slug(string $name): string
    {
        return strtolower($name);
    }

    private function readPath(string $key, string $name = 'hours'): string
    {
        return '/api/v1/' . $this->slug($this->plugin) . '/public/' . $name . '/' . $key;
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = []): Request
    {
        return new Request('GET', $path, $headers, '');
    }

    private function gate(bool $readsEnabled): PublicFormGate
    {
        return new PublicFormGate(
            $this->router,
            $this->keys,
            $this->store,
            $this->bot,
            static fn (): array => ['https://instance-wide.example.test'],
            $readsEnabled,
        );
    }

    /** @param array<string, string> $headers */
    private function read(bool $readsEnabled, array $headers = [], ?string $key = null, string $name = 'hours'): Response
    {
        return $this->through($this->gate($readsEnabled), $this->get($this->readPath($key ?? $this->key, $name), $headers));
    }

    /**
     * Through the real gate, CSRF guard and tenant middleware into the router's
     * handler. Each call starts as a new request does, with no tenant.
     */
    private function through(PublicFormGate $gate, Request $request): Response
    {
        TenantContext::reset();
        $csrf = new CsrfGuard();

        return $gate->handle($request, fn ($r) => $csrf->handle($r, fn ($r) => $this->tenantMiddleware->handle($r, function ($r): Response {
            $route = $this->router->match($r);
            self::assertNotNull($route);

            return ($route['handler'])($r, $route['params']);
        })));
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    private function writePlugin(string $name): void
    {
        $slug = $this->slug($name);
        mkdir($this->dir . '/' . $name, 0755, true);
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace __NAME__;

use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\PublicRead;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string    { return '__NAME__'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        $ok = function (Request $r): Response {
            $GLOBALS['__public_read_seen'] = [
                'tenant_context' => TenantContext::getTenantId(),
                'public_read' => PublicRead::from($r),
                'headers' => $r->getHeaders(),
            ];
            return Response::json(['open' => '08:00'])->withHeaders(['Set-Cookie' => 'session=x']);
        };
        return [
            ['method' => 'GET', 'path' => '/api/__SLUG__/public/hours/{key}', 'handler' => $ok, 'public' => 'read'],
            // Not declared public: same shape, still private.
            ['method' => 'GET', 'path' => '/api/__SLUG__/public/private/{key}', 'handler' => $ok],
            ['method' => 'GET', 'path' => '/api/__SLUG__/items', 'handler' => $ok],
            // A form, then a read that wants the form's name: the read stays private.
            ['method' => 'POST', 'path' => '/api/__SLUG__/forms/enquiry/{key}', 'handler' => $ok, 'public' => 'form'],
            ['method' => 'GET', 'path' => '/api/__SLUG__/public/enquiry/{key}', 'handler' => $ok, 'public' => 'read'],
            // Refused: a permission, a role, a bad name, and a value that isn't 'read'.
            ['method' => 'GET', 'path' => '/api/__SLUG__/public/gated/{key}', 'handler' => $ok, 'public' => 'read', 'requiredPermission' => '__SLUG__:read'],
            ['method' => 'GET', 'path' => '/api/__SLUG__/public/roled/{key}', 'handler' => $ok, 'public' => 'read', 'requiredRole' => 'admin'],
            ['method' => 'GET', 'path' => '/api/__SLUG__/public/Bad_Name/{key}', 'handler' => $ok, 'public' => 'read'],
            ['method' => 'GET', 'path' => '/api/__SLUG__/public/typo/{key}', 'handler' => $ok, 'public' => 'reads'],
        ];
    }
    public function getPermissions(): array { return ['__SLUG__:read']; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
}
PHP;
        file_put_contents($this->dir . '/' . $name . '/Plugin.php', str_replace(['__NAME__', '__SLUG__'], [$name, $slug], $source));
    }
}
