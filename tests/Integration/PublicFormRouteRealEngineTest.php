<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PublicFormBotSettingsApiHandler;
use Whity\Api\PublicFormKeysApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Http\HttpClient;
use Whity\Core\Plugin\PluginPublicRoutes;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\Plugin\PublicForm\PublicFormKeyRepository;
use Whity\Core\PluginLoader;
use Whity\Core\RateLimit\ClientIp;
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
use Whity\Sdk\Http\PublicForm;
use Whity\Sdk\Http\Response;

/**
 * A plugin's public form (WHIT-906), through the real gate, CSRF guard, tenant
 * middleware, plugin loader and migration-built schema. The handler records what it
 * saw, so "never reaches the handler" is a fact checked on every refusal.
 */
final class PublicFormRouteRealEngineTest extends TestCase
{
    private const SITE = 'https://www.example-bakery.test';
    private const SECRET = 'turnstile-secret-value';

    private PDO $pdo;
    private string $dir;
    private Router $router;
    private PublicFormGate $gate;
    private PublicFormKeyRepository $keys;
    private DatabaseSharedStore $store;
    private GlobalSettingsRepository $globals;
    private BotCheck $bot;
    private EnforceTenantIsolation $tenantMiddleware;
    private string $plugin;
    private string $other;
    private string $key;

    private PublicFormFakeProvider $provider;

    protected function setUp(): void
    {
        PluginPublicRoutes::reset();
        TenantContext::reset();
        RoleChecker::clearCache();
        $GLOBALS['__public_form_seen'] = [];

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe') ON CONFLICT DO NOTHING");

        $this->keys = new PublicFormKeyRepository($this->pdo);
        $this->store = new DatabaseSharedStore($this->pdo);
        $this->globals = new GlobalSettingsRepository($this->pdo);
        $this->provider = new PublicFormFakeProvider();
        $this->bot = new BotCheck($this->globals, new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'), $this->provider);

        $this->dir = sys_get_temp_dir() . '/whity_forms_' . bin2hex(random_bytes(4));
        $this->plugin = 'Forma' . bin2hex(random_bytes(4));
        $this->other = 'Formb' . bin2hex(random_bytes(4));
        $this->writePlugin($this->plugin);
        $this->writePlugin($this->other);

        $this->router = new Router('/v1');
        (new PluginLoader($this->dir, $this->router, new PermissionRegistry(), new HookManager()))->load();

        $this->gate = new PublicFormGate(
            $this->router,
            $this->keys,
            $this->store,
            $this->bot,
            static fn (): array => ['https://instance-wide.example.test'],
        );
        $this->tenantMiddleware = new EnforceTenantIsolation(new JwtParser(str_repeat('k', 40)), null, null, $this->router);

        $this->useBotCheck();
        $this->key = $this->issue(1, $this->slug($this->plugin), 'enquiry');
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
        unset($GLOBALS['__public_form_seen']);
    }

    // --- The route's shape ---------------------------------------------------------

    /** @return iterable<string, array{string, string}> */
    public static function refusedShapes(): iterable
    {
        yield 'not POST' => ['GET', '/forms/nope/{key}'];
        yield 'no key parameter' => ['POST', '/forms/nope'];
        yield 'a differently named parameter' => ['POST', '/forms/nope/{slug}'];
        yield 'a bad name' => ['POST', '/forms/Bad_Name/{key}'];
        yield 'a deeper path' => ['POST', '/forms/a/b/{key}'];
        yield 'not under forms' => ['POST', '/things/{key}'];
    }

    #[DataProvider('refusedShapes')]
    public function testAnyOtherShapeIsNotRegistered(string $method, string $tail): void
    {
        self::assertNotNull(PluginPublicRoutes::formRefusal($this->plugin, $method, '/api/' . $this->slug($this->plugin) . $tail, null, null));
    }

    public function testAnotherPluginsPathAPermissionOrARoleIsRefused(): void
    {
        $path = '/api/' . $this->slug($this->plugin) . '/forms/enquiry/{key}';
        self::assertNull(PluginPublicRoutes::formRefusal($this->plugin, 'POST', $path, null, null));
        self::assertNotNull(PluginPublicRoutes::formRefusal($this->other, 'POST', $path, null, null));
        self::assertNotNull(PluginPublicRoutes::formRefusal($this->plugin, 'POST', $path, 'x:y', null));
        self::assertNotNull(PluginPublicRoutes::formRefusal($this->plugin, 'POST', $path, null, 'admin'));
    }

    public function testTheLoaderRegistersTheGoodRouteAndFailsTheOthersClosed(): void
    {
        $slug = $this->slug($this->plugin);
        foreach (['gated', 'roled', 'Bad_Name'] as $name) {
            $probe = new Request('POST', '/api/v1/' . $slug . '/forms/' . $name . '/' . str_repeat('0', 64), [], '');
            self::assertNull($this->router->match($probe), "{$name} must not be registered");
        }
        self::assertNull($this->router->match(new Request('POST', '/api/v1/' . $slug . '/forms/typo/' . str_repeat('0', 64), [], '')), "'public' => 'forms' must not be registered");
        self::assertContains(['plugin' => $slug, 'form' => 'enquiry'], PluginPublicRoutes::forms());
        self::assertNotContains(['plugin' => $slug, 'form' => 'gated'], PluginPublicRoutes::forms());
        // A webhook still means a webhook.
        self::assertTrue(PluginPublicRoutes::allows('POST', '/api/v1/' . $slug . '/webhooks/still-here'));
    }

    public function testAFormRouteIsNeverAnMcpTool(): void
    {
        self::assertTrue(PluginPublicRoutes::allows('POST', '/api/v1/' . $this->slug($this->plugin) . '/forms/enquiry/{key}'));
    }

    public function testAGoneAwayPluginTakesItsFormsWithIt(): void
    {
        PluginPublicRoutes::removeOwner($this->plugin);
        self::assertNotContains(['plugin' => $this->slug($this->plugin), 'form' => 'enquiry'], PluginPublicRoutes::forms());
        self::assertContains(['plugin' => $this->slug($this->other), 'form' => 'enquiry'], PluginPublicRoutes::forms());
    }

    // --- A good request ------------------------------------------------------------

    public function testAGoodRequestReachesTheHandlerWithTheKeysTenant(): void
    {
        $response = $this->post();

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame(1, $this->seen()['tenant_context']);
        self::assertSame(1, $this->seen()['public_form']->tenantId);
        self::assertSame($this->slug($this->plugin), $this->seen()['public_form']->plugin);
        self::assertSame('enquiry', $this->seen()['public_form']->form);
        self::assertSame(self::SITE, $this->seen()['public_form']->origin);
    }

    public function testTheProviderIsAskedAtTheFixedAddressWithTheDecryptedSecret(): void
    {
        $this->post(['X-Whity-Client-Ip' => '203.0.113.9']);

        self::assertCount(1, $this->provider->calls);
        [$url, $params] = $this->provider->calls[0];
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $url);
        self::assertSame(self::SECRET, $params['secret']);
        self::assertSame('a-token', $params['response']);
        self::assertSame('203.0.113.9', $params['remoteip']);
    }

    public function testCookiesAndCredentialsAreGoneBeforeAnythingReadsThem(): void
    {
        $_COOKIE = ['access_token' => 'a-real-session'];
        $_SERVER['HTTP_COOKIE'] = 'access_token=a-real-session';

        // With a cookie and no X-Requested-With, CsrfGuard would refuse this if it
        // saw the cookie. It doesn't, because the gate runs first.
        $response = $this->post([
            'Cookie' => 'access_token=a-real-session',
            'Authorization' => 'Bearer a-real-token',
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame([], $_COOKIE);
        self::assertArrayNotHasKey('HTTP_COOKIE', $_SERVER);
        $headers = array_change_key_case($this->seen()['headers']);
        self::assertArrayNotHasKey('cookie', $headers);
        self::assertArrayNotHasKey('authorization', $headers);
        self::assertArrayNotHasKey('x-whity-bot-token', $headers);
    }

    public function testNoCookieIsSetAndCredentialsAreNotAllowedOnTheAnswer(): void
    {
        $response = $this->post();
        $headers = array_change_key_case($response->getHeaders());

        self::assertArrayNotHasKey('set-cookie', $headers);
        self::assertSame(self::SITE, $headers['access-control-allow-origin']);
        self::assertArrayNotHasKey('access-control-allow-credentials', $headers);
    }

    // --- Every refusal, none of which reaches the handler ---------------------------

    public function testAnOversizedBodyIsRefused(): void
    {
        $this->assertRefused(413, $this->post([], str_repeat('x', PublicFormGate::MAX_BODY_BYTES + 1)));
    }

    public function testAnOversizedDeclaredBodyIsRefused(): void
    {
        $this->assertRefused(413, $this->post(['Content-Length' => (string) (PublicFormGate::MAX_BODY_BYTES + 1)]));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedMediaTypes(): iterable
    {
        yield 'a browser form' => ['application/x-www-form-urlencoded'];
        yield 'a file upload' => ['multipart/form-data; boundary=x'];
        yield 'plain text, which needs no preflight' => ['text/plain'];
    }

    #[DataProvider('refusedMediaTypes')]
    public function testOnlyJsonIsAccepted(string $type): void
    {
        $this->assertRefused(415, $this->post(['Content-Type' => $type]));
    }

    public function testAMalformedKeyIsRefused(): void
    {
        $this->assertRefused(404, $this->post([], '{}', 'not-a-key'));
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $this->assertRefused(404, $this->post([], '{}', str_repeat('a', 64)));
    }

    public function testARevokedKeyIsRefusedTheSameWay(): void
    {
        $unknown = $this->post([], '{}', str_repeat('a', 64));
        $id = (int) $this->scalar('SELECT MAX(id) FROM public_form_keys');
        self::assertTrue($this->keys->revoke(1, $id, null));

        $response = $this->post();

        $this->assertRefused(404, $response);
        self::assertSame($unknown->getBody(), $response->getBody());
    }

    public function testAnotherPluginsKeyIsRefusedTheSameWay(): void
    {
        $unknown = $this->post([], '{}', str_repeat('a', 64));
        $theirs = $this->issue(1, $this->slug($this->other), 'enquiry');

        $response = $this->post([], '{}', $theirs);

        $this->assertRefused(404, $response);
        self::assertSame($unknown->getBody(), $response->getBody());
    }

    public function testAKeyForAnotherFormOfThisPluginIsRefused(): void
    {
        $theirs = $this->issue(1, $this->slug($this->plugin), 'throwing');

        $this->assertRefused(404, $this->post([], '{}', $theirs));
    }

    public function testNoOriginIsRefused(): void
    {
        $this->assertRefused(403, $this->post(['Origin' => null]));
    }

    public function testAnOriginNotOnEitherListIsRefused(): void
    {
        $this->assertRefused(403, $this->post(['Origin' => 'https://somebody-else.test']));
    }

    public function testASubdomainOrPrefixOfAnAllowedOriginIsRefused(): void
    {
        $this->assertRefused(403, $this->post(['Origin' => self::SITE . '.evil.test']));
        $this->assertRefused(403, $this->post(['Origin' => 'http://www.example-bakery.test']));
    }

    public function testTheNullOriginIsRefused(): void
    {
        $this->assertRefused(403, $this->post(['Origin' => 'null']));
    }

    public function testAnOriginOnTheInstancesListIsAllowed(): void
    {
        self::assertSame(200, $this->post(['Origin' => 'https://instance-wide.example.test'])->getStatusCode());
    }

    public function testWithNoProviderTheRouteIsRefusedUntilTheOperatorAllowsIt(): void
    {
        $this->globals->delete(BotCheck::SETTING_PROVIDER);
        $this->assertRefused(403, $this->post());
        self::assertSame([], $this->provider->calls);

        $this->globals->set(BotCheck::SETTING_ALLOW_NONE, '1');

        self::assertSame(200, $this->post([], '{}', null, null)->getStatusCode());
        self::assertSame([], $this->provider->calls);
    }

    public function testAProviderWithoutItsSecretIsRefusedEvenIfNoBotCheckIsAllowed(): void
    {
        $this->globals->set(BotCheck::SETTING_ALLOW_NONE, '1');
        $this->bot->clearSecret();

        $this->assertRefused(403, $this->post());
        self::assertSame([], $this->provider->calls);
    }

    public function testAMissingTokenIsRefusedWithoutAskingTheProvider(): void
    {
        $this->assertRefused(403, $this->post([], '{}', null, null));
        self::assertSame([], $this->provider->calls);
    }

    public function testAnOverlongTokenIsRefusedWithoutAskingTheProvider(): void
    {
        $this->assertRefused(403, $this->post([], '{}', null, str_repeat('t', BotCheck::MAX_TOKEN_BYTES + 1)));
        self::assertSame([], $this->provider->calls);
    }

    public function testATokenTheProviderRejectsIsRefused(): void
    {
        $this->provider->answers = [['success' => false]];

        $this->assertRefused(403, $this->post());
    }

    public function testAnythingButAClearYesFromTheProviderIsRefused(): void
    {
        $this->provider->answers = [['success' => 'true']];

        $this->assertRefused(403, $this->post());
    }

    /** @return iterable<string, array{array<string, mixed>|\Throwable}> */
    public static function unreachableProviders(): iterable
    {
        yield 'no answer' => [[]];
        yield 'the transport throws' => [new \RuntimeException('network down')];
        yield 'an answer with no verdict' => [['hello' => 'world']];
    }

    /** @param array<string, mixed>|\Throwable $answer */
    #[DataProvider('unreachableProviders')]
    public function testAProviderThatCannotBeReachedFailsClosed(array|\Throwable $answer): void
    {
        $this->provider->answers = [$answer];

        $response = $this->post();

        $this->assertRefused(403, $response);
        self::assertSame('60', array_change_key_case($response->getHeaders())['retry-after']);
    }

    public function testASecretThatCannotBeDecryptedFailsClosed(): void
    {
        $this->globals->set(BotCheck::SETTING_SECRET, 'v9:not-a-real-ciphertext');

        $this->assertRefused(403, $this->post());
        self::assertSame([], $this->provider->calls);
    }

    // --- Limits --------------------------------------------------------------------

    public function testOneAddressHitsItsLimitBeforeAnythingElseIsLookedAt(): void
    {
        $ip = ['X-Whity-Client-Ip' => '203.0.113.7'];
        for ($i = 0; $i < PublicFormGate::IP_MAX; $i++) {
            self::assertSame(200, $this->post($ip)->getStatusCode());
        }
        $GLOBALS['__public_form_seen'] = [];

        $this->assertRefused(429, $this->post($ip));
        // A made-up key gets the same answer now, so the limiter tells nobody which are real.
        $this->assertRefused(429, $this->post($ip, '{}', str_repeat('a', 64)));
        self::assertArrayHasKey('retry-after', array_change_key_case($this->post($ip)->getHeaders()));

        // Someone else isn't affected.
        self::assertSame(200, $this->post(['X-Whity-Client-Ip' => '203.0.113.8'])->getStatusCode());
    }

    public function testRequestsWithNoKnownAddressShareOneBucket(): void
    {
        for ($i = 0; $i < PublicFormGate::IP_MAX; $i++) {
            $this->post();
        }
        $GLOBALS['__public_form_seen'] = [];

        $this->assertRefused(429, $this->post());
    }

    public function testTheCeilingHoldsAcrossManyAddresses(): void
    {
        $key = 'pluginform:form:1:' . $this->slug($this->plugin) . ':enquiry';
        for ($i = 0; $i < PublicFormGate::FORM_MAX - 1; $i++) {
            $this->store->increment($key, PublicFormGate::WINDOW_SECONDS);
        }

        self::assertSame(200, $this->post(['X-Whity-Client-Ip' => '198.51.100.1'])->getStatusCode());
        $GLOBALS['__public_form_seen'] = [];

        $this->assertRefused(429, $this->post(['X-Whity-Client-Ip' => '198.51.100.2']));
        $this->assertRefused(429, $this->post(['X-Whity-Client-Ip' => '198.51.100.3']));
        self::assertCount(1, $this->provider->calls, 'Over the ceiling, the provider is not asked');
    }

    public function testFailedTokensDoNotUseUpTheCeiling(): void
    {
        $key = 'pluginform:form:1:' . $this->slug($this->plugin) . ':enquiry';
        $this->provider->answers = [['success' => false], ['success' => false]];
        $this->post(['X-Whity-Client-Ip' => '198.51.100.1']);
        $this->post(['X-Whity-Client-Ip' => '198.51.100.2']);

        self::assertSame(0, $this->store->count($key));
    }

    // --- The tenant comes from the key and nothing else ------------------------------

    public function testSpoofingTheTenantChangesNothing(): void
    {
        $second = $this->issue(2, $this->slug($this->plugin), 'enquiry');

        $_GET = ['tenant' => '1', 'tenant_id' => '1'];
        $response = $this->post(
            ['X-Tenant-Id' => '1', 'Host' => 'example-bakery.whity.test', 'X-Forwarded-Host' => 'example-bakery.whity.test'],
            '{"tenant_id":1,"tenant":"example-bakery","data":{"tenant_id":1}}',
            $second,
        );
        $_GET = [];

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame(2, $this->seen()['tenant_context']);
        self::assertSame(2, $this->seen()['public_form']->tenantId);
        self::assertArrayNotHasKey('x-tenant-id', array_change_key_case($this->seen()['headers']));
    }

    public function testASpoofedTenantWithNoKeyGetsNowhere(): void
    {
        $this->assertRefused(404, $this->post(['X-Tenant-Id' => '1', 'Host' => 'example-bakery.whity.test'], '{"tenant_id":1}', str_repeat('a', 64)));
    }

    public function testATenantsCeilingDoesNotTouchAnothers(): void
    {
        $second = $this->issue(2, $this->slug($this->plugin), 'enquiry');
        $key = 'pluginform:form:1:' . $this->slug($this->plugin) . ':enquiry';
        for ($i = 0; $i < PublicFormGate::FORM_MAX; $i++) {
            $this->store->increment($key, PublicFormGate::WINDOW_SECONDS);
        }

        $this->assertRefused(429, $this->post(['X-Whity-Client-Ip' => '198.51.100.1']));
        self::assertSame(200, $this->post(['X-Whity-Client-Ip' => '198.51.100.2'], '{}', $second)->getStatusCode());
        self::assertSame(2, $this->seen()['tenant_context']);
    }

    public function testKeysAreListedAndChangedOnlyByTheirOwnTenant(): void
    {
        $id = (int) $this->scalar('SELECT MAX(id) FROM public_form_keys');

        self::assertCount(1, $this->keys->listForTenant(1));
        self::assertSame([], $this->keys->listForTenant(2));
        self::assertNull($this->keys->findForTenant(2, $id));
        self::assertFalse($this->keys->revoke(2, $id, null));
        self::assertFalse($this->keys->update(2, $id, 'Stolen', []));
        self::assertSame(200, $this->post()->getStatusCode());
    }

    // --- The route can't be reached around the gate ----------------------------------

    public function testWithoutTheGateTheTenantMiddlewareRefusesIt(): void
    {
        $response = $this->tenantMiddleware->handle(
            new Request('POST', $this->formPath($this->key), ['Origin' => self::SITE], '{}'),
            static fn (): Response => Response::json(['reached' => true]),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testWithoutTheGateTheHandlerWrapperRefusesIt(): void
    {
        $request = new Request('POST', $this->formPath($this->key), [], '{}');
        $route = $this->router->match($request);
        self::assertNotNull($route);

        $response = ($route['handler'])($request, $route['params']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $GLOBALS['__public_form_seen']);
    }

    public function testAThrowingHandlerCannotQuarantineThePlugin(): void
    {
        $slug = $this->slug($this->plugin);
        $key = $this->issue(1, $slug, 'throwing');

        for ($i = 0; $i < 6; $i++) {
            $response = $this->post(['X-Whity-Client-Ip' => '192.0.2.' . $i], '{}', $key, 'a-token', 'throwing');
            self::assertSame(500, $response->getStatusCode());
        }

        // Still serving after more throws than the breaker would ever allow.
        self::assertSame(200, $this->post(['X-Whity-Client-Ip' => '192.0.2.200'])->getStatusCode());
    }

    // --- Preflight and the site key --------------------------------------------------

    public function testAPreflightFromAnAllowedOriginMayGoOn(): void
    {
        $response = $this->gate->preflight(new Request('OPTIONS', $this->formPath($this->key), ['Origin' => self::SITE, 'Access-Control-Request-Method' => 'POST'], ''));

        self::assertNotNull($response);
        self::assertSame(204, $response->getStatusCode());
        $headers = array_change_key_case($response->getHeaders());
        self::assertSame(self::SITE, $headers['access-control-allow-origin']);
        self::assertSame('POST', $headers['access-control-allow-methods']);
        self::assertStringContainsString('X-Whity-Bot-Token', $headers['access-control-allow-headers']);
        self::assertArrayNotHasKey('access-control-allow-credentials', $headers);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedPreflights(): iterable
    {
        yield 'a site not on the list' => ['live', 'https://somebody-else.test'];
        yield 'a key that does not exist' => ['unknown', self::SITE];
    }

    #[DataProvider('refusedPreflights')]
    public function testAPreflightFromAnyoneElseLooksTheSameAndAllowsNothing(string $which, string $origin): void
    {
        $key = $which === 'live' ? $this->key : str_repeat('a', 64);
        $response = $this->gate->preflight(new Request('OPTIONS', $this->formPath($key), ['Origin' => $origin], ''));

        self::assertNotNull($response);
        self::assertSame(204, $response->getStatusCode());
        self::assertArrayNotHasKey('access-control-allow-origin', array_change_key_case($response->getHeaders()));
    }

    public function testAPreflightForAnythingElseIsLeftAlone(): void
    {
        self::assertNull($this->gate->preflight(new Request('OPTIONS', '/api/v1/users', ['Origin' => self::SITE], '')));
        self::assertNull($this->gate->preflight(new Request('POST', $this->formPath($this->key), ['Origin' => self::SITE], '')));
    }

    public function testThePageCanReadTheSiteKeyAndNotTheSecret(): void
    {
        $handler = $this->keysHandler();

        $response = $handler->config(new Request('GET', '/x', ['Origin' => self::SITE], ''), ['key' => $this->key]);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        self::assertSame(['provider' => 'turnstile', 'site_key' => 'a-site-key'], $data['bot_check']);
        self::assertTrue($data['accepting']);
        self::assertSame(self::SITE, array_change_key_case($response->getHeaders())['access-control-allow-origin']);
        self::assertStringNotContainsString(self::SECRET, $response->getBody());
    }

    public function testTheSiteKeyAnswersOnlyToALiveKeyAndAnAllowedSite(): void
    {
        $handler = $this->keysHandler();

        self::assertSame(404, $handler->config(new Request('GET', '/x', [], ''), ['key' => str_repeat('a', 64)])->getStatusCode());
        self::assertSame(403, $handler->config(new Request('GET', '/x', ['Origin' => 'https://somebody-else.test'], ''), ['key' => $this->key])->getStatusCode());
        self::assertSame(200, $handler->config(new Request('GET', '/x', [], ''), ['key' => $this->key])->getStatusCode());
    }

    public function testTheSiteKeyReadIsLimited(): void
    {
        $handler = $this->keysHandler();
        $request = new Request('GET', '/x', [ClientIp::HEADER => '203.0.113.50'], '');
        for ($i = 0; $i < PublicFormGate::READ_IP_MAX; $i++) {
            $handler->config($request, ['key' => $this->key]);
        }

        self::assertSame(429, $handler->config($request, ['key' => $this->key])->getStatusCode());
    }

    // --- Issuing and revoking --------------------------------------------------------

    public function testAKeyIsShownOnceAndStoredOnlyAsAHash(): void
    {
        TenantContext::setTenantId(1);
        $response = $this->keysHandler()->issue($this->adminRequest('POST', [
            'plugin' => $this->slug($this->plugin),
            'form' => 'enquiry',
            'label' => 'Example Bakery website',
            'allowed_origins' => ['HTTPS://WWW.Example-Bakery.test'],
        ]));

        self::assertSame(201, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true)['data'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['key']);
        self::assertSame([self::SITE], $data['allowed_origins']);

        $listing = $this->keysHandler()->index($this->adminRequest('GET'))->getBody();
        self::assertStringNotContainsString($data['key'], $listing);
        self::assertStringNotContainsString(hash('sha256', $data['key']), $listing);
        self::assertSame(
            ['id', 'plugin', 'form', 'label', 'key_hint', 'allowed_origins', 'created_at', 'revoked_at'],
            array_keys(json_decode($listing, true)['data'][0]),
        );
        $hash = $this->scalar('SELECT key_hash FROM public_form_keys WHERE id = ' . (int) $data['id']);
        self::assertSame(hash('sha256', $data['key']), $hash);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM public_form_keys WHERE key_hash = '" . $data['key'] . "'"));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedIssues(): iterable
    {
        yield 'no label' => [['label' => ''], 'label'];
        yield 'a wildcard origin' => [['allowed_origins' => ['*']], 'allowed_origins'];
        yield 'an origin with a path' => [['allowed_origins' => ['https://example.test/page']], 'allowed_origins'];
        yield 'plain http on a real host' => [['allowed_origins' => ['http://example.test']], 'allowed_origins'];
        yield 'a form nobody has' => [['form' => 'nothing-here'], 'form'];
        yield 'another form name for the plugin' => [['plugin' => 'nothing-here'], 'form'];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('refusedIssues')]
    public function testABadIssueIsRefused(array $change, string $field): void
    {
        TenantContext::setTenantId(1);
        $response = $this->keysHandler()->issue($this->adminRequest('POST', $change + [
            'plugin' => $this->slug($this->plugin),
            'form' => 'enquiry',
            'label' => 'Website',
            'allowed_origins' => [],
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, json_decode($response->getBody(), true)['details']);
    }

    public function testRevokingThroughTheApiStopsTheKeyAndOnlyInItsOwnTenant(): void
    {
        $id = (int) $this->scalar('SELECT MAX(id) FROM public_form_keys');

        TenantContext::setTenantId(2);
        self::assertSame(404, $this->keysHandler()->revoke($this->adminRequest('POST'), ['id' => (string) $id])->getStatusCode());
        TenantContext::reset();
        self::assertSame(200, $this->post()->getStatusCode());
        TenantContext::reset();

        TenantContext::setTenantId(1);
        self::assertSame(204, $this->keysHandler()->revoke($this->adminRequest('POST'), ['id' => (string) $id])->getStatusCode());
        // @phpstan-ignore staticMethod.alreadyNarrowedType (second revoke() is the idempotency check; PHPStan remembers the first call)
        self::assertSame(204, $this->keysHandler()->revoke($this->adminRequest('POST'), ['id' => (string) $id])->getStatusCode());
        TenantContext::reset();
        $GLOBALS['__public_form_seen'] = [];

        $this->assertRefused(404, $this->post());
    }

    public function testChangingAKeysOriginsTakesEffect(): void
    {
        $id = (int) $this->scalar('SELECT MAX(id) FROM public_form_keys');
        TenantContext::setTenantId(1);
        $response = $this->keysHandler()->update($this->adminRequest('PATCH', ['allowed_origins' => ['https://new.example-bakery.test']]), ['id' => (string) $id]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        TenantContext::reset();

        $this->assertRefused(403, $this->post());
        self::assertSame(200, $this->post(['Origin' => 'https://new.example-bakery.test'])->getStatusCode());
    }

    public function testARevokedKeyCannotBeChanged(): void
    {
        $id = (int) $this->scalar('SELECT MAX(id) FROM public_form_keys');
        $this->keys->revoke(1, $id, null);

        TenantContext::setTenantId(1);
        self::assertSame(409, $this->keysHandler()->update($this->adminRequest('PATCH', ['label' => 'x']), ['id' => (string) $id])->getStatusCode());
    }

    public function testMigration217GrantsByCapabilityNotByRoleName(): void
    {
        $this->pdo->exec("INSERT INTO roles (name) VALUES ('Office manager') ON CONFLICT DO NOTHING");
        $roleId = (int) $this->scalar("SELECT id FROM roles WHERE name = 'Office manager'");
        $this->pdo->exec("INSERT INTO permissions (name, description, created_at) VALUES ('settings:write', 'x', CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING");
        $writeId = (int) $this->scalar("SELECT id FROM permissions WHERE name = 'settings:write'");
        $this->pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES ({$roleId}, {$writeId}, CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING");

        require_once dirname(__DIR__, 2) . '/database/migrations/217_create_public_form_keys.php';
        $pdo = $this->pdo;
        \Database\Migrations\CreatePublicFormKeys::up(\Whity\Database\Database::withFactory(static fn (): PDO => $pdo));

        $granted = $this->scalar(
            "SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = 'public_form_keys:manage' AND rp.role_id = {$roleId}"
        );
        self::assertSame(1, (int) $granted);
    }

    // --- Bot settings ----------------------------------------------------------------

    public function testTheSecretIsWriteOnlyAndEncrypted(): void
    {
        TenantContext::setTenantId(0);
        $handler = $this->botSettingsHandler(true);

        $saved = $handler->update($this->adminRequest('PUT', ['provider' => 'turnstile', 'site_key' => 'site-2', 'secret' => 'new-secret-value']));

        self::assertSame(200, $saved->getStatusCode(), $saved->getBody());
        self::assertStringNotContainsString('new-secret-value', $saved->getBody());
        $shown = $handler->show($this->adminRequest('GET'));
        self::assertStringNotContainsString('new-secret-value', $shown->getBody());
        self::assertStringNotContainsString((string) $this->globals->get(BotCheck::SETTING_SECRET), $shown->getBody());
        self::assertSame(
            ['provider', 'providers', 'site_key', 'has_secret', 'allow_without_bot_check', 'ready'],
            array_keys(json_decode($shown->getBody(), true)['data']),
        );
        $data = json_decode($shown->getBody(), true)['data'];
        self::assertTrue($data['has_secret']);
        self::assertSame('site-2', $data['site_key']);
        self::assertStringStartsWith('v1:', (string) $this->globals->get(BotCheck::SETTING_SECRET));
        self::assertStringNotContainsString('new-secret-value', (string) $this->globals->get(BotCheck::SETTING_SECRET));
    }

    public function testLeavingTheSecretOutKeepsItAndNullClearsIt(): void
    {
        TenantContext::setTenantId(0);
        $handler = $this->botSettingsHandler(true);

        $handler->update($this->adminRequest('PUT', ['site_key' => 'site-3']));
        self::assertTrue($this->bot->hasSecret());

        $handler->update($this->adminRequest('PUT', ['secret' => null]));
        self::assertFalse($this->bot->hasSecret());
    }

    public function testAProviderThisInstanceDoesNotKnowIsRefused(): void
    {
        TenantContext::setTenantId(0);

        $response = $this->botSettingsHandler(true)->update($this->adminRequest('PUT', ['provider' => 'https://evil.test/verify']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('turnstile', $this->bot->provider());
    }

    public function testOnlyTheSystemTenantWithSettingsManageMayTouchIt(): void
    {
        TenantContext::setTenantId(1);
        self::assertSame(403, $this->botSettingsHandler(true)->show($this->adminRequest('GET'))->getStatusCode());
        self::assertSame(403, $this->botSettingsHandler(true)->update($this->adminRequest('PUT', ['provider' => null]))->getStatusCode());
        TenantContext::reset();

        TenantContext::setTenantId(0);
        self::assertSame(403, $this->botSettingsHandler(false)->show($this->adminRequest('GET'))->getStatusCode());
    }

    // --- Helpers ---------------------------------------------------------------------

    private function assertRefused(int $status, Response $response): void
    {
        self::assertSame($status, $response->getStatusCode(), $response->getBody());
        self::assertSame([], $GLOBALS['__public_form_seen'], 'A refusal must never reach the handler');
    }

    /** @return array<string, mixed> */
    private function seen(): array
    {
        return $GLOBALS['__public_form_seen'];
    }

    private function useBotCheck(): void
    {
        $this->globals->set(BotCheck::SETTING_PROVIDER, 'turnstile');
        $this->globals->set(BotCheck::SETTING_SITE_KEY, 'a-site-key');
        $this->bot->setSecret(self::SECRET);
    }

    private function issue(int $tenantId, string $plugin, string $form): string
    {
        return (string) $this->keys->issue($tenantId, $plugin, $form, 'Website', [self::SITE], null)['key'];
    }

    private function slug(string $name): string
    {
        return strtolower($name);
    }

    private function formPath(string $key, ?string $form = 'enquiry'): string
    {
        return '/api/v1/' . $this->slug($this->plugin) . '/forms/' . $form . '/' . $key;
    }

    /**
     * Post through the real gate, CSRF guard and tenant middleware into the router's
     * handler. Each call starts as a new request does, with no tenant.
     *
     * @param array<string, string|null> $headers null removes a default.
     */
    private function post(array $headers = [], string $body = '{}', ?string $key = null, ?string $token = 'a-token', string $form = 'enquiry'): Response
    {
        TenantContext::reset();
        $defaults = ['Origin' => self::SITE, 'Content-Type' => 'application/json'];
        if ($token !== null) {
            $defaults[PublicForm::BOT_TOKEN_HEADER] = $token;
        }
        $merged = array_filter($headers + $defaults, static fn (?string $v): bool => $v !== null);
        $request = new Request('POST', $this->formPath($key ?? $this->key, $form), $merged, $body);

        $csrf = new CsrfGuard();

        return $this->gate->handle($request, fn ($r) => $csrf->handle($r, fn ($r) => $this->tenantMiddleware->handle($r, function ($r): Response {
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

    /** @param array<string, mixed> $body */
    private function adminRequest(string $method, array $body = []): Request
    {
        $request = new Request($method, '/x', ['Content-Type' => 'application/json'], (string) json_encode($body));
        $request->user = (object) ['profile_id' => 1];

        return $request;
    }

    private function keysHandler(): PublicFormKeysApiHandler
    {
        return new PublicFormKeysApiHandler(
            $this->keys,
            $this->bot,
            $this->store,
            null,
            static fn (): array => ['https://instance-wide.example.test'],
        );
    }

    private function botSettingsHandler(bool $holdsPermission): PublicFormBotSettingsApiHandler
    {
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn($holdsPermission);

        return new PublicFormBotSettingsApiHandler($this->globals, $this->bot, $roles);
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
use Whity\Sdk\Http\PublicForm;
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
            $GLOBALS['__public_form_seen'] = [
                'tenant_context' => TenantContext::getTenantId(),
                'public_form' => PublicForm::from($r),
                'headers' => $r->getHeaders(),
            ];
            return Response::json(['ok' => true])->withHeaders(['Set-Cookie' => 'session=x']);
        };
        $boom = function (Request $r): Response { throw new \RuntimeException('bad anonymous input'); };
        return [
            ['method' => 'POST', 'path' => '/api/__SLUG__/forms/enquiry/{key}', 'handler' => $ok, 'public' => 'form'],
            ['method' => 'POST', 'path' => '/api/__SLUG__/forms/throwing/{key}', 'handler' => $boom, 'public' => 'form'],
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/still-here', 'handler' => $ok, 'public' => true],
            // Refused: a permission, a role, a bad name, and a value that isn't 'form'.
            ['method' => 'POST', 'path' => '/api/__SLUG__/forms/gated/{key}', 'handler' => $ok, 'public' => 'form', 'requiredPermission' => '__SLUG__:write'],
            ['method' => 'POST', 'path' => '/api/__SLUG__/forms/roled/{key}', 'handler' => $ok, 'public' => 'form', 'requiredRole' => 'admin'],
            ['method' => 'POST', 'path' => '/api/__SLUG__/forms/Bad_Name/{key}', 'handler' => $ok, 'public' => 'form'],
            ['method' => 'POST', 'path' => '/api/__SLUG__/forms/typo/{key}', 'handler' => $ok, 'public' => 'forms'],
        ];
    }
    public function getPermissions(): array { return ['__SLUG__:write']; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
}
PHP;
        file_put_contents(
            $this->dir . '/' . $name . '/Plugin.php',
            str_replace(['__NAME__', '__SLUG__'], [$name, $slug], $source)
        );
    }
}

/** Stands in for the provider's verify endpoint. An empty array means no usable answer. */
final class PublicFormFakeProvider implements HttpClient
{
    /** @var list<array<string, mixed>|\Throwable> */
    public array $answers = [];
    /** @var list<array{string, array<string, string>}> */
    public array $calls = [];

    public function getJson(string $url): ?array
    {
        throw new \LogicException('The bot check only posts');
    }

    public function postForm(string $url, #[\SensitiveParameter] array $params): ?array
    {
        $this->calls[] = [$url, $params];
        $answer = array_shift($this->answers) ?? ['success' => true];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer === [] ? null : $answer;
    }
}
