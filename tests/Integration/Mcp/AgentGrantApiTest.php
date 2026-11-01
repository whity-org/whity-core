<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Mcp\Auth\AgentGrantView;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpTokenHandler;
use Whity\Mcp\Auth\McpTokenService;

/**
 * An agent credential's grant, read and changed by the person who granted it
 * (WHIT-832): never above them, audit-logged, and applied to the token the
 * client already holds.
 */
final class AgentGrantApiTest extends TestCase
{
    private const JWT_SECRET = 'agent-grant-test-secret-padded-to-32-chars-min';
    private const RANA       = 10;
    private const OMAR       = 11;
    private const TENANT     = 1;

    private const OPERATIONS = [
        ['permission' => 'users:read',   'readOnly' => true,  'agentPolicy' => 'allow'],
        ['permission' => 'users:manage', 'readOnly' => false, 'agentPolicy' => 'allow'],
        ['permission' => 'roles:manage', 'readOnly' => false, 'agentPolicy' => 'allow'],
    ];

    private JwtParser $jwt;
    private \PDO $pdo;
    private McpTokenHandler $handler;
    private TokenValidator $validator;
    /** @var AuditLoggerInterface&object{log: list<array{string, array<string, mixed>}>} */
    private AuditLoggerInterface $audit;

    protected function setUp(): void
    {
        $this->jwt = new JwtParser(self::JWT_SECRET);
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Example Bakery') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT DO NOTHING");
        foreach ([self::RANA => 'Rana', self::OMAR => 'Omar'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, datetime('now'), datetime('now'))")->execute([$id, $name]);
            $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, 1, 1, 'active', datetime('now'))")->execute([$id]);
        }

        // Rana may read and manage users; nobody here may manage roles.
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            static fn (int $profile, string $permission): bool => in_array($permission, ['users:read', 'users:manage'], true),
        );
        $audit = new class implements AuditLoggerInterface {
            /** @var list<array{string, array<string, mixed>}> */
            public array $log = [];
            public function record(string $action, array $options = []): void
            {
                $this->log[] = [$action, $options];
            }
        };
        $this->audit = $audit;

        $this->validator = new TokenValidator($this->jwt, $this->pdo);
        $this->handler   = new McpTokenHandler(
            $this->validator,
            new McpTokenService($this->pdo, $this->jwt),
            new PermissionRegistry(),
            $roles,
            static fn (): array => self::OPERATIONS,
            $audit,
        );
        unset($_COOKIE['access_token']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token']);
    }

    public function testTheGrantReadsPermissionByPermission(): void
    {
        [$jti] = $this->issueAgent();

        $data = $this->read($jti);

        self::assertSame('Billing assistant', $data['name']);
        $rows = array_combine(array_column($data['permissions'], 'permission'), $data['permissions']);
        self::assertSame(['users:manage', 'users:read'], array_keys($rows), 'only what Rana holds');
        self::assertSame('allow', $rows['users:read']['mode']);
        self::assertSame(['read' => 'allow', 'write' => null], $rows['users:read']['effective']);
        self::assertSame(['off', 'ask'], $rows['users:manage']['modes']);
        self::assertSame(AgentGrantView::ALWAYS_ASKS, $rows['users:manage']['withheld']['allow']['reason']);
    }

    public function testAChangeAppliesToTheTokenTheClientAlreadyHolds(): void
    {
        [$jti, $token] = $this->issueAgent();
        self::assertSame('allow', AgentScopeGrant::of($this->principal($token))->modeFor('users:read', true));

        $response = $this->patch($jti, '{"users:read":"off","users:manage":"ask"}');

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $grant = AgentScopeGrant::of($this->principal($token));
        self::assertSame('off', $grant->modeFor('users:read', true));
        self::assertSame('ask', $grant->modeFor('users:manage', false));
    }

    public function testDefaultRemovesTheEntry(): void
    {
        [$jti, $token] = $this->issueAgent();

        $this->patch($jti, '{"users:read":"default"}');

        self::assertSame([], AgentScopeGrant::of($this->principal($token))->entries());
        self::assertNull(array_column($this->read($jti)['permissions'], 'mode', 'permission')['users:read']);
    }

    /** @return array<string, array{string, string}> */
    public static function aboveThePerson(): array
    {
        return [
            'a permission the person lacks'       => ['{"roles:manage":"ask"}', 'roles:manage'],
            'Allowed where every operation asks'  => ['{"users:manage":"allow"}', 'users:manage'],
            'a mode that does not exist'          => ['{"users:read":"always"}', 'users:read'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('aboveThePerson')]
    public function testNothingAboveThePersonIsAccepted(string $body, string $permission): void
    {
        [$jti, $token] = $this->issueAgent();

        $response = $this->patch($jti, $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString($permission, $response->getBody());
        self::assertSame(['users:read' => 'allow'], AgentScopeGrant::of($this->principal($token))->entries(), 'nothing changed');
        self::assertSame([], $this->audit->log);
    }

    public function testEveryChangeIsAuditLogged(): void
    {
        [$jti] = $this->issueAgent();

        $this->patch($jti, '{"users:read":"ask"}');

        self::assertCount(1, $this->audit->log);
        [$action, $options] = $this->audit->log[0];
        self::assertSame('mcp.token.grant_changed', $action);
        self::assertSame($jti, $options['metadata']['jti']);
        self::assertSame(['users:read' => 'allow'], $options['metadata']['before']);
        self::assertSame(['users:read' => 'ask'], $options['metadata']['after']);
    }

    public function testOnlyTheGrantingPersonSeesOrChangesIt(): void
    {
        [$jti] = $this->issueAgent();

        $_COOKIE['access_token'] = $this->session(self::OMAR);
        self::assertSame(404, $this->handler->grant(new Request('GET', '/'), ['jti' => $jti])->getStatusCode());
        self::assertSame(404, $this->handler->updateGrant(new Request('PATCH', '/', [], '{"users:read":"off"}'), ['jti' => $jti])->getStatusCode());
    }

    public function testAPersonsOwnClientHasNoGrant(): void
    {
        $_COOKIE['access_token'] = $this->session(self::RANA);
        $issued = json_decode($this->handler->create(new Request('POST', '/', [], '{"name":"My Claude","scope":["tools:call"]}'))->getBody(), true);

        self::assertSame(404, $this->handler->grant(new Request('GET', '/'), ['jti' => $issued['jti']])->getStatusCode());
    }

    /** @return array{string, string} jti and token */
    private function issueAgent(): array
    {
        $_COOKIE['access_token'] = $this->session(self::RANA);
        $response = $this->handler->create(new Request('POST', '/', [], '{"name":"Billing assistant","agent":true,"scope":["tools:call","grant:users:read=allow"]}'));
        self::assertSame(201, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        return [(string) $body['jti'], (string) $body['token']];
    }

    /** @return array<string, mixed> */
    private function read(string $jti): array
    {
        $response = $this->handler->grant(new Request('GET', '/'), ['jti' => $jti]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        return json_decode($response->getBody(), true)['data'];
    }

    private function patch(string $jti, string $body): Response
    {
        return $this->handler->updateGrant(new Request('PATCH', '/', ['Content-Type' => 'application/json'], $body), ['jti' => $jti]);
    }

    private function principal(string $token): \Whity\Mcp\Auth\McpPrincipal
    {
        $principal = $this->validator->validateMcpToken($token);
        self::assertNotNull($principal);
        return $principal;
    }

    private function session(int $profileId): string
    {
        return $this->jwt->create(['profile_id' => $profileId, 'active_tenant_id' => self::TENANT, 'token_epoch' => 0], 900, 'access');
    }
}
