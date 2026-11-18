<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AgentProposalsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\McpCallKeys;
use Whity\Mcp\Tools\McpCallKeysPruneJob;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Sdk\Http\Response;

/**
 * Retry protection for MCP tools/call (WHIT-931, #1798), on SQLite and on
 * Postgres: a call sent again with the same idempotency key runs once, and an
 * Ask-first call sent again without one is the proposal already waiting.
 */
final class ToolsCallIdempotencyRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;
    private const OMAR   = 202;

    private PDO $pdo;
    private McpCallKeys $keys;
    private AgentProposalRepository $proposals;
    private ToolsCallHandler $toolsCall;

    /** @var list<array<string, mixed>> what the route was asked to make */
    private array $created = [];

    /** @var list<int> the statuses the route answers with, in order; 201 once empty */
    private array $statuses = [];

    /** Run inside the route while it is handling a call. */
    private ?\Closure $whileRunning = null;

    /** @var array<string, McpPrincipal> */
    private array $principals;

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        foreach ([self::RANA => 'Amro Saleh', self::OMAR => 'Awss Haddad'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
        SchemaFromMigrations::syncSequences($this->pdo);

        $router = new Router('');
        $router->registerUnversioned('POST', '/api/forms', function (Request $request): Response {
            if ($this->whileRunning !== null) {
                $during = $this->whileRunning;
                $this->whileRunning = null;
                $during();
            }
            $status = array_shift($this->statuses) ?? 201;
            if ($status >= 400) {
                return Response::json(['error' => 'Not now'], $status);
            }
            $this->created[] = (array) json_decode($request->getBody(), true);
            return Response::json(['id' => count($this->created)], $status);
        }, null, null, 'forms:manage');
        $deriver = new ToolDeriver([[
            'method' => 'POST', 'path' => '/api/forms', 'requiredPermission' => 'forms:manage',
            'schema' => ['summary' => 'Create a form', 'tags' => ['forms'], 'request' => ['type' => 'object', 'properties' => [
                'name'   => ['type' => 'string'],
                'fields' => ['type' => 'array'],
            ], 'required' => ['name']]],
        ]], [], $router, static fn (string $m): null => null);

        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);

        $this->principals = [
            'rana'       => new McpPrincipal(self::RANA, self::RANA, self::TENANT, 'oauth', ['tools:call'], 'oauth:rana'),
            'omar'       => new McpPrincipal(self::OMAR, self::OMAR, self::TENANT, 'oauth', ['tools:call'], 'oauth:omar'),
            'agent'      => new McpPrincipal(self::RANA, self::RANA, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call'], 'jti-billing-assistant'),
            'agent-omar' => new McpPrincipal(self::OMAR, self::OMAR, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call'], 'jti-billing-assistant'),
        ];
        $tokens = $this->createStub(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturnCallback(fn (string $bearer): ?McpPrincipal => $this->principals[$bearer] ?? null);

        $this->keys      = new McpCallKeys($this->pdo);
        $this->proposals = new AgentProposalRepository($this->pdo);
        $this->toolsCall = new ToolsCallHandler($deriver, $router, $roles, $tokens, proposals: $this->proposals, callKeys: $this->keys);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    // ── With a key ──────────────────────────────────────────────────────────

    public function testARetryWithTheSameKeyGetsTheFirstResultAndRunsNothing(): void
    {
        $first  = $this->call('rana', ['name' => 'Leave request'], 'key-1');
        $second = $this->call('rana', ['name' => 'Leave request'], 'key-1');

        self::assertSame([['name' => 'Leave request']], $this->created, 'the write ran once');
        self::assertSame($first['content'], $second['content']);
        self::assertTrue($second['_meta'][ToolsCallHandler::META_REPLAYED]);
        self::assertArrayNotHasKey('_meta', $first, 'the first answer is not a replay');
        self::assertSame('done', $this->keyRow('key-1')['status'] ?? null);
    }

    public function testThePlainIdempotencyKeyNameWorksToo(): void
    {
        $params = ['name' => 'post_api_forms', 'arguments' => ['name' => 'Leave request'], '_meta' => ['idempotencyKey' => 'plain']];
        ($this->toolsCall)($params, 'rana');
        ($this->toolsCall)($params, 'rana');

        self::assertCount(1, $this->created);
    }

    public function testArgumentsInAnotherOrderAreTheSameCall(): void
    {
        $this->call('rana', ['name' => 'Leave request', 'fields' => [['a' => 1, 'b' => 2]]], 'key-1');
        $again = $this->call('rana', ['fields' => [['b' => 2, 'a' => 1]], 'name' => 'Leave request'], 'key-1');

        self::assertCount(1, $this->created);
        self::assertTrue($again['_meta'][ToolsCallHandler::META_REPLAYED]);
    }

    public function testTheSameKeyWithOtherArgumentsIsInvalidParams(): void
    {
        $this->call('rana', ['name' => 'Leave request'], 'key-1');

        $error = $this->callError('rana', ['name' => 'Expense claim'], 'key-1');

        self::assertSame(ErrorCode::INVALID_PARAMS, $error->errorCode);
        self::assertSame([['name' => 'Leave request']], $this->created);
    }

    public function testARetryWhileTheFirstCallIsStillRunningIsToldSo(): void
    {
        $during = null;
        $this->whileRunning = function () use (&$during): void {
            $during = $this->callError('rana', ['name' => 'Leave request'], 'key-1');
        };

        $this->call('rana', ['name' => 'Leave request'], 'key-1');

        self::assertInstanceOf(McpException::class, $during);
        self::assertSame(ErrorCode::CALL_IN_PROGRESS, $during->errorCode);
        self::assertCount(1, $this->created, 'the retry ran nothing');

        $after = $this->call('rana', ['name' => 'Leave request'], 'key-1');
        self::assertTrue($after['_meta'][ToolsCallHandler::META_REPLAYED], 'once done, the retry gets the result');
    }

    public function testAFailedCallReleasesItsKeySoTheSameKeyCanTryAgain(): void
    {
        $this->statuses = [503];

        $failed = $this->call('rana', ['name' => 'Leave request'], 'key-1');
        self::assertTrue($failed['isError']);
        self::assertNull($this->keyRow('key-1'));

        $retried = $this->call('rana', ['name' => 'Leave request'], 'key-1');
        self::assertFalse($retried['isError']);
        self::assertArrayNotHasKey('_meta', $retried, 'it ran, it was not replayed');
        self::assertCount(1, $this->created);
    }

    public function testACallRefusedBeforeRunningReleasesItsKey(): void
    {
        $error = $this->callError('rana', ['name' => 'Leave request'], 'key-1', 'no_such_tool');

        self::assertSame(ErrorCode::METHOD_NOT_FOUND, $error->errorCode);
        self::assertSame(0, $this->keyCount());
    }

    public function testAKeyLeftRunningByADeadProcessIsTakenOverOnceStale(): void
    {
        $this->pdo->prepare(
            "INSERT INTO mcp_call_keys (tenant_id, principal, tool, idempotency_key, arguments_hash, status, created_at)
             VALUES (1, 'profile:101', 'post_api_forms', 'key-1', ?, 'running', ?)"
        )->execute([McpCallKeys::hashArguments(['name' => 'Leave request']), gmdate('Y-m-d H:i:s', time() - McpCallKeys::STALE_SECONDS - 60)]);

        $result = $this->call('rana', ['name' => 'Leave request'], 'key-1');

        self::assertFalse($result['isError']);
        self::assertCount(1, $this->created);
        self::assertSame('done', $this->keyRow('key-1')['status'] ?? null);
    }

    public function testKeysAreNotSharedAcrossPeopleOrAgents(): void
    {
        $this->call('rana', ['name' => 'Leave request'], 'key-1');
        $this->call('omar', ['name' => 'Leave request'], 'key-1');

        self::assertCount(2, $this->created, 'another person with the same key runs their own call');
        self::assertSame(2, $this->keyCount());
    }

    public function testAnUnusableKeyIsInvalidParams(): void
    {
        foreach ([42, '', str_repeat('k', McpCallKeys::MAX_KEY_LENGTH + 1)] as $bad) {
            $error = null;
            try {
                ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => ['name' => 'Leave request'], '_meta' => [ToolsCallHandler::META_IDEMPOTENCY_KEY => $bad]], 'rana');
            } catch (McpException $e) {
                $error = $e;
            }
            self::assertSame(ErrorCode::INVALID_PARAMS, $error?->errorCode);
        }
        self::assertSame([], $this->created);
    }

    public function testWithoutAKeyEveryCallRunsAsBefore(): void
    {
        $this->call('rana', ['name' => 'Leave request']);
        $this->call('rana', ['name' => 'Leave request']);

        self::assertCount(2, $this->created);
        self::assertSame(0, $this->keyCount());
    }

    public function testAnAskFirstRetryWithAKeyGetsTheSameProposal(): void
    {
        $first  = $this->proposalOf($this->call('agent', ['name' => 'Leave request'], 'key-1'));
        $second = $this->call('agent', ['name' => 'Leave request'], 'key-1');

        self::assertSame($first['id'], $this->proposalOf($second)['id']);
        self::assertTrue($second['_meta'][ToolsCallHandler::META_REPLAYED]);
        self::assertCount(1, $this->proposals->listFor(self::TENANT, self::RANA));
    }

    // ── Ask first, no key ───────────────────────────────────────────────────

    public function testAnAskFirstRetryWithoutAKeyIsTheProposalAlreadyWaiting(): void
    {
        $first  = $this->proposalOf($this->call('agent', ['name' => 'Leave request']));
        $second = $this->proposalOf($this->call('agent', ['name' => 'Leave request']));

        self::assertSame($first['id'], $second['id']);
        self::assertArrayNotHasKey('alreadyWaiting', $first);
        self::assertTrue($second['alreadyWaiting']);
        self::assertCount(1, $this->proposals->listFor(self::TENANT, self::RANA), 'one card for the person');
        self::assertSame([], $this->created, 'nothing ran');
    }

    public function testOtherArgumentsOrAnotherPersonAreANewProposal(): void
    {
        $first = $this->proposalOf($this->call('agent', ['name' => 'Leave request']));
        $other = $this->proposalOf($this->call('agent', ['name' => 'Expense claim']));
        $omars = $this->proposalOf($this->call('agent-omar', ['name' => 'Leave request']));

        self::assertNotSame($first['id'], $other['id']);
        self::assertNotSame($first['id'], $omars['id']);
        self::assertCount(2, $this->proposals->listFor(self::TENANT, self::RANA));
        self::assertCount(1, $this->proposals->listFor(self::TENANT, self::OMAR));
    }

    public function testOnceDecidedTheSameCallAsksAgain(): void
    {
        $first = $this->proposalOf($this->call('agent', ['name' => 'Leave request']));
        self::assertTrue($this->proposals->decide($first['id'], self::TENANT, self::RANA, AgentProposalRepository::DECLINED));

        $again = $this->proposalOf($this->call('agent', ['name' => 'Leave request']));

        self::assertNotSame($first['id'], $again['id']);
        self::assertArrayNotHasKey('alreadyWaiting', $again);
    }

    public function testAnExpiredProposalIsNotHandedBack(): void
    {
        $first = $this->proposalOf($this->call('agent', ['name' => 'Leave request']));
        $this->pdo->prepare('UPDATE agent_proposals SET expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 60), $first['id']]);

        $again = $this->proposalOf($this->call('agent', ['name' => 'Leave request']));

        self::assertNotSame($first['id'], $again['id']);
        self::assertSame('expired', $this->proposals->find($first['id'], self::TENANT)['status'] ?? null);
    }

    public function testTheDatabaseKeepsOneWaitingProposalPerCallEvenWhenTwoArriveAtOnce(): void
    {
        $hash = McpCallKeys::hashArguments(['name' => 'Leave request']);
        $make = fn (): int => $this->proposals->create(self::TENANT, 'jti-billing-assistant', self::RANA, 'post_api_forms', ['name' => 'Leave request'], ['tools:call'], ['forms:manage'], 'Create a form', argumentsHash: $hash);

        self::assertGreaterThan(0, $make());
        self::assertSame(0, $make(), 'the second insert stores nothing');
        self::assertCount(1, $this->proposals->listFor(self::TENANT, self::RANA));
    }

    public function testProposalsMadeWithoutAHashAreNotDeduplicated(): void
    {
        $make = fn (): int => $this->proposals->create(self::TENANT, 'agent:31', self::RANA, 'post_api_forms', ['name' => 'Leave request'], ['tools:call'], ['forms:manage'], 'Create a form');

        self::assertNotSame($make(), $make());
    }

    // ── Clean-up ────────────────────────────────────────────────────────────

    public function testThePruneJobDeletesKeysOlderThanADay(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO mcp_call_keys (tenant_id, principal, tool, idempotency_key, arguments_hash, status, created_at)
             VALUES (1, 'profile:101', 'post_api_forms', ?, 'h', 'done', ?)"
        );
        $insert->execute(['old', gmdate('Y-m-d H:i:s', time() - McpCallKeys::LIFETIME_SECONDS - 60)]);
        $insert->execute(['new', gmdate('Y-m-d H:i:s', time() - 60)]);

        self::assertSame(['pruned' => 1], (new McpCallKeysPruneJob($this->keys))->handle([]));
        self::assertNull($this->keyRow('old'));
        self::assertNotNull($this->keyRow('new'));
    }

    public function testAnOldKeyIsForgottenSoTheCallRunsAgain(): void
    {
        $this->call('rana', ['name' => 'Leave request'], 'key-1');
        $this->pdo->prepare('UPDATE mcp_call_keys SET created_at = ?')->execute([gmdate('Y-m-d H:i:s', time() - McpCallKeys::LIFETIME_SECONDS - 60)]);
        $this->keys->prune();

        $this->call('rana', ['name' => 'Leave request'], 'key-1');

        self::assertCount(2, $this->created);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $bearer, array $arguments, ?string $key = null, string $tool = 'post_api_forms'): array
    {
        $params = ['name' => $tool, 'arguments' => $arguments];
        if ($key !== null) {
            $params['_meta'] = [ToolsCallHandler::META_IDEMPOTENCY_KEY => $key];
        }
        $result = ($this->toolsCall)($params, $bearer);
        self::assertIsArray($result);
        return $result;
    }

    /** @param array<string, mixed> $arguments */
    private function callError(string $bearer, array $arguments, string $key, string $tool = 'post_api_forms'): McpException
    {
        try {
            $this->call($bearer, $arguments, $key, $tool);
        } catch (McpException $e) {
            return $e;
        }
        self::fail('expected an MCP error');
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function proposalOf(array $result): array
    {
        $decoded = json_decode((string) $result['content'][0]['text'], true);
        self::assertIsArray($decoded['proposal'] ?? null, (string) $result['content'][0]['text']);
        return $decoded['proposal'];
    }

    /** @return array<string, mixed>|null */
    private function keyRow(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mcp_call_keys WHERE tenant_id = 1 AND idempotency_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function keyCount(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM mcp_call_keys');
        self::assertNotFalse($stmt);
        return (int) $stmt->fetchColumn();
    }
}
