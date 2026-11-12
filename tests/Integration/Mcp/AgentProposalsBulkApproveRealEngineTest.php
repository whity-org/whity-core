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
use Whity\Core\Agent\ProposalTier;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Sdk\Http\Response;

/**
 * Bulk approval in the Approvals list (AQ.1): only Ordinary (tier-1) proposals
 * are approved together, each recorded on its own; Check first and Needs
 * verifying ones are never approved this way, whatever the screen sends.
 */
final class AgentProposalsBulkApproveRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;
    private const OMAR   = 202;

    private AgentProposalRepository $proposals;
    private AgentProposalsApiHandler $inbox;

    /** @var list<array<string, mixed>> */
    private array $created = [];

    /** @var list<string> */
    private array $audited = [];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        foreach ([self::RANA => 'Amro Saleh', self::OMAR => 'Emad Nasser'] as $id => $name) {
            $pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                           VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
        SchemaFromMigrations::syncSequences($pdo);

        $router = new Router('');
        $router->registerUnversioned('POST', '/api/forms', function (Request $request): Response {
            $this->created[] = (array) json_decode($request->getBody(), true);
            return Response::json(['id' => count($this->created)], 201);
        }, null, null, 'forms:manage');
        $deriver = new ToolDeriver([[
            'method' => 'POST', 'path' => '/api/forms', 'requiredPermission' => 'forms:manage',
            'schema' => ['summary' => 'Create a form', 'tags' => ['forms'], 'request' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],
        ]], [], $router, static fn (string $m): null => null);

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(
            new McpPrincipal(self::RANA, self::RANA, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call'], 'jti-billing-assistant'),
        );
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->method('record')->willReturnCallback(function (string $event): void {
            $this->audited[] = $event;
        });

        $this->proposals = new AgentProposalRepository($pdo);
        $toolsCall       = new ToolsCallHandler($deriver, $router, $roles, $tokens, proposals: $this->proposals);
        $this->inbox     = new AgentProposalsApiHandler($this->proposals, $toolsCall, $audit);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testTheTierFollowsWhatTheCallNeedsAndWhetherItCanBeUndone(): void
    {
        self::assertSame(ProposalTier::ORDINARY, ProposalTier::of(['forms:manage'], 300));
        self::assertSame(ProposalTier::CHECK_FIRST, ProposalTier::of(['forms:manage'], null), 'no undo, so check first');
        self::assertSame(ProposalTier::CHECK_FIRST, ProposalTier::of(['documents:issue'], 300));
        self::assertSame(ProposalTier::NEEDS_VERIFYING, ProposalTier::of(['users:write'], 300));
        self::assertSame(ProposalTier::NEEDS_VERIFYING, ProposalTier::of(['forms:manage', 'tags:delete'], 300));
    }

    public function testOrdinaryProposalsAreApprovedTogetherEachOnItsOwn(): void
    {
        $first  = $this->propose('Leave request', ['forms:manage'], 300);
        $second = $this->propose('Expense claim', ['forms:manage'], 300);

        $response = $this->inbox->bulkApprove($this->as(self::RANA, ['ids' => [$first, $second]]));

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true)['data'];
        self::assertSame([$first, $second], array_column($data['approved'], 'id'));
        self::assertSame([], $data['refused']);
        self::assertSame([['name' => 'Leave request'], ['name' => 'Expense claim']], $this->created, 'each ran once');
        self::assertSame(['agent.proposal.approved', 'agent.proposal.approved'], $this->audited, 'one audit entry each');
    }

    public function testCheckFirstAndNeedsVerifyingAreNeverApprovedTogether(): void
    {
        $ordinary   = $this->propose('Leave request', ['forms:manage'], 300);
        $checkFirst = $this->propose('Expense claim', ['forms:manage'], null);
        $verifying  = $this->propose('Remove a person', ['users:delete'], 300);

        $data = json_decode($this->inbox->bulkApprove($this->as(self::RANA, ['ids' => [$ordinary, $checkFirst, $verifying]]))->getBody(), true)['data'];

        self::assertSame([$ordinary], array_column($data['approved'], 'id'));
        self::assertSame([
            ['id' => $checkFirst, 'reason' => 'open_on_its_own'],
            ['id' => $verifying, 'reason' => 'open_on_its_own'],
        ], $data['refused']);
        self::assertSame('waiting', $this->proposals->find($checkFirst, self::TENANT)['status']);
        self::assertSame('waiting', $this->proposals->find($verifying, self::TENANT)['status']);
        self::assertCount(1, $this->created);
    }

    public function testSomeoneElsesProposalsAndDecidedOnesAreRefused(): void
    {
        $rana    = $this->propose('Leave request', ['forms:manage'], 300);
        $decided = $this->propose('Expense claim', ['forms:manage'], 300);
        $this->inbox->decline($this->as(self::RANA), ['id' => (string) $decided]);

        $data = json_decode($this->inbox->bulkApprove($this->as(self::OMAR, ['ids' => [$rana]]))->getBody(), true)['data'];
        self::assertSame([['id' => $rana, 'reason' => 'not_found']], $data['refused']);

        $data = json_decode($this->inbox->bulkApprove($this->as(self::RANA, ['ids' => [$decided]]))->getBody(), true)['data'];
        self::assertSame([['id' => $decided, 'reason' => 'not_waiting']], $data['refused']);
        self::assertSame([], $this->created);
    }

    public function testTheListSaysEachProposalsTier(): void
    {
        $this->propose('Leave request', ['forms:manage'], 300);
        $this->propose('Remove a person', ['users:delete'], 300);

        $listed = json_decode($this->inbox->list($this->as(self::RANA))->getBody(), true)['data'];

        self::assertSame([3, 1], array_column($listed, 'tier'), 'newest first');
    }

    public function testAnEmptyOrOversizedListIsRefused(): void
    {
        self::assertSame(422, $this->inbox->bulkApprove($this->as(self::RANA, ['ids' => []]))->getStatusCode());
        self::assertSame(422, $this->inbox->bulkApprove($this->as(self::RANA, ['ids' => range(1, 51)]))->getStatusCode());
    }

    /** @param list<string> $permissions */
    private function propose(string $name, array $permissions, ?int $undoWindowSeconds): int
    {
        return $this->proposals->create(self::TENANT, 'jti-billing-assistant', self::RANA, 'post_api_forms', ['name' => $name], ['tools:call'], $permissions, 'Create a form', $undoWindowSeconds);
    }

    /** @param array<string, mixed> $body */
    private function as(int $profileId, array $body = []): Request
    {
        $request       = new Request('POST', '/api/me/agent-proposals/bulk-approve', ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];
        return $request;
    }
}
