<?php

declare(strict_types=1);

namespace Tests\Integration\Taskaat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TasksApiHandler;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Taskaat\TaskaatClient;
use Whity\Core\Taskaat\TaskaatConnection;
use Whity\Core\Taskaat\TaskPeople;
use Whity\Core\Tenant\TenantContext;

/**
 * A10: Tasks and Boards read and write the workspace's Taskaat through its record tools. Real
 * workspace rows (people, memberships, agents, the connection), fake Taskaat.
 */
final class TasksApiRealEngineTest extends TestCase
{
    private const KEY = 'unit-test-encryption-key-please-ignore-0123456789';
    private const BAKERY = 4;
    private const CAFE = 5;
    private const AMRO = 41;
    private const AWSS = 42;
    private const OUTSIDER = 43;

    private PDO $pdo;
    private TasksApiHandler $api;
    private int $agentId = 0;

    /** @var list<array{name: string, arguments: array<mixed>}> */
    private array $calls = [];

    /** @var array<string, list<array<mixed>>> scripted MCP results by tool */
    private array $results = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::BAKERY);

        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (4, 'Example Bakery', 'example-bakery'), (5, 'Example Cafe', 'example-cafe')");
        foreach ([self::AMRO => 'Amro Saleh', self::AWSS => 'Awss Haddad', self::OUTSIDER => 'Emad Nasser'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
        $role = (int) $this->pdo->query('SELECT id FROM roles ORDER BY id LIMIT 1')->fetchColumn();
        foreach ([[self::AMRO, self::BAKERY], [self::AWSS, self::BAKERY], [self::OUTSIDER, self::CAFE]] as [$profile, $tenant]) {
            $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, ?, ?, 'active', CURRENT_TIMESTAMP)")
                ->execute([$profile, $tenant, $role]);
        }
        SchemaFromMigrations::syncSequences($this->pdo);
        $agents = new AgentRepository($this->pdo);
        $this->pdo->prepare("INSERT INTO agents (tenant_id, slug, name, status, created_at, updated_at) VALUES (?, 'stock', 'Stock assistant', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
            ->execute([self::BAKERY]);
        $this->agentId = (int) $this->pdo->query("SELECT id FROM agents WHERE slug = 'stock'")->fetchColumn();

        $secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        $settings = new TenantSettingsRepository($this->pdo);
        $now = 2_000_000_000;
        $tokenReplies = [['access_token' => 'at_1', 'refresh_token' => 'rt_1', 'expires_in' => 30 * 86400]];
        $connection = new TaskaatConnection(
            $settings,
            $secrets,
            'https://taskaat.test',
            'https://bakery.example.test/tasks/connect',
            'Whity',
            static fn (string $url, array $headers = []): array => [
                'authorization_endpoint' => 'https://taskaat.test/oauth/authorize',
                'token_endpoint' => 'https://taskaat.test/api/oauth/token',
                'registration_endpoint' => 'https://taskaat.test/api/oauth/register',
            ],
            static function (string $url, array $params) use (&$tokenReplies): ?array {
                return array_shift($tokenReplies);
            },
            static fn (string $url, array $payload): array => ['client_id' => 'c1'],
            static fn (): int => $now,
        );
        parse_str((string) parse_url($connection->start(self::BAKERY, self::AMRO), PHP_URL_QUERY), $query);
        $connection->complete(self::BAKERY, 'code', (string) $query['state'], self::AMRO);

        $client = new TaskaatClient($connection, function (string $url, array $payload, array $headers): array {
            $name = (string) $payload['params']['name'];
            $this->calls[] = ['name' => $name, 'arguments' => (array) $payload['params']['arguments']];
            $queue = $this->results[$name] ?? [];
            $result = array_shift($queue) ?? ['content' => [['type' => 'text', 'text' => 'ok']]];
            $this->results[$name] = $queue;

            return ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result];
        });
        $this->api = new TasksApiHandler($client, new TaskPeople($this->pdo, $agents));
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $_GET = [];
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, ['Content-Type' => 'application/json'], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => self::AMRO];

        return $request;
    }

    /** @param array<string, mixed> $structured */
    private function json(string $tool, array $structured): void
    {
        $this->results[$tool][] = ['content' => [['type' => 'text', 'text' => json_encode($structured)]], 'structuredContent' => $structured];
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array{name: string, arguments: array<mixed>} */
    private function call(string $name): array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['name'] === $name) {
                return $call;
            }
        }
        self::fail("Taskaat's {$name} was not called");
    }

    public function testMyTasksAsksTaskaatForWhatIsWithMe(): void
    {
        $this->json('list_task_records', ['tasks' => [], 'next_cursor' => null]);

        $response = $this->api->list($this->request('GET', '/api/tasks?view=mine'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('whity:4:person:41', $this->call('list_task_records')['arguments']['assignee_ref']);
    }

    public function testAgentsTasksKeepOnlyTasksWithAnAgent(): void
    {
        $this->json('list_task_records', ['tasks' => [
            ['id' => 'a', 'assignee' => ['kind' => 'agent', 'ref' => 'whity:4:agent:1', 'name' => 'Stock assistant']],
            ['id' => 'b', 'assignee' => ['kind' => 'person', 'ref' => 'whity:4:person:42', 'name' => 'Awss Haddad']],
            ['id' => 'c', 'assignee' => null],
        ], 'next_cursor' => null]);

        $data = self::body($this->api->list($this->request('GET', '/api/tasks?view=agents')))['data'];

        self::assertSame(['a'], array_column($data['tasks'], 'id'));
    }

    public function testATaskGivenToAnAgentIsForThePersonWhoGaveIt(): void
    {
        $response = $this->api->create($this->request('POST', '/api/tasks', [
            'board' => 'BAKE', 'title' => 'Count the flour', 'assignee' => ['kind' => 'agent', 'id' => $this->agentId],
        ]));

        self::assertSame(201, $response->getStatusCode());
        $assignee = $this->call('create_task')['arguments']['assignee'];
        self::assertSame('agent', $assignee['kind']);
        self::assertSame('Stock assistant', $assignee['name']);
        self::assertSame(['ref' => 'whity:4:person:41', 'name' => 'Amro Saleh'], $assignee['for']);
        self::assertSame(['ref' => 'whity:4:person:41', 'name' => 'Amro Saleh'], $assignee['by']);
    }

    public function testSomeoneFromAnotherWorkspaceCannotBeGivenATask(): void
    {
        $response = $this->api->create($this->request('POST', '/api/tasks', [
            'board' => 'BAKE', 'title' => 'Count the flour', 'assignee' => ['kind' => 'person', 'id' => self::OUTSIDER],
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->calls);
    }

    public function testMovingToDoneRunsTaskaatsCompletionAndATaskaatRefusalReachesThePerson(): void
    {
        $this->results['complete_task'][] = ['content' => [['type' => 'text', 'text' => 'Error: the check has no passing evidence yet']], 'isError' => true];

        $response = $this->api->update($this->request('PATCH', '/api/tasks/BAKE-3', ['column' => 'done']), ['task' => 'BAKE-3']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('the check has no passing evidence yet', self::body($response)['error']);
    }

    public function testMovingIntoAwaitingReviewByHandIsRefused(): void
    {
        $response = $this->api->update($this->request('PATCH', '/api/tasks/BAKE-3', ['column' => 'review']), ['task' => 'BAKE-3']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->calls);
    }

    public function testAcceptingPassesEveryRuleAsAHumanVerdict(): void
    {
        $record = ['id' => 'u1', 'checks' => [['id' => 'r1', 'text' => 'Counted', 'status' => 'met'], ['id' => 'r2', 'text' => 'Photo', 'status' => 'pending']]];
        $this->json('get_task_record', $record);
        $this->json('get_task_record', $record + ['gate' => 'accepted']);

        $response = $this->api->review($this->request('POST', '/api/tasks/BAKE-3/review', ['decision' => 'accept']), ['task' => 'BAKE-3']);

        self::assertSame(200, $response->getStatusCode());
        $review = $this->call('submit_task_review')['arguments'];
        self::assertSame('human', $review['validator']);
        self::assertSame([['rule_id' => 'r1', 'status' => 'pass'], ['rule_id' => 'r2', 'status' => 'pass']], $review['results']);
        self::assertSame(['task_id' => 'BAKE-3'], $this->call('complete_task')['arguments']);
    }

    public function testSendingBackNeedsAReasonAndFailsWhatIsNotMet(): void
    {
        self::assertSame(422, $this->api->review($this->request('POST', '/api/tasks/BAKE-3/review', ['decision' => 'send_back']), ['task' => 'BAKE-3'])->getStatusCode());

        $record = ['id' => 'u1', 'checks' => [['id' => 'r1', 'text' => 'Counted', 'status' => 'met'], ['id' => 'r2', 'text' => 'Photo', 'status' => 'pending']]];
        $this->json('get_task_record', $record);
        $this->api->review($this->request('POST', '/api/tasks/BAKE-3/review', ['decision' => 'send_back', 'note' => 'No photo of the shelf']), ['task' => 'BAKE-3']);

        self::assertSame([
            ['rule_id' => 'r1', 'status' => 'pass', 'note' => 'No photo of the shelf'],
            ['rule_id' => 'r2', 'status' => 'fail', 'note' => 'No photo of the shelf'],
        ], $this->call('submit_task_review')['arguments']['results']);
        self::assertNotContains('complete_task', array_column($this->calls, 'name'));
    }

    public function testAnOlderTaskaatSaysItNeedsAnUpdate(): void
    {
        $this->results['list_boards'][] = ['content' => [['type' => 'text', 'text' => 'Error: Unknown tool: list_boards']], 'isError' => true];

        $response = $this->api->boards($this->request('GET', '/api/tasks/boards'));

        self::assertSame(501, $response->getStatusCode());
        self::assertStringContainsString('Taskaat needs an update', self::body($response)['error']);
    }
}
