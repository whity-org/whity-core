<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\ActiveTenantMembershipGuard;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Http\RbacMiddleware;
use Whity\Mcp\Auth\McpTokenService;

/**
 * Only a session's access token counts as a sign-in on the API (WHIT-903).
 *
 * Every token type carries the same identity claims, and the gate used to check
 * only the signature, so a password step's temp token skipped two-step sign-in and
 * an MCP token kept working past its scope and its revocation. These run the real
 * parser, validator and database, because the bug lived between those layers.
 */
final class TokenTypeGateRealEngineTest extends TestCase
{
    private const SECRET = 'token-type-gate-test-secret-padded-to-32-chars';
    private const TENANT = 1;
    private const GATED  = '/api/v1/users';

    private PDO $pdo;
    private JwtParser $parser;
    private TokenValidator $validator;
    private int $profileId;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->parser    = new JwtParser(self::SECRET);
        $this->pdo       = SchemaFromMigrations::make();
        $this->validator = new TokenValidator($this->parser, $this->pdo);

        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Gate') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT DO NOTHING");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (10, 'Gate', 'x', false, 0, 0, NOW(), NOW()) ON CONFLICT DO NOTHING"
        );
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (10, 1, 1, 'active', NOW()) ON CONFLICT DO NOTHING"
        );
        $this->profileId = 10;
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function token(string $type, int $epoch = 0): string
    {
        return $this->parser->create([
            'profile_id'       => $this->profileId,
            'active_tenant_id' => self::TENANT,
            'token_epoch'      => $epoch,
        ], 300, $type);
    }

    /** The gate as public/index.php wires it, reporting whether the handler ran. */
    private function send(string $token, string $path = self::GATED): int
    {
        TenantContext::reset();
        $gate = new EnforceTenantIsolation(
            $this->parser,
            null,
            new ActiveTenantMembershipGuard($this->pdo),
            null,
            $this->validator->isAccessTokenLive(...),
        );
        $request = new Request('GET', $path, ['Authorization' => "Bearer {$token}"], '');

        return $gate->handle($request, static fn (): Response => Response::json(['ran' => true], 200))->getStatusCode();
    }

    public function testASessionsAccessTokenPasses(): void
    {
        self::assertSame(200, $this->send($this->token('access')));
    }

    /** @return array<string, array{string}> */
    public static function tokensThatAreNotASignIn(): array
    {
        return [
            'password step, before the second factor' => ['temp'],
            'refresh'                                 => ['refresh'],
            'device'                                  => ['device'],
            'MCP'                                     => ['mcp'],
            'two-step setup, on another route'        => ['two_factor_enrollment'],
        ];
    }

    #[DataProvider('tokensThatAreNotASignIn')]
    public function testOtherTokenTypesAreRefused(string $type): void
    {
        self::assertSame(401, $this->send($this->token($type)));
    }

    public function testARealMcpTokenIsRefusedBeforeAndAfterRevocation(): void
    {
        $service = new McpTokenService($this->pdo, $this->parser);
        $token   = $service->issue($this->profileId, self::TENANT, 'app', ['resources:read']);
        self::assertSame(401, $this->send($token));

        $claims = $this->parser->parse($token);
        self::assertIsArray($claims);
        self::assertTrue($service->revoke((string) $claims['jti'], $this->profileId, self::TENANT));
        self::assertSame(401, $this->send($token));
    }

    /** @return array<string, array{string}> */
    public static function twoStepSetupRoutes(): array
    {
        return [
            'setup'     => ['/api/v1/auth/2fa/setup'],
            'confirm'   => ['/api/v1/auth/2fa/confirm'],
            'status'    => ['/api/v1/auth/2fa/status'],
            'query'     => ['/api/v1/auth/2fa/status?x=1'],
        ];
    }

    #[DataProvider('twoStepSetupRoutes')]
    public function testASetupTokenStillReachesTwoStepSetup(string $path): void
    {
        self::assertSame(200, $this->send($this->token('two_factor_enrollment'), $path));
    }

    public function testASetupTokenCannotTurnTwoStepOff(): void
    {
        self::assertSame(401, $this->send($this->token('two_factor_enrollment'), '/api/v1/auth/2fa/disable'));
    }

    public function testARevokedAccessTokenIsRefusedAtOnce(): void
    {
        $token  = $this->token('access');
        $claims = $this->parser->parse($token);
        self::assertIsArray($claims);
        self::assertSame(200, $this->send($token));

        $this->pdo->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)')
            ->execute([$claims['jti'], date('Y-m-d H:i:s', time() + 300)]);

        self::assertSame(401, $this->send($token));
    }

    public function testASupersededEpochIsRefused(): void
    {
        $this->pdo->exec('UPDATE profiles SET token_epoch = 2 WHERE id = 10');

        self::assertSame(401, $this->send($this->token('access', 1)));
        self::assertSame(200, $this->send($this->token('access', 2)));
    }

    /** The parser refusing an untyped token is what lets the gate read an absent type as a stub. */
    public function testTheParserRefusesATokenWithNoType(): void
    {
        $encode = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head   = $encode('{"alg":"HS256","typ":"JWT"}');
        $body   = $encode((string) json_encode([
            'jti' => 'x', 'iat' => time(), 'exp' => time() + 300,
            'profile_id' => $this->profileId, 'active_tenant_id' => self::TENANT,
        ]));
        $sig = $encode(hash_hmac('sha256', "{$head}.{$body}", self::SECRET, true));

        self::assertNull($this->parser->parse("{$head}.{$body}.{$sig}"));
    }

    /** @return array<string, array{array<string, mixed>, bool, int}> */
    public static function rbacCases(): array
    {
        $who = ['profile_id' => 10, 'active_tenant_id' => 1];

        return [
            'access, stashed'     => [$who + ['type' => 'access'], true, 200],
            'in-process MCP call' => [$who + ['type' => 'mcp'], true, 200],
            'temp, stashed'       => [$who + ['type' => 'temp'], true, 401],
            'refresh, stashed'    => [$who + ['type' => 'refresh'], true, 401],
            'setup, stashed'      => [$who + ['type' => 'two_factor_enrollment'], true, 401],
        ];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('rbacCases')]
    public function testTheRouteGateChecksTheTypeToo(array $claims, bool $stash, int $expected): void
    {
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        TenantContext::setTenantId(self::TENANT);

        $request = new Request('GET', self::GATED, ['Authorization' => 'Bearer unused'], '');
        if ($stash) {
            $request->setAttribute(Request::ATTR_JWT_CLAIMS, $claims);
        }
        $response = (new RbacMiddleware($this->parser, $roles))->handle(
            $request,
            static fn (): Response => Response::json(['ran' => true], 200),
            null,
            'users:read',
        );

        self::assertSame($expected, $response->getStatusCode());
    }

    public function testAnMcpTokenParsedFromARequestFailsTheRouteGate(): void
    {
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        TenantContext::setTenantId(self::TENANT);

        $request  = new Request('GET', self::GATED, ['Authorization' => 'Bearer ' . $this->token('mcp')], '');
        $response = (new RbacMiddleware($this->parser, $roles))->handle(
            $request,
            static fn (): Response => Response::json(['ran' => true], 200),
            null,
            'users:read',
        );

        self::assertSame(401, $response->getStatusCode());
    }

    /** The live wiring, because a gate built without the check would still pass every test above. */
    public function testIndexWiresTheLiveSessionCheck(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

        self::assertStringContainsString('->isAccessTokenLive(...)', $index);
    }
}
