<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Auth\Service\ServiceTokenVerifier;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Http\HttpClient;
use Whity\Core\Plugin\PluginServiceRoutes;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Mcp\Auth\OAuth\IssuerJwtVerifier;
use Whity\Sdk\Http\Response;

/**
 * A plugin SERVICE route (WHIT-1011) opens to exactly one thing: a short-lived
 * token from the trusted issuer, for an allowed client, with this deployment's
 * audience and the route's scope. These pin each of those, and that nothing
 * else (no token, a person's session, a shared-secret token) gets through.
 */
final class PluginServiceRoutesTest extends TestCase
{
    private const ISSUER = 'https://id.example.test';
    private const AUDIENCE = 'https://app.example.test/api/v1';
    private const JWKS = 'https://id.example.test/.well-known/jwks.json';

    private string $dir;
    private Router $router;
    private string $name;
    private string $slug;
    private string $privateKey;
    /** @var array<string, string> */
    private array $publicJwk;

    protected function setUp(): void
    {
        PluginServiceRoutes::reset();
        $this->name = 'Svc' . bin2hex(random_bytes(4));
        $this->slug = strtolower($this->name);
        $this->dir = sys_get_temp_dir() . '/whity_' . $this->slug;
        mkdir($this->dir . '/' . $this->name, 0755, true);
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace __NAME__;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Http\ServiceCall;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string    { return '__NAME__'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        $who = fn (Request $r): Response => Response::json(['client' => ServiceCall::from($r)?->clientId, 'for' => ServiceCall::from($r)?->onBehalfOf]);
        return [
            ['method' => 'GET', 'path' => '/api/__SLUG__/customers/{sub}/subscriptions', 'handler' => $who, 'service' => 'billing:read'],
            // Refused: a service route has no person to hold a permission.
            ['method' => 'GET', 'path' => '/api/__SLUG__/gated', 'handler' => $who, 'service' => 'billing:read', 'requiredPermission' => '__SLUG__:read'],
            // Refused: not a scope.
            ['method' => 'GET', 'path' => '/api/__SLUG__/badscope', 'handler' => $who, 'service' => 'everything'],
            // Refused: public AND service.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/both', 'handler' => $who, 'service' => 'billing:read', 'public' => true],
            // An ordinary route: a session, as always.
            ['method' => 'GET', 'path' => '/api/__SLUG__/mine', 'handler' => $who],
        ];
    }
    public function getPermissions(): array { return ['__SLUG__:read']; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
}
PHP;
        file_put_contents(
            $this->dir . '/' . $this->name . '/Plugin.php',
            str_replace(['__NAME__', '__SLUG__'], [$this->name, $this->slug], $source)
        );
        $this->router = new Router();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->privateKey = $pem;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->publicJwk = [
            'kty' => 'RSA',
            'kid' => 'k1',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ];
    }

    protected function tearDown(): void
    {
        PluginServiceRoutes::reset();
        @unlink($this->dir . '/' . $this->name . '/Plugin.php');
        @rmdir($this->dir . '/' . $this->name);
        @rmdir($this->dir);
    }

    public function testAValidTokenReachesTheHandlerWithTheCall(): void
    {
        $response = $this->call($this->token());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['client' => 'whity-id', 'for' => null], json_decode($response->getBody(), true));
    }

    public function testWhityIdsOwnClientMayActForAPerson(): void
    {
        $response = $this->call($this->token(['sub_on_behalf' => 'person-123']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['client' => 'whity-id', 'for' => 'person-123'], json_decode($response->getBody(), true));
    }

    public function testNoOtherClientMayActForAPerson(): void
    {
        // An allowed client, otherwise valid: claiming to act for somebody is refused, not ignored.
        $acting = $this->call($this->token(['client_id' => 'reports-svc', 'sub_on_behalf' => 'person-123']));
        self::assertSame(401, $acting->getStatusCode());

        // The same client without the claim is served as itself.
        $plain = $this->call($this->token(['client_id' => 'reports-svc']));
        self::assertSame(['client' => 'reports-svc', 'for' => null], json_decode($plain->getBody(), true));

        // An empty or non-string person is refused even from Whity ID.
        self::assertSame(401, $this->call($this->token(['sub_on_behalf' => '']))->getStatusCode());
        self::assertSame(401, $this->call($this->token(['sub_on_behalf' => ['a']]))->getStatusCode());
    }

    public function testNothingElseOpensIt(): void
    {
        $now = time();
        foreach ([
            'no token' => null,
            'wrong scope' => $this->token(['scope' => 'billing:portal']),
            'wrong audience' => $this->token(['aud' => 'https://elsewhere.example.test/api/v1']),
            'client not allowed' => $this->token(['client_id' => 'someone-else']),
            'too long-lived' => $this->token(['iat' => $now, 'exp' => $now + 3600]),
            'another issuer' => $this->token(['iss' => 'https://evil.example.test']),
            'expired' => $this->token(['iat' => $now - 400, 'exp' => $now - 100]),
            'shared secret' => JWT::encode($this->claims(), str_repeat('s', 64), 'HS256', 'k1'),
            "a person's session" => JWT::encode(['sub' => '5', 'tenant_id' => 1, 'exp' => $now + 600], str_repeat('k', 40), 'HS256'),
        ] as $case => $token) {
            self::assertSame(401, $this->call($token)->getStatusCode(), $case);
        }
    }

    public function testNoServiceCallOpensWhenItIsNotConfigured(): void
    {
        $this->load(configured: false);

        self::assertSame(401, $this->handle($this->token())->getStatusCode());
    }

    public function testRefusedDeclarationsAreNotRegistered(): void
    {
        $this->load();

        foreach (['/gated', '/badscope'] as $tail) {
            self::assertNull($this->router->match(new Request('GET', '/api/v1/' . $this->slug . $tail, [], '')), $tail);
        }
        self::assertNull($this->router->match(new Request('POST', '/api/v1/' . $this->slug . '/webhooks/both', [], '')));
    }

    public function testAServiceTokenDoesNotOpenAnOrdinaryRoute(): void
    {
        $this->load();
        $request = new Request('GET', '/api/v1/' . $this->slug . '/mine', ['Authorization' => 'Bearer ' . $this->token()], '');

        self::assertSame(401, $this->middleware()->handle($request, static fn (): Response => Response::json([]))->getStatusCode());
    }

    public function testServiceRoutesAreNeverMcpTools(): void
    {
        $this->load();

        self::assertTrue(PluginServiceRoutes::isService('GET', '/api/v1/' . $this->slug . '/customers/{sub}/subscriptions'));
        self::assertFalse(PluginServiceRoutes::isService('GET', '/api/v1/' . $this->slug . '/mine'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function call(?string $token): Response
    {
        $this->load();

        return $this->handle($token);
    }

    private function handle(?string $token): Response
    {
        $path = '/api/v1/' . $this->slug . '/customers/wid-123/subscriptions';
        $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];
        $router = $this->router;

        return $this->middleware()->handle(
            new Request('GET', $path, $headers, ''),
            static function (Request $request) use ($router): Response {
                $route = $router->match($request);

                return $route === null ? Response::error('no route', 404) : ($route['handler'])($request, $route['params']);
            },
        );
    }

    private function load(bool $configured = true): void
    {
        (new PluginLoader($this->dir, $this->router, new PermissionRegistry(), new HookManager()))->load();

        if (!$configured) {
            PluginServiceRoutes::useVerifier(null);

            return;
        }
        $jwk = $this->publicJwk;
        $http = $this->createStub(HttpClient::class);
        $jwks = new JwksProvider(static fn (string $uri): array => ['keys' => [$jwk]]);
        PluginServiceRoutes::useVerifier(new ServiceTokenVerifier(
            new IssuerJwtVerifier(self::ISSUER, $http, $jwks, self::JWKS),
            self::AUDIENCE,
            ['whity-id', 'reports-svc'],
        ));
    }

    private function middleware(): EnforceTenantIsolation
    {
        return new EnforceTenantIsolation(new JwtParser(str_repeat('k', 40)), null, null, $this->router);
    }

    /** @param array<string, mixed> $override */
    private function token(array $override = []): string
    {
        return JWT::encode(array_merge($this->claims(), $override), $this->privateKey, 'RS256', 'k1');
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        $now = time();

        return [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'whity-id',
            'client_id' => 'whity-id',
            'scope' => 'billing:read billing:portal',
            'iat' => $now,
            'exp' => $now + 300,
        ];
    }
}
