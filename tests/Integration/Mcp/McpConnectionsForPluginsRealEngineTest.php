<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AiPrincipalsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpConnectionEvents;
use Whity\Mcp\Auth\McpTokenService;
use Whity\Mcp\Auth\SdkMcpConnections;
use Whity\Sdk\Hooks\Events;

/**
 * AI connections for plugins (WHIT-992): events when a connection is made,
 * changed or disconnected, and a read of the workspace's connections, never
 * with anything that could be used to call as one.
 *
 * Runs on real Postgres in CI (PHPUNIT_PG_DSN), SQLite locally.
 */
final class McpConnectionsForPluginsRealEngineTest extends TestCase
{
    private const JWT_SECRET = 'mcp-connections-plugins-test-secret-32';
    private const BAKERY = 1;
    private const CAFE   = 2;
    private const RANA   = 10; // manages AI connections
    private const OMAR   = 11;
    private const LINA   = 12; // the cafe

    private \PDO $pdo;
    private McpTokenService $tokens;
    private McpConnectionEvents $events;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $heard = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        AuditContext::reset();

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT DO NOTHING");
        foreach ([self::RANA => 'Rana', self::OMAR => 'Omar', self::LINA => 'Lina'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
            $tenant = $id === self::LINA ? self::CAFE : self::BAKERY;
            $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, ?, 1, 'active', CURRENT_TIMESTAMP)")->execute([$id, $tenant]);
        }
        SchemaFromMigrations::syncSequences($this->pdo);

        $hooks = new HookManager();
        foreach ([Events::MCP_CONNECTION_CREATED, Events::MCP_CONNECTION_GRANT_CHANGED, Events::MCP_CONNECTION_REVOKED] as $event) {
            $hooks->listen($event, function (array $payload) use ($event): array {
                $this->heard[] = [$event, $payload];
                throw new \RuntimeException('a listener that fails changes nothing');
            });
        }
        $this->events = new McpConnectionEvents($this->pdo, $hooks);
        $this->tokens = new McpTokenService($this->pdo, new JwtParser(self::JWT_SECRET), $this->events);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    public function testEventsNameTheConnectionByIdAndNeverCarryTheToken(): void
    {
        $token = $this->tokens->issue(self::OMAR, self::BAKERY, 'Claude on my laptop', ['tools:call']);
        $jti   = $this->jti($token);
        $id    = $this->connectionId($jti);

        self::assertSame([Events::MCP_CONNECTION_CREATED, [
            'tenant_id'           => self::BAKERY,
            'connection_id'       => $id,
            'profile_id'          => self::OMAR,
            'kind'                => 'user',
            'agent_assignment_id' => null,
            'actor_profile_id'    => self::OMAR,
        ]], $this->heard[0]);

        $this->tokens->updateScope($jti, self::OMAR, self::BAKERY, ['tools:call', 'documents:read']);
        self::assertSame(Events::MCP_CONNECTION_GRANT_CHANGED, $this->heard[1][0]);

        self::assertTrue($this->tokens->revoke($jti, self::OMAR, self::BAKERY));
        self::assertTrue($this->tokens->revoke($jti, self::OMAR, self::BAKERY), 'revoking again is fine');
        $revoked = array_values(array_filter($this->heard, static fn (array $h): bool => $h[0] === Events::MCP_CONNECTION_REVOKED));
        self::assertCount(1, $revoked, 'announced once');
        self::assertSame('owner', $revoked[0][1]['by']);

        $all = json_encode($this->heard, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($jti, $all);
        self::assertStringNotContainsString($token, $all);
    }

    public function testAnAdminDisconnectAndAnAgentRemovalAreAnnouncedWithWhoDidIt(): void
    {
        $jti = $this->jti($this->tokens->issue(self::OMAR, self::BAKERY, 'Cursor', ['tools:call']));
        TenantContext::setTenantId(self::BAKERY);
        $request       = new Request('DELETE', '/api/admin/mcp/tokens/' . $jti, ['Content-Type' => 'application/json'], '');
        $request->user = (object) ['profile_id' => self::RANA];
        self::assertSame(204, (new AiPrincipalsApiHandler($this->pdo, $this->roles([self::RANA]), $this->events))->revoke($request, ['jti' => $jti])->getStatusCode());
        $last = $this->last();
        self::assertSame([Events::MCP_CONNECTION_REVOKED, 'admin', self::RANA, self::OMAR], [$last[0], $last[1]['by'], $last[1]['actor_profile_id'], $last[1]['profile_id']]);

        $agent       = (new AgentRepository($this->pdo))->create(self::BAKERY, ['slug' => 'clerk', 'name' => 'Clerk'], self::RANA);
        $assignments = new AgentAssignmentRepository($this->pdo, $this->events);
        $assignment  = $assignments->assign($agent, self::OMAR, self::BAKERY, []);
        $this->tokens->issueForAssignment(self::OMAR, self::BAKERY, 'Clerk', ['tools:call'], $assignment);
        $created = $this->last();
        self::assertSame('ai-agent', $created[1]['kind']);
        self::assertSame($assignment, $created[1]['agent_assignment_id']);

        self::assertSame(1, $assignments->revoke($assignment, self::BAKERY, self::OMAR));
        $last = $this->last();
        self::assertSame([Events::MCP_CONNECTION_REVOKED, 'agent_removed'], [$last[0], $last[1]['by']]);
    }

    public function testAManagerReadsTheWorkspacesConnectionsAndAnybodyElseTheirOwn(): void
    {
        $agent      = (new AgentRepository($this->pdo))->create(self::BAKERY, ['slug' => 'clerk', 'name' => 'Clerk'], self::RANA);
        $assignment = (new AgentAssignmentRepository($this->pdo))->assign($agent, self::OMAR, self::BAKERY, []);
        $omars      = $this->tokens->issue(self::OMAR, self::BAKERY, 'Omar laptop', ['tools:call']);
        $this->tokens->issueForAssignment(self::OMAR, self::BAKERY, 'Clerk', ['tools:call', 'agent:documents:read=allow'], $assignment);
        $ranas      = $this->tokens->issue(self::RANA, self::BAKERY, 'Rana phone', ['tools:call']);
        $gone       = $this->tokens->issue(self::RANA, self::BAKERY, 'Old', ['tools:call']);
        $this->tokens->revoke($this->jti($gone), self::RANA, self::BAKERY);
        $this->tokens->issue(self::LINA, self::CAFE, 'Cafe app', ['tools:call']);

        TenantContext::setTenantId(self::BAKERY);
        AuditContext::set(self::RANA, null);
        $all = (new SdkMcpConnections($this->pdo, $this->roles([self::RANA])))->list();
        $names = array_column($all, 'name');
        sort($names);
        self::assertSame(['Clerk', 'Omar laptop', 'Rana phone'], $names, 'no revoked one, no other workspace');

        $clerk = array_values(array_filter($all, static fn (array $c): bool => $c['name'] === 'Clerk'))[0];
        self::assertSame('ai-agent', $clerk['kind']);
        self::assertSame($agent, $clerk['agentId']);
        self::assertSame($assignment, $clerk['agentAssignmentId']);
        self::assertSame(self::OMAR, $clerk['personId']);
        self::assertSame(['tools:call', 'agent:documents:read=allow'], $clerk['scope']);

        $json = json_encode($all, JSON_THROW_ON_ERROR);
        foreach ([$omars, $ranas] as $token) {
            self::assertStringNotContainsString($this->jti($token), $json, 'never the jti');
            self::assertStringNotContainsString($token, $json);
        }
        self::assertArrayNotHasKey('jti', $all[0]);

        TenantContext::reset();
        TenantContext::setTenantId(self::BAKERY);
        AuditContext::set(self::OMAR, null);
        $own = array_column((new SdkMcpConnections($this->pdo, $this->roles([self::RANA])))->list(), 'name');
        sort($own);
        self::assertSame(['Clerk', 'Omar laptop'], $own);

        AuditContext::reset();
        self::assertSame([], (new SdkMcpConnections($this->pdo, $this->roles([self::RANA])))->list(), 'no person, nothing');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function last(): array
    {
        self::assertNotSame([], $this->heard);

        return $this->heard[count($this->heard) - 1];
    }

    /** @param list<int> $managers */
    private function roles(array $managers): RoleChecker
    {
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            static fn (int $profileId, string $permission): bool => $permission === 'mcp:tokens:manage' && in_array($profileId, $managers, true),
        );

        return $roles;
    }

    private function jti(string $token): string
    {
        $claims = (new JwtParser(self::JWT_SECRET))->parse($token);
        self::assertIsArray($claims);

        return (string) $claims['jti'];
    }

    private function connectionId(string $jti): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM mcp_tokens WHERE jti = ?');
        $stmt->execute([$jti]);

        return (int) $stmt->fetchColumn();
    }
}
