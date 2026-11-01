<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AgentActionsApiHandler;
use Whity\Api\AgentProposalsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentActionRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolSurface;
use Whity\Sdk\Http\Response;

/**
 * Undo for agent actions (WHIT-812; register 7.5c): an agent's run of an
 * operation that declares an undo is recorded, and the person it acted for
 * undoes it inside the window, as themselves, unless it changed since.
 *
 * Against the migration-built schema, with a small in-memory "notes" resource
 * declared the way tags and forms are.
 */
final class AgentUndoRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;
    private const OMAR   = 202;
    private const AGENT  = 'jti-office-assistant';

    private PDO $pdo;
    private AgentActionRepository $actions;
    private AgentProposalRepository $proposals;
    private ToolsCallHandler $toolsCall;
    private AgentActionsApiHandler $undoApi;
    private AgentProposalsApiHandler $inbox;
    private ToolDeriver $deriver;
    private McpPrincipal $caller;
    private bool $deleteFails = false;

    /** @var array<int, array{id: int, name: string}> */
    private array $notes = [];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery') ON CONFLICT DO NOTHING");
        foreach ([self::RANA => 'Rana', self::OMAR => 'Omar'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
        SchemaFromMigrations::syncSequences($this->pdo);

        $router = new Router('');
        $router->registerUnversioned('POST', '/api/notes', function (Request $request): Response {
            $id = count($this->notes) + 1;
            $this->notes[$id] = ['id' => $id, 'name' => (string) (json_decode($request->getBody(), true)['name'] ?? '')];
            return Response::json(['data' => $this->notes[$id]], 201);
        }, null, null, 'notes:manage');
        $router->registerUnversioned('GET', '/api/notes/{id:\d+}', function (Request $request, array $params): Response {
            $note = $this->notes[(int) $params['id']] ?? null;
            return $note !== null ? Response::json(['data' => $note]) : Response::json(['message' => 'Note not found'], 404);
        }, null, null, 'notes:read');
        $router->registerUnversioned('PATCH', '/api/notes/{id:\d+}', function (Request $request, array $params): Response {
            $id = (int) $params['id'];
            if (!isset($this->notes[$id])) {
                return Response::json(['message' => 'Note not found'], 404);
            }
            $this->notes[$id]['name'] = (string) (json_decode($request->getBody(), true)['name'] ?? '');
            return Response::json(['data' => $this->notes[$id]]);
        }, null, null, 'notes:manage');
        $router->registerUnversioned('DELETE', '/api/notes/{id:\d+}', function (Request $request, array $params): Response {
            if ($this->deleteFails) {
                return Response::json(['message' => 'It is in use'], 409);
            }
            unset($this->notes[(int) $params['id']]);
            return Response::json([], 204);
        }, null, null, 'notes:manage');

        $id   = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];
        $name = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];
        $this->deriver = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/notes', 'requiredPermission' => 'notes:manage', 'schema' => [
                'summary' => 'Create a note', 'tags' => ['notes'], 'request' => $name,
                'mcp' => ['undo' => ['windowSeconds' => 1800, 'operation' => 'delete_api_notes_id', 'arguments' => ['id' => '/result/data/id']]],
            ]],
            ['method' => 'GET', 'path' => '/api/notes/{id:\d+}', 'requiredPermission' => 'notes:read', 'schema' => [
                'summary' => 'Get a note', 'tags' => ['notes'], 'parameters' => [$id],
            ]],
            ['method' => 'PATCH', 'path' => '/api/notes/{id:\d+}', 'requiredPermission' => 'notes:manage', 'schema' => [
                'summary' => 'Rename a note', 'tags' => ['notes'], 'parameters' => [$id], 'request' => $name,
                'mcp' => ['undo' => [
                    'windowSeconds' => 1800,
                    'read' => ['operation' => 'get_api_notes_id', 'arguments' => ['id' => '/arguments/id']],
                    'operation' => 'patch_api_notes_id',
                    'arguments' => ['id' => '/arguments/id', 'name' => '/before/data/name'],
                ]],
            ]],
            ['method' => 'DELETE', 'path' => '/api/notes/{id:\d+}', 'requiredPermission' => 'notes:manage', 'schema' => [
                'summary' => 'Delete a note', 'tags' => ['notes'], 'parameters' => [$id],
            ]],
        ], [], $router, static fn (string $m): null => null);

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturnCallback(fn (): McpPrincipal => $this->caller);

        $this->caller    = self::agent('grant:notes:manage=allow');
        $this->actions   = new AgentActionRepository($this->pdo);
        $this->proposals = new AgentProposalRepository($this->pdo);
        $this->toolsCall = new ToolsCallHandler($this->deriver, $router, $roles, $tokens, proposals: $this->proposals, actions: $this->actions);
        $this->undoApi   = new AgentActionsApiHandler($this->actions, $this->toolsCall, $this->proposals);
        $this->inbox     = new AgentProposalsApiHandler($this->proposals, $this->toolsCall, actions: $this->actions);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testAnUndoWindowLetsAnAllowedGrantRunAWrite(): void
    {
        $create = $this->deriver->toolFor('post_api_notes') ?? [];
        $delete = $this->deriver->toolFor('delete_api_notes_id') ?? [];
        $access = ['requiredPermission' => 'notes:manage'];

        self::assertSame(1800, $create['_meta'][ToolSurface::META_UNDO_WINDOW] ?? null);
        self::assertSame('allow', \Whity\Mcp\Tools\ToolsListHandler::agentModeFor($create, $access, $this->caller));
        // No undo: Ask first whatever the grant says (owner decision 11).
        self::assertSame('ask', \Whity\Mcp\Tools\ToolsListHandler::agentModeFor($delete, $access, $this->caller));
    }

    public function testAnAllowedCallIsRecordedAndUndoneAsThePerson(): void
    {
        $result = $this->call('post_api_notes', ['name' => 'Bread order']);

        $undo = $result['_meta'][ToolsCallHandler::META_UNDO] ?? null;
        self::assertIsArray($undo);
        self::assertSame(1800, $undo['windowSeconds']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $undo['until']);
        self::assertCount(1, $this->notes);

        $response = $this->undo($undo['actionId'], self::RANA);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame([], $this->notes, 'the inverse ran');
        $action = json_decode($response->getBody(), true)['data'];
        self::assertSame('undone', $action['status']);
        self::assertSame(['id' => self::RANA, 'name' => 'Rana'], $action['undoneBy']);
        self::assertNotNull($action['undoneAt']);
    }

    public function testAnUpdateIsUndoneByRestoringWhatItReplaced(): void
    {
        $this->notes[1] = ['id' => 1, 'name' => 'Bread order'];

        $actionId = $this->call('patch_api_notes_id', ['id' => 1, 'name' => 'Cake order'])['_meta'][ToolsCallHandler::META_UNDO]['actionId'];
        self::assertSame('Cake order', $this->notes[1]['name']);

        self::assertSame(200, $this->undo($actionId, self::RANA)->getStatusCode());
        self::assertSame('Bread order', $this->notes[1]['name']);
    }

    public function testUndoNeverOverwritesALaterChange(): void
    {
        $this->notes[1] = ['id' => 1, 'name' => 'Bread order'];
        $actionId = $this->call('patch_api_notes_id', ['id' => 1, 'name' => 'Cake order'])['_meta'][ToolsCallHandler::META_UNDO]['actionId'];
        $this->notes[1]['name'] = 'Cake order for Friday';

        $response = $this->undo($actionId, self::RANA);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('changed since', $response->getBody());
        self::assertSame('Cake order for Friday', $this->notes[1]['name']);
        self::assertSame('done', $this->actions->find($actionId, self::TENANT)['status'] ?? null);
    }

    public function testAnApprovedProposalCanBeUndoneAndSaysSo(): void
    {
        $this->caller = self::agent(); // default grant: writes are Ask first
        $proposalId   = (int) json_decode($this->call('post_api_notes', ['name' => 'Bread order'])['content'][0]['text'], true)['proposal']['id'];
        self::assertSame([], $this->notes);

        $approved = json_decode($this->inbox->approve($this->as(self::RANA), ['id' => (string) $proposalId])->getBody(), true)['data'];
        self::assertSame(1800, $approved['undoWindowSeconds']);
        self::assertIsArray($approved['action']);
        self::assertSame($proposalId, $approved['action']['proposalId']);

        self::assertSame(200, $this->undo($approved['action']['id'], self::RANA)->getStatusCode());
        self::assertSame('undone', $this->proposals->find($proposalId, self::TENANT)['status'] ?? null);
        self::assertSame([], $this->notes);
    }

    public function testOnlyThePersonItActedForCanUndoIt(): void
    {
        $actionId = $this->call('post_api_notes', ['name' => 'Bread order'])['_meta'][ToolsCallHandler::META_UNDO]['actionId'];

        self::assertSame(404, $this->undo($actionId, self::OMAR)->getStatusCode());
        self::assertCount(1, $this->notes);
    }

    public function testItCanBeUndoneOnceAndOnlyInsideItsWindow(): void
    {
        $actionId = $this->call('post_api_notes', ['name' => 'Bread order'])['_meta'][ToolsCallHandler::META_UNDO]['actionId'];
        self::assertSame(200, $this->undo($actionId, self::RANA)->getStatusCode());
        self::assertSame(409, $this->undo($actionId, self::RANA)->getStatusCode());

        $late = $this->call('post_api_notes', ['name' => 'Cake order'])['_meta'][ToolsCallHandler::META_UNDO]['actionId'];
        $this->pdo->prepare('UPDATE agent_actions SET undoable_until = :past WHERE id = :id')
            ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 1), ':id' => $late]);
        $response = $this->undo($late, self::RANA);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('window has closed', $response->getBody());
    }

    public function testAFailedInverseLeavesTheActionDone(): void
    {
        $actionId = $this->call('post_api_notes', ['name' => 'Bread order'])['_meta'][ToolsCallHandler::META_UNDO]['actionId'];
        $this->deleteFails = true;

        $response = $this->undo($actionId, self::RANA);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('It is in use', $response->getBody());
        self::assertSame('done', $this->actions->find($actionId, self::TENANT)['status'] ?? null);
    }

    public function testAPersonsOwnCallIsNotRecorded(): void
    {
        $this->caller = new McpPrincipal(self::RANA, self::RANA, self::TENANT, 'oauth', ['tools:call'], 'oauth:abc');

        $result = $this->call('post_api_notes', ['name' => 'Bread order']);

        self::assertArrayNotHasKey('_meta', $result);
        self::assertSame([], $this->actions->listFor(self::TENANT, self::RANA));
    }

    public function testTheActivityListShowsWhatCanBeUndone(): void
    {
        $this->call('post_api_notes', ['name' => 'Bread order']);

        $list = json_decode($this->undoApi->list($this->as(self::RANA))->getBody(), true)['data'];

        self::assertCount(1, $list);
        self::assertSame('post_api_notes', $list[0]['tool']);
        self::assertSame('done', $list[0]['status']);
        self::assertArrayNotHasKey('inverse', $list[0], 'how it is undone stays server-side');
        self::assertSame([], json_decode($this->undoApi->list($this->as(self::OMAR))->getBody(), true)['data']);
    }

    public function testAnApprovedProposalThatCantBeUndoneIsStillActivity(): void
    {
        $this->notes[1] = ['id' => 1, 'name' => 'Bread order'];
        $this->caller   = self::agent();
        $proposalId     = (int) json_decode($this->call('delete_api_notes_id', ['id' => 1])['content'][0]['text'], true)['proposal']['id'];

        $approved = json_decode($this->inbox->approve($this->as(self::RANA), ['id' => (string) $proposalId])->getBody(), true)['data'];

        self::assertSame([], $this->notes);
        self::assertNull($approved['undoWindowSeconds']);
        self::assertSame('done', $approved['action']['status']);
        self::assertNull($approved['action']['undoableUntil'], 'it cannot be undone');
        self::assertSame(['notes:manage'], $approved['action']['permissions']);
        $response = $this->undo($approved['action']['id'], self::RANA);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString("can't be undone", $response->getBody());
    }

    public function testAnActionPastItsWindowReadsAsExpiredAndFilters(): void
    {
        $open   = $this->call('post_api_notes', ['name' => 'Bread order'])['_meta'][ToolsCallHandler::META_UNDO]['actionId'];
        $closed = $this->call('post_api_notes', ['name' => 'Cake order'])['_meta'][ToolsCallHandler::META_UNDO]['actionId'];
        $this->pdo->prepare('UPDATE agent_actions SET undoable_until = :past WHERE id = :id')
            ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 1), ':id' => $closed]);

        self::assertSame([$closed], array_column($this->list('?status=expired'), 'id'));
        self::assertSame([$open], array_column($this->list('?status=done'), 'id'));
        self::assertSame('expired', $this->list('?status=expired')[0]['status']);
        self::assertSame([], $this->list('?since=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', time() + 3600))));
    }

    public function testAnActionNamesTheAgentCredential(): void
    {
        $this->pdo->prepare("INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
                             VALUES (?, ?, ?, 'Office assistant', 'ai-agent', '[]', ?, CURRENT_TIMESTAMP)")
            ->execute([self::AGENT, self::RANA, self::TENANT, gmdate('Y-m-d H:i:s', time() + 3600)]);

        $this->call('post_api_notes', ['name' => 'Bread order']);

        self::assertSame(['name' => 'Office assistant'], $this->list('')[0]['agent']);
    }

    /** @return list<array<string, mixed>> */
    private function list(string $query): array
    {
        $request       = new Request('GET', '/api/me/agent-actions' . $query, [], '');
        $request->user = (object) ['profile_id' => self::RANA];
        return json_decode($this->undoApi->list($request)->getBody(), true)['data'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments): array
    {
        $result = ($this->toolsCall)(['name' => $tool, 'arguments' => $arguments], 'bearer');
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? false, (string) ($result['content'][0]['text'] ?? ''));
        return $result;
    }

    private function undo(int $actionId, int $profileId): \Whity\Core\Response
    {
        return $this->undoApi->undo($this->as($profileId), ['id' => (string) $actionId]);
    }

    private function as(int $profileId): Request
    {
        $request       = new Request('POST', '/api/me/agent-actions', ['Content-Type' => 'application/json'], '');
        $request->user = (object) ['profile_id' => $profileId];
        return $request;
    }

    private static function agent(string ...$grants): McpPrincipal
    {
        return new McpPrincipal(self::RANA, self::RANA, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call', ...$grants], self::AGENT);
    }
}
