<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PluginFrameTokenApiHandler;
use Whity\Auth\ActiveTenantMembershipGuard;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Plugin\PluginFrameTokens;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Http\RbacMiddleware;

/**
 * An embedded plugin screen's frame token reaches its own plugin's routes and
 * nothing else (#1688). Runs the real parser, validator, router and database:
 * the scope is decided between those layers.
 */
final class PluginFrameTokenRealEngineTest extends TestCase
{
    private const SECRET = 'plugin-frame-token-test-secret-padded-32';
    private const TENANT = 1;
    private const BOM = 'Acme\\Bom\\';
    private const OTHER = 'Acme\\Other\\';

    private PDO $pdo;
    private JwtParser $parser;
    private TokenValidator $validator;
    private Router $router;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->parser = new JwtParser(self::SECRET);
        $this->pdo = SchemaFromMigrations::make();
        $this->validator = new TokenValidator($this->parser, $this->pdo);

        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Frame') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT DO NOTHING");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (10, 'Frame', 'x', false, 0, 0, NOW(), NOW()) ON CONFLICT DO NOTHING"
        );
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (10, 1, 1, 'active', NOW()) ON CONFLICT DO NOTHING"
        );

        $ok = static fn (): Response => Response::json(['ran' => true], 200);
        $this->router = new Router();
        $this->router->register('GET', '/api/users', $ok);
        $this->router->register('GET', '/api/bom/designer', $ok, null, self::BOM, 'bom:write');
        $this->router->register('GET', '/api/bom/parts', $ok, null, self::BOM, 'bom:write');
        $this->router->register('POST', '/api/bom/parts', $ok, null, self::BOM, 'bom:write');
        $this->router->register('GET', '/api/other/things', $ok, null, self::OTHER, 'other:read');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @return array<string, mixed> */
    private function sessionClaims(int $epoch = 0): array
    {
        $claims = $this->parser->parse($this->parser->create([
            'profile_id' => 10,
            'active_tenant_id' => self::TENANT,
            'token_epoch' => $epoch,
        ], 900, 'access'));
        self::assertIsArray($claims);

        return $claims;
    }

    /** @param array<string, mixed> $session */
    private function frameToken(array $session, string $namespace = self::BOM): string
    {
        return PluginFrameTokens::issue($this->parser, $session, 'bom-designer', $namespace)['token'];
    }

    /**
     * The gate as public/index.php wires it; the status, or 200 when the handler ran.
     *
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, array $headers): int
    {
        TenantContext::reset();
        $gate = new EnforceTenantIsolation(
            $this->parser,
            null,
            new ActiveTenantMembershipGuard($this->pdo),
            $this->router,
            $this->validator->isAccessTokenLive(...),
        );
        $request = new Request($method, $path, $headers, '');

        return $gate->handle($request, static fn (): Response => Response::json(['ran' => true], 200))->getStatusCode();
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function testTheTokenReachesItsOwnPluginsRoutes(): void
    {
        $token = $this->frameToken($this->sessionClaims());

        self::assertSame(200, $this->send('GET', '/api/v1/bom/parts', $this->bearer($token)));
        self::assertSame(200, $this->send('POST', '/api/v1/bom/parts', $this->bearer($token)));
    }

    public function testACoreRouteRefusesIt(): void
    {
        $token = $this->frameToken($this->sessionClaims());

        self::assertSame(401, $this->send('GET', '/api/v1/users', $this->bearer($token)));
    }

    public function testAnotherPluginsRouteRefusesIt(): void
    {
        $token = $this->frameToken($this->sessionClaims());

        self::assertSame(401, $this->send('GET', '/api/v1/other/things', $this->bearer($token)));
    }

    public function testAPathNoRouteServesRefusesIt(): void
    {
        $token = $this->frameToken($this->sessionClaims());

        self::assertSame(401, $this->send('GET', '/api/v1/bom/nothing-here', $this->bearer($token)));
    }

    public function testItIsNeverTakenFromACookie(): void
    {
        $token = $this->frameToken($this->sessionClaims());

        self::assertSame(401, $this->send('GET', '/api/v1/bom/parts', ['Cookie' => "access_token={$token}"]));
    }

    public function testItFallsWithItsSession(): void
    {
        $session = $this->sessionClaims();
        $token = $this->frameToken($session);
        self::assertSame(200, $this->send('GET', '/api/v1/bom/parts', $this->bearer($token)));

        $this->pdo->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)')
            ->execute([$session['jti'], date('Y-m-d H:i:s', time() + 900)]);

        self::assertSame(401, $this->send('GET', '/api/v1/bom/parts', $this->bearer($token)));
    }

    public function testItFallsWhenTheProfilesTokensAreCut(): void
    {
        $token = $this->frameToken($this->sessionClaims());
        $this->pdo->exec('UPDATE profiles SET token_epoch = 1 WHERE id = 10');

        self::assertSame(401, $this->send('GET', '/api/v1/bom/parts', $this->bearer($token)));
    }

    public function testATamperedNamespaceFailsTheSignature(): void
    {
        $token = $this->frameToken($this->sessionClaims());
        [$head, $body, $sig] = explode('.', $token);
        $claims = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        $claims['plugin_ns'] = self::OTHER;
        $forged = $head . '.' . rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=') . '.' . $sig;

        self::assertSame(401, $this->send('GET', '/api/v1/other/things', $this->bearer($forged)));
    }

    public function testItKeepsTheSessionsIdentityAndConfinement(): void
    {
        $session = $this->sessionClaims() + ['confined_tenant_id' => self::TENANT];
        $claims = $this->parser->parse($this->frameToken($session));

        self::assertIsArray($claims);
        self::assertSame(PluginFrameTokens::TYPE, $claims['type']);
        self::assertSame(10, $claims['profile_id']);
        self::assertSame(self::TENANT, $claims['active_tenant_id']);
        self::assertSame(self::TENANT, $claims['confined_tenant_id']);
        self::assertSame($session['jti'], $claims['session_jti']);
        self::assertNotSame($session['jti'], $claims['jti']);
        self::assertLessThanOrEqual(time() + PluginFrameTokens::TTL, $claims['exp']);
    }

    public function testOnlyASessionsAccessTokenCanAskForOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $frame = $this->parser->parse($this->frameToken($this->sessionClaims()));
        self::assertIsArray($frame);
        PluginFrameTokens::issue($this->parser, $frame, 'bom-designer', self::BOM);
    }

    public function testRbacTakesAStashedFrameTokenAndStillChecksThePermission(): void
    {
        $claims = $this->parser->parse($this->frameToken($this->sessionClaims()));
        self::assertIsArray($claims);
        TenantContext::setTenantId(self::TENANT);

        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnOnConsecutiveCalls(true, false);
        $rbac = new RbacMiddleware($this->parser, $roles);
        $next = static fn (): Response => Response::json(['ran' => true], 200);

        $request = new Request('GET', '/api/v1/bom/parts', ['Authorization' => 'Bearer x'], '');
        $request->setAttribute(\Whity\Sdk\Http\Request::ATTR_JWT_CLAIMS, $claims);
        self::assertSame(200, $rbac->handle($request, $next, null, 'bom:write')->getStatusCode());
        self::assertSame(403, $rbac->handle($request, $next, null, 'bom:write')->getStatusCode());

        // Parsed straight from a request (no upstream gate), the type is refused.
        $raw = new Request('GET', '/api/v1/bom/parts', ['Authorization' => 'Bearer ' . $this->frameToken($this->sessionClaims())], '');
        self::assertSame(401, $rbac->handle($raw, $next, null, 'bom:write')->getStatusCode());
    }

    public function testTheEndpointIssuesOnlyForAnEmbedScreenThePersonMaySee(): void
    {
        $loader = $this->createStub(PluginLoader::class);
        $loader->method('getFrontendFeatures')->willReturn([
            ['id' => 'bom-designer', 'screen' => 'embed', 'embed' => ['path' => '/api/v1/bom/designer'], 'requiredPermission' => 'bom:write'],
            ['id' => 'bom-list', 'screen' => 'crud', 'embed' => null, 'requiredPermission' => 'bom:write'],
            ['id' => 'core-page', 'screen' => 'embed', 'embed' => ['path' => '/api/v1/users'], 'requiredPermission' => 'bom:write'],
            ['id' => 'other-page', 'screen' => 'embed', 'embed' => ['path' => '/api/v1/other/things'], 'requiredPermission' => 'other:read'],
        ]);
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            static fn (int $p, string $permission): bool => $permission === 'bom:write'
        );
        $handler = new PluginFrameTokenApiHandler($loader, $roles, $this->router, $this->parser);
        TenantContext::setTenantId(self::TENANT);

        $ask = function (string $feature, array $claims) use ($handler): Response {
            $request = new Request('POST', "/api/v1/plugin-frames/{$feature}/token", [], '');
            $request->user = (object) $claims;

            return $handler->issue($request, ['feature' => $feature]);
        };

        $session = $this->sessionClaims();
        $issued = $ask('bom-designer', $session);
        self::assertSame(200, $issued->getStatusCode());
        $body = json_decode($issued->getBody(), true);
        $claims = $this->parser->parse($body['data']['token']);
        self::assertSame(self::BOM, $claims['plugin_ns'] ?? null);
        self::assertSame(PluginFrameTokens::TTL, $body['data']['expiresIn']);

        self::assertSame(404, $ask('bom-list', $session)->getStatusCode(), 'not an embed screen');
        self::assertSame(404, $ask('core-page', $session)->getStatusCode(), 'page is not a plugin route');
        self::assertSame(404, $ask('other-page', $session)->getStatusCode(), 'person lacks the permission');
        self::assertSame(404, $ask('nope', $session)->getStatusCode());

        $frame = $this->parser->parse($body['data']['token']);
        self::assertIsArray($frame);
        self::assertSame(401, $ask('bom-designer', $frame)->getStatusCode(), 'a frame token cannot mint another');
    }
}
