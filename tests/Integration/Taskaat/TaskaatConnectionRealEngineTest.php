<?php

declare(strict_types=1);

namespace Tests\Integration\Taskaat;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Taskaat\TaskaatClient;
use Whity\Core\Taskaat\TaskaatConnection;
use Whity\Core\Taskaat\TaskaatException;
use Whity\Core\Taskaat\TaskaatTools;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Sdk\Mcp\McpToolContext;

/**
 * WHIT-962 (A10): a workspace connects to Taskaat with OAuth (dynamic registration, PKCE), its
 * tokens stay in its own tenant_settings rows, and the Taskaat tools in the workspace MCP call
 * Taskaat with that workspace's token. Real tenant_settings, fake HTTP.
 */
final class TaskaatConnectionRealEngineTest extends TestCase
{
    private const KEY = 'unit-test-encryption-key-please-ignore-0123456789';
    private const ORIGIN = 'https://taskaat.test';
    private const REDIRECT = 'https://bakery.example.test/tasks/connect';
    private const TENANT = 4;
    private const OTHER_TENANT = 5;

    private TenantSettingsRepository $settings;
    private EncryptedSecretStore $secrets;
    private int $now = 2_000_000_000;

    /** @var list<array{string, string, array<mixed>}> */
    private array $calls = [];

    /** @var list<array<mixed>|null> scripted token endpoint replies */
    private array $tokenReplies = [];

    /** @var list<array<mixed>|null> scripted MCP replies */
    private array $mcpReplies = [];

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (4, 'Example Bakery', 'example-bakery'), (5, 'Example Cafe', 'example-cafe')");
        $this->settings = new TenantSettingsRepository($pdo);
        $this->secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
    }

    /** @return array{string, string, array<mixed>} */
    private function lastCall(): array
    {
        $last = end($this->calls);
        self::assertIsArray($last);

        return $last;
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(AuthoredToolRegistry $registry, string $name): array
    {
        $tool = $registry->get($name);
        self::assertNotNull($tool);

        return $tool;
    }

    private function connection(): TaskaatConnection
    {
        return new TaskaatConnection(
            $this->settings,
            $this->secrets,
            self::ORIGIN,
            self::REDIRECT,
            'Whity (bakery.example.test)',
            function (string $url, array $headers = []): ?array {
                $this->calls[] = ['GET', $url, $headers];

                return $url === self::ORIGIN . '/.well-known/oauth-authorization-server' ? [
                    'issuer' => self::ORIGIN,
                    'authorization_endpoint' => self::ORIGIN . '/oauth/authorize',
                    'token_endpoint' => self::ORIGIN . '/api/oauth/token',
                    'registration_endpoint' => self::ORIGIN . '/api/oauth/register',
                ] : null;
            },
            function (string $url, array $params): ?array {
                $this->calls[] = ['POST', $url, $params];

                return array_shift($this->tokenReplies);
            },
            function (string $url, array $payload): array {
                $this->calls[] = ['JSON', $url, $payload];

                return ['client_id' => 'taskaat_client_1'];
            },
            fn (): int => $this->now,
        );
    }

    private function client(TaskaatConnection $connection): TaskaatClient
    {
        return new TaskaatClient($connection, function (string $url, array $payload, array $headers): ?array {
            $this->calls[] = ['MCP', $url, ['payload' => $payload, 'headers' => $headers]];

            return array_shift($this->mcpReplies);
        });
    }

    /** @return array<string, string> */
    private function start(TaskaatConnection $connection, int $tenant = self::TENANT, ?int $actor = 7): array
    {
        $url = $connection->start($tenant, $actor);
        self::assertStringStartsWith(self::ORIGIN . '/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parsed);
        $query = [];
        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $query[(string) $key] = $value;
            }
        }

        return $query;
    }

    private function connect(TaskaatConnection $connection, int $tenant = self::TENANT): void
    {
        $query = $this->start($connection, $tenant);
        $this->tokenReplies[] = ['access_token' => 'at_1', 'refresh_token' => 'rt_1', 'expires_in' => 30 * 86400];
        $connection->complete($tenant, 'the-code', $query['state'], 7);
    }

    public function testConnectingRegistersWithPkceAndKeepsTheTokensEncrypted(): void
    {
        $connection = $this->connection();
        $query = $this->start($connection);

        self::assertSame('taskaat_client_1', $query['client_id']);
        self::assertSame(self::REDIRECT, $query['redirect_uri']);
        self::assertSame('S256', $query['code_challenge_method']);
        $registration = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'JSON'))[0];
        self::assertSame([self::REDIRECT], $registration[2]['redirect_uris']);
        self::assertSame('none', $registration[2]['token_endpoint_auth_method']);

        $this->tokenReplies[] = ['access_token' => 'at_1', 'refresh_token' => 'rt_1', 'expires_in' => 30 * 86400];
        $status = $connection->complete(self::TENANT, 'the-code', $query['state'], 7);

        self::assertTrue($status['connected']);
        self::assertFalse($status['needs_reconnect']);
        $exchange = $this->lastCall();
        self::assertSame(self::ORIGIN . '/api/oauth/token', $exchange[1]);
        self::assertSame('authorization_code', $exchange[2]['grant_type']);
        self::assertSame(
            $query['code_challenge'],
            rtrim(strtr(base64_encode(hash('sha256', (string) $exchange[2]['code_verifier'], true)), '+/', '-_'), '='),
        );

        $stored = $this->settings->allForTenant(self::TENANT);
        self::assertArrayNotHasKey('taskaat.pending', $stored);
        foreach ($stored as $value) {
            self::assertStringNotContainsString('rt_1', $value);
            self::assertStringNotContainsString('at_1', $value);
        }
        self::assertSame('at_1', $connection->accessToken(self::TENANT));
    }

    public function testAStateFromAnotherSignInIsRefused(): void
    {
        $connection = $this->connection();
        $this->start($connection);

        $this->expectException(TaskaatException::class);
        $connection->complete(self::TENANT, 'the-code', 'not-the-state', 7);
    }

    public function testOnlyThePersonWhoStartedCanFinish(): void
    {
        $connection = $this->connection();
        $query = $this->start($connection, self::TENANT, 7);

        try {
            $connection->complete(self::TENANT, 'the-code', $query['state'], 8);
            self::fail('Another person finished the sign-in');
        } catch (TaskaatException $e) {
            self::assertSame(403, $e->status);
        }
    }

    public function testASignInExpiresAfterTenMinutes(): void
    {
        $connection = $this->connection();
        $query = $this->start($connection);
        $this->now += 601;

        $this->expectException(TaskaatException::class);
        $connection->complete(self::TENANT, 'the-code', $query['state'], 7);
    }

    public function testOneWorkspacesConnectionIsNotAnothers(): void
    {
        $connection = $this->connection();
        $this->connect($connection);

        self::assertFalse($connection->status(self::OTHER_TENANT)['connected']);
        self::assertNull($connection->accessToken(self::OTHER_TENANT));
    }

    public function testATokenNearItsEndIsRefreshedAndTheRotatedOneKept(): void
    {
        $connection = $this->connection();
        $this->connect($connection);
        $this->now += 30 * 86400 - 3600;

        $this->tokenReplies[] = ['access_token' => 'at_2', 'refresh_token' => 'rt_2', 'expires_in' => 30 * 86400];
        self::assertSame('at_2', $connection->accessToken(self::TENANT));
        self::assertSame('rt_1', $this->lastCall()[2]['refresh_token']);

        $this->now += 30 * 86400 - 3600;
        $this->tokenReplies[] = ['access_token' => 'at_3', 'refresh_token' => 'rt_3', 'expires_in' => 30 * 86400];
        self::assertSame('at_3', $connection->accessToken(self::TENANT));
        self::assertSame('rt_2', $this->lastCall()[2]['refresh_token']);
    }

    public function testARevokedRefreshTokenAsksAPersonToConnectAgain(): void
    {
        $connection = $this->connection();
        $this->connect($connection);
        $this->now += 31 * 86400;

        $this->tokenReplies[] = ['error' => 'invalid_grant'];
        self::assertNull($connection->accessToken(self::TENANT));
        self::assertTrue($connection->status(self::TENANT)['needs_reconnect']);
    }

    public function testDisconnectForgetsEverything(): void
    {
        $connection = $this->connection();
        $this->connect($connection);
        $connection->disconnect(self::TENANT);

        self::assertFalse($connection->status(self::TENANT)['connected']);
        self::assertSame([], $this->settings->allForTenant(self::TENANT));
    }

    public function testTheClientCallsTaskaatsMcpWithTheWorkspacesToken(): void
    {
        $connection = $this->connection();
        $this->connect($connection);
        $this->mcpReplies[] = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'WHIT-1 — Bake [high, pending]']]]];

        $result = $this->client($connection)->callTool(self::TENANT, 'list_tasks', ['project_id' => 'WHIT']);

        self::assertSame(['text' => 'WHIT-1 — Bake [high, pending]', 'isError' => false], $result);
        $call = $this->lastCall();
        self::assertSame(self::ORIGIN . '/api/mcp', $call[1]);
        self::assertSame('Bearer at_1', $call[2]['headers']['Authorization']);
        self::assertSame('tools/call', $call[2]['payload']['method']);
        self::assertSame(['name' => 'list_tasks', 'arguments' => ['project_id' => 'WHIT']], $call[2]['payload']['params']);
    }

    public function testARefusedTokenIsRefreshedOnceAndTheCallRetried(): void
    {
        $connection = $this->connection();
        $this->connect($connection);
        $this->mcpReplies[] = ['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Invalid token'], 'id' => null];
        $this->mcpReplies[] = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'ok']]]];
        $this->tokenReplies[] = ['access_token' => 'at_2', 'refresh_token' => 'rt_2', 'expires_in' => 30 * 86400];

        $result = $this->client($connection)->callTool(self::TENANT, 'list_projects', []);

        self::assertSame('ok', $result['text']);
        self::assertSame('Bearer at_2', $this->lastCall()[2]['headers']['Authorization']);
    }

    public function testCallingWithoutAConnectionSaysWhatToDo(): void
    {
        try {
            $this->client($this->connection())->callTool(self::TENANT, 'list_projects', []);
            self::fail('Called Taskaat without a connection');
        } catch (TaskaatException $e) {
            self::assertSame(409, $e->status);
            self::assertStringContainsString('not connected to Taskaat', $e->getMessage());
        }
    }

    public function testTheToolsRegisterUnderTheTaskaatToolsetAndReadingNeverStartsATask(): void
    {
        $connection = $this->connection();
        $this->connect($connection);
        $tools = new TaskaatTools($this->client($connection));
        $registry = new AuthoredToolRegistry();

        self::assertSame([], $tools->register($registry));
        self::assertSame([], $registry->drainWarnings());
        foreach (TaskaatTools::forwarded() as $name) {
            self::assertTrue($registry->has('taskaat_' . $name), $name);
        }
        self::assertSame('tasks:read', $this->tool($registry, 'taskaat_get_task')['requiredPermission']);
        self::assertSame('tasks:write', $this->tool($registry, 'taskaat_create_task')['requiredPermission']);
        self::assertSame('propose', $this->tool($registry, 'taskaat_create_task')['agentPolicy']);
        self::assertSame('allow', $this->tool($registry, 'taskaat_list_tasks')['agentPolicy']);

        $this->mcpReplies[] = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'WHIT-1']]]];
        $out = $tools->forward('get_task', ['task_id' => 'WHIT-1'], new McpToolContext(self::TENANT, 7));

        self::assertSame('WHIT-1', $out);
        self::assertSame(['task_id' => 'WHIT-1', 'peek' => true], $this->lastCall()[2]['payload']['params']['arguments']);
    }

    public function testATaskaatErrorReachesTheAgentAsAnError(): void
    {
        $connection = $this->connection();
        $this->connect($connection);
        $this->mcpReplies[] = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'Error: no such task']], 'isError' => true]];

        $out = (new TaskaatTools($this->client($connection)))->forward('get_task', ['task_id' => 'WHIT-404'], new McpToolContext(self::TENANT, 7));

        self::assertSame(['error' => 'Error: no such task'], $out);
    }
}
