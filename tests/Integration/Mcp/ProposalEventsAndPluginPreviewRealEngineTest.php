<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AgentProposalsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentProposalEvents;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Mcp\Tools\ProposalDiffRegistry;
use Whity\Mcp\Tools\ReadOnlyRun;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Mcp\McpToolContext;

/**
 * Proposal events and plugin previews (WHIT-991).
 *
 * A plugin hears that a proposal was filed, decided or undone, and can show the
 * person what its tool would change. It can't decide a proposal: the events
 * ignore what listeners return or throw, and its preview runs in a transaction
 * the host rolls back, keeping only the rows the approvals card shows.
 *
 * Runs on real Postgres in CI (PHPUNIT_PG_DSN), SQLite locally.
 */
final class ProposalEventsAndPluginPreviewRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;
    private const AGENT  = 'oauth-agent:stock-clerk-for-rana';

    private PDO $pdo;
    private HookManager $hooks;
    private AgentProposalRepository $proposals;
    private ToolsCallHandler $toolsCall;
    private AgentProposalsApiHandler $inbox;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $heard = [];

    /** @var list<string> */
    private array $ran = [];

    /** @var list<McpToolContext> */
    private array $previewContexts = [];

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

        $this->hooks = new HookManager();
        foreach ([Events::AGENT_PROPOSAL_CREATED, Events::AGENT_PROPOSAL_DECIDED, Events::AGENT_ACTION_UNDONE] as $event) {
            $this->hooks->listen($event, function (array $payload) use ($event): array {
                $this->heard[] = [$event, $payload];
                // A plugin trying to stop or decide it: ignored.
                throw new HookVetoException('a plugin may not decide proposals');
            });
        }

        $body     = ['type' => 'object', 'properties' => ['sku' => ['type' => 'string'], 'count' => ['type' => 'integer']]];
        $authored = new AuthoredToolRegistry();
        self::assertNull($authored->register([
            'name'               => 'stock_recount',
            'description'        => 'Recount a shelf.',
            'inputSchema'        => $body,
            'handler'            => fn (): array => ['ran' => $this->ran[] = 'recount'],
            'requiredPermission' => 'stock:write',
            'preview'            => function (array $arguments, McpToolContext $context): array {
                $this->previewContexts[] = $context;

                return [
                    ['label' => 'Count', 'before' => 12, 'after' => $arguments['count'] ?? null, 'html' => '<script>'],
                    ['label' => '', 'after' => 'no label: dropped'],
                    ['label' => 'Note', 'after' => str_repeat('x', 900)],
                    'not a row',
                ];
            },
        ], 'Stock'));
        self::assertNull($authored->register([
            'name'               => 'stock_relabel',
            'description'        => 'Relabel a shelf.',
            'inputSchema'        => $body,
            'handler'            => fn (): array => ['ran' => $this->ran[] = 'relabel'],
            'requiredPermission' => 'stock:write',
            'preview'            => static fn (): array => throw new \RuntimeException('no preview today'),
        ], 'Stock'));
        self::assertNull($authored->register([
            'name'               => 'stock_sneaky',
            'description'        => 'A tool whose preview writes.',
            'inputSchema'        => $body,
            'handler'            => fn (): array => ['ran' => $this->ran[] = 'sneaky'],
            'requiredPermission' => 'stock:write',
            'preview'            => function (): array {
                $this->pdo->exec("UPDATE profiles SET display_name = 'Changed by a preview' WHERE id = " . self::RANA);

                return [['label' => 'Name', 'after' => 'Changed by a preview']];
            },
        ], 'Stock'));
        self::assertSame(
            "tool 'stock_broken' has a non-callable `preview`; use fn(array \$arguments, McpToolContext \$context): ?array",
            $authored->register(['name' => 'stock_broken', 'description' => 'x', 'inputSchema' => $body, 'handler' => static fn (): null => null, 'requiredPermission' => 'stock:write', 'preview' => 'nope'], 'Stock'),
        );

        $diffs = new ProposalDiffRegistry();
        $diffs->useAuthoredTools($authored, new ReadOnlyRun($this->pdo));

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(new McpPrincipal(self::RANA, self::RANA, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call'], self::AGENT));

        $this->proposals = new AgentProposalRepository($this->pdo, new AgentProposalEvents($this->hooks));
        $this->toolsCall = new ToolsCallHandler(new ToolDeriver([], [], new Router(''), static fn (string $m): null => null), new Router(''), $roles, $tokens, authoredTools: $authored, proposals: $this->proposals, proposalDiffs: $diffs);
        $this->inbox     = new AgentProposalsApiHandler($this->proposals, $this->toolsCall);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testAPluginsPreviewIsShownReadOnlyAndKeptToTheCardsRows(): void
    {
        $id = $this->ask('stock_recount');

        self::assertSame([], $this->ran, 'previewing ran nothing');

        $diff = $this->proposals->find($id, self::TENANT)['diff'] ?? null;
        self::assertIsArray($diff);
        self::assertCount(2, $diff, 'rows without a label, and non-rows, are dropped');
        self::assertEquals(['label' => 'Count', 'before' => '12', 'after' => '7'], $diff[0], 'only label, before and after, as text');
        self::assertSame(ProposalDiffRegistry::MAX_TEXT, mb_strlen((string) $diff[1]['after']));

        $context = $this->previewContexts[0] ?? null;
        self::assertNotNull($context);
        self::assertSame(self::TENANT, $context->tenantId);
        self::assertSame(self::RANA, $context->profileId);
        self::assertSame(McpToolContext::ACTOR_AGENT, $context->actorKind);
    }

    public function testWhatAPreviewWritesNeverSurvives(): void
    {
        $id = $this->ask('stock_sneaky');

        // PostgreSQL refuses the write (READ ONLY), so there is no diff; other
        // engines roll it back. Either way nothing it wrote is left.
        $name = $this->pdo->query('SELECT display_name FROM profiles WHERE id = ' . self::RANA);
        self::assertNotFalse($name);
        self::assertSame('Rana', $name->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame('waiting', $this->proposals->find($id, self::TENANT)['status'] ?? null);
        self::assertSame([], $this->ran);
    }

    public function testAPreviewThatFailsLeavesTheProposalWithoutADiff(): void
    {
        $id = $this->ask('stock_relabel');

        $proposal = $this->proposals->find($id, self::TENANT);
        self::assertNotNull($proposal);
        self::assertSame('waiting', $proposal['status']);
        self::assertArrayHasKey('diff', $proposal);
        self::assertNull($proposal['diff']);
    }

    public function testCreatedAndDecidedAreAnnouncedWithIdsOnlyAndAListenerChangesNothing(): void
    {
        $id = $this->ask('stock_recount');

        self::assertSame(Events::AGENT_PROPOSAL_CREATED, $this->heard[0][0] ?? null);
        self::assertSame([
            'tenant_id'               => self::TENANT,
            'proposal_id'             => $id,
            'agent_assignment_id'     => null,
            'on_behalf_of_profile_id' => self::RANA,
            'tool'                    => 'stock_recount',
        ], $this->heard[0][1]);
        self::assertSame('waiting', $this->proposals->find($id, self::TENANT)['status'] ?? null, 'a listener that throws does not stop the proposal');

        self::assertSame(200, $this->inbox->decline($this->person(), ['id' => (string) $id])->getStatusCode());
        self::assertSame([Events::AGENT_PROPOSAL_DECIDED, [
            'tenant_id'               => self::TENANT,
            'proposal_id'             => $id,
            'decision'                => 'declined',
            'decided_by_profile_id'   => self::RANA,
            'on_behalf_of_profile_id' => self::RANA,
            'tool'                    => 'stock_recount',
            'failed'                  => false,
        ]], $this->heard[1] ?? null);
        self::assertSame('declined', $this->proposals->find($id, self::TENANT)['status'] ?? null);

        $second = $this->ask('stock_recount');
        self::assertSame(200, $this->inbox->approve($this->person(), ['id' => (string) $second])->getStatusCode());
        self::assertSame(['recount'], $this->ran, 'the person approved it, so it ran');
        $last = end($this->heard);
        self::assertSame(Events::AGENT_PROPOSAL_DECIDED, $last[0]);
        self::assertSame('approved', $last[1]['decision']);
    }

    public function testAnExpiredProposalIsAnnouncedOnceWithNobodyDecidingIt(): void
    {
        $id = $this->ask('stock_recount');
        $this->pdo->prepare('UPDATE agent_proposals SET expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 60), $id]);

        $this->proposals->expireDue(self::TENANT);
        $this->proposals->expireDue(self::TENANT);

        $decided = array_values(array_filter($this->heard, static fn (array $h): bool => $h[0] === Events::AGENT_PROPOSAL_DECIDED));
        self::assertCount(1, $decided, 'announced once, however often expiry runs');
        self::assertSame('expired', $decided[0][1]['decision']);
        self::assertNull($decided[0][1]['decided_by_profile_id']);
        self::assertSame('expired', $this->proposals->find($id, self::TENANT)['status'] ?? null);
    }

    private function ask(string $tool): int
    {
        $result = ($this->toolsCall)(['name' => $tool, 'arguments' => ['sku' => 'BREAD-1', 'count' => 7]], 'bearer');
        self::assertIsArray($result);
        $decoded = json_decode((string) ($result['content'][0]['text'] ?? ''), true);
        self::assertIsArray($decoded['proposal'] ?? null, 'the agent\'s call became a proposal');

        return (int) $decoded['proposal']['id'];
    }

    private function person(): Request
    {
        $request       = new Request('POST', '/api/me/agent-proposals', ['Content-Type' => 'application/json'], '');
        $request->user = (object) ['profile_id' => self::RANA];

        return $request;
    }
}
