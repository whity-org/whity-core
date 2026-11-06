<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentGrantView;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Mcp\Tools\ToolSurface;
use Whity\Sdk\Http\Response;

/**
 * Core decides whether an agent's tool only reads (WHIT-912).
 *
 * Whether an agent runs a tool at once or a person is asked first depends on
 * whether the tool only reads. That must come from what core controls: the
 * HTTP method of a plugin's route, never the `readOnlyHint` a plugin writes
 * for itself. A plugin-authored tool has no method, so for agents it is a
 * write. Core's own routes keep what core declares.
 *
 * Against the migration-built schema (SQLite, or PostgreSQL with
 * PHPUNIT_PG_DSN), with the agent's default grant: reads Allowed, writes Ask
 * first.
 */
final class AgentToolReadDecisionRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;
    private const AGENT  = 'jti-office-assistant';

    private PDO $pdo;
    private ToolDeriver $deriver;
    private AuthoredToolRegistry $authored;
    private ToolsCallHandler $toolsCall;
    private ToolsListHandler $toolsList;
    private McpPrincipal $caller;

    /** @var list<string> what the handlers actually ran */
    private array $ran = [];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery') ON CONFLICT DO NOTHING");
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Rana', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([self::RANA]);
        SchemaFromMigrations::syncSequences($this->pdo);

        $router = new Router('');
        $ran    = function (string $what): Response {
            $this->ran[] = $what;
            return Response::json(['data' => ['ran' => $what]]);
        };
        $body = ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]];

        // A plugin's routes, as the loader registers them: under its namespace.
        $router->registerUnversioned('GET', '/api/stock/levels', static fn (): Response => $ran('plugin read'), null, 'stock', 'stock:read', [
            'operationId' => 'stock_levels', 'summary' => 'Stock levels',
        ]);
        $router->registerUnversioned('POST', '/api/stock/adjust', static fn (): Response => $ran('plugin write'), null, 'stock', 'stock:read', [
            'operationId' => 'stock_adjust', 'summary' => 'Adjust stock', 'request' => $body,
            'mcp_annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
        ]);

        // Core's own routes: a read, a POST core declares read-only, a write.
        $router->registerUnversioned('GET', '/api/notes', static fn (): Response => $ran('core read'), null, null, 'notes:read', [
            'operationId' => 'notes_list', 'summary' => 'List notes', 'tags' => ['notes'],
        ]);
        $router->registerUnversioned('POST', '/api/notes/validate', static fn (): Response => $ran('core check'), null, null, 'notes:read', [
            'operationId' => 'notes_validate', 'summary' => 'Check a note', 'tags' => ['notes'], 'request' => $body,
            'mcp_annotations' => ['readOnlyHint' => true, 'idempotentHint' => true],
        ]);
        $router->registerUnversioned('POST', '/api/notes', static fn (): Response => $ran('core write'), null, null, 'notes:manage', [
            'operationId' => 'notes_create', 'summary' => 'Create a note', 'tags' => ['notes'], 'request' => $body,
        ]);

        $this->deriver  = new ToolDeriver([], [], $router, static fn (string $m): null => null);
        $this->authored = new AuthoredToolRegistry();
        self::assertNull($this->authored->register([
            'name'               => 'stock_recount',
            'description'        => 'Recount a shelf.',
            'inputSchema'        => $body,
            'handler'            => fn (): array => ['ran' => $this->ran[] = 'authored write'],
            'requiredPermission' => 'stock:read',
            'annotations'        => ['readOnlyHint' => true],
        ], 'Stock'));

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturnCallback(fn (): McpPrincipal => $this->caller);

        $this->caller    = new McpPrincipal(self::RANA, self::RANA, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call'], self::AGENT);
        $this->toolsCall = new ToolsCallHandler($this->deriver, $router, $roles, $tokens, authoredTools: $this->authored, proposals: new AgentProposalRepository($this->pdo));
        $this->toolsList = new ToolsListHandler($this->deriver, $roles, $tokens, $this->authored);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testAPluginWriteDeclaredReadOnlyIsHeldForApproval(): void
    {
        $result = $this->call('stock_adjust');

        self::assertSame([], $this->ran, 'nothing ran before a person approved');
        self::assertSame('waiting', $this->proposal($result)['status'] ?? null);
        self::assertSame(1, $this->proposalRows());
    }

    public function testAPluginAuthoredToolIsHeldForApprovalWhateverItDeclares(): void
    {
        $result = $this->call('stock_recount');

        self::assertSame([], $this->ran);
        self::assertSame('waiting', $this->proposal($result)['status'] ?? null);
        self::assertSame(1, $this->proposalRows());
    }

    public function testAnHonestPluginReadStillRunsAtOnce(): void
    {
        $result = $this->call('stock_levels');

        self::assertSame(['plugin read'], $this->ran);
        self::assertStringContainsString('plugin read', (string) ($result['content'][0]['text'] ?? ''));
        self::assertSame(0, $this->proposalRows());
    }

    public function testCoreToolsAreUnchanged(): void
    {
        $this->call('notes_list');
        $this->call('notes_validate');
        self::assertSame(['core read', 'core check'], $this->ran, 'core reads, including the POST core declares read-only, run at once');

        $result = $this->call('notes_create');
        self::assertSame(['core read', 'core check'], $this->ran);
        self::assertSame('waiting', $this->proposal($result)['status'] ?? null);
    }

    public function testTheListAndTheGrantViewSayWhatCoreDecided(): void
    {
        $listed = [];
        foreach ($this->toolsList->listFor($this->caller, self::TENANT) as $tool) {
            $listed[(string) $tool['name']] = $tool;
        }

        // What clients are told matches what the agent check does.
        self::assertFalse($listed['stock_adjust']['annotations']['readOnlyHint']);
        self::assertFalse(ToolSurface::readsOnly($listed['stock_adjust']));
        self::assertSame('ask', $listed['stock_adjust']['_meta'][ToolsListHandler::META_AGENT_MODE]);
        self::assertSame('ask', $listed['stock_recount']['_meta'][ToolsListHandler::META_AGENT_MODE]);
        self::assertSame('allow', $listed['stock_levels']['_meta'][ToolsListHandler::META_AGENT_MODE]);
        self::assertSame('allow', $listed['notes_validate']['_meta'][ToolsListHandler::META_AGENT_MODE]);
        self::assertTrue($listed['notes_validate']['annotations']['readOnlyHint']);

        $operations = AgentGrantView::operationsFrom(
            [$listed['stock_adjust'], $listed['stock_recount'], $listed['stock_levels']],
            ['stock_adjust' => ['requiredPermission' => 'stock:read'], 'stock_recount' => ['requiredPermission' => 'stock:read'], 'stock_levels' => ['requiredPermission' => 'stock:read']],
        );
        self::assertSame([false, false, true], array_column($operations, 'readOnly'));
    }

    /** @return array<string, mixed> */
    private function call(string $tool): array
    {
        $result = ($this->toolsCall)(['name' => $tool, 'arguments' => ['sku' => 'BREAD-1']], 'bearer');
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? false, (string) ($result['content'][0]['text'] ?? ''));
        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function proposal(array $result): array
    {
        $decoded = json_decode((string) ($result['content'][0]['text'] ?? ''), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['proposal'] ?? null, 'the call returned a proposal');
        return $decoded['proposal'];
    }

    private function proposalRows(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM agent_proposals');
        self::assertNotFalse($statement);
        return (int) $statement->fetchColumn();
    }
}
