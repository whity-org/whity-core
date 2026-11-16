<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Security\GatedApproval;
use Whity\Core\Security\IdentityCheck;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AgentProposalsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Plugin\Tenant\WorkspacePluginProposalDiffs;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\OpenAPI\CoreApiSchemas;
use Whity\Sdk\Http\Response;

/**
 * "Ask first" made real (WHIT-784; register 7.5a, 7.5b; owner decisions 10
 * and 12): an agent's Ask-first call is stored, not run; the person it acts
 * for approves, edits or declines; approval runs it once, re-checked.
 */
final class AgentProposalsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;
    private const OMAR   = 202;

    private PDO $pdo;
    private AgentProposalRepository $proposals;
    private ToolsCallHandler $toolsCall;
    private AgentProposalsApiHandler $inbox;
    private bool $ranaMayManageForms = true;

    public const GOOD_CODE = '123456';

    /** When set, the only purpose the stand-in check accepts. */
    private ?string $goodPurpose = null;

    /** @var list<array<string, mixed>> */
    private array $created = [];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        foreach ([self::RANA => 'Rana', self::OMAR => 'Omar'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
        SchemaFromMigrations::syncSequences($this->pdo);

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
        $roles->method('hasPermissionForProfile')->willReturnCallback(fn (): bool => $this->ranaMayManageForms);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(
            new McpPrincipal(self::RANA, self::RANA, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call'], 'jti-billing-assistant'),
        );

        $this->proposals = new AgentProposalRepository($this->pdo);
        $this->toolsCall = new ToolsCallHandler($deriver, $router, $roles, $tokens, proposals: $this->proposals);
        $this->inbox     = new AgentProposalsApiHandler($this->proposals, $this->toolsCall);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testAnAskFirstCallIsStoredNotRun(): void
    {
        $result = ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => ['name' => 'Leave request']], 'bearer');

        self::assertSame([], $this->created, 'nothing ran');
        $proposal = json_decode($result['content'][0]['text'], true)['proposal'];
        self::assertSame('waiting', $proposal['status']);
        self::assertSame(['forms:manage'], $proposal['permissions']);

        $stored = $this->stored($proposal['id'], self::TENANT);
        self::assertSame(self::RANA, $stored['on_behalf_of_profile_id']);
        self::assertSame('jti-billing-assistant', $stored['agent_principal_id']);
        self::assertSame(['name' => 'Leave request'], $stored['arguments']);
    }

    public function testTheInboxNamesTheAgentByItsCredential(): void
    {
        $this->pdo->prepare("INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
                             VALUES ('jti-billing-assistant', ?, ?, 'Billing assistant', 'ai-agent', '[]', '2099-01-01 00:00:00', CURRENT_TIMESTAMP)")
            ->execute([self::RANA, self::TENANT]);
        ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => ['name' => 'Leave request']], 'bearer');

        $listed = json_decode($this->inbox->list($this->as(self::RANA))->getBody(), true)['data'][0];

        self::assertSame(['name' => 'Billing assistant', 'nameAr' => null], $listed['agent']);
        self::assertArrayNotHasKey('agent_principal_id', $listed, 'the credential id stays server-side');
    }

    public function testAPausedAgentsProposalCanBeDeclinedButRunsOnlyOnceResumed(): void
    {
        $this->pdo->exec("INSERT INTO agents (id, tenant_id, slug, name, status) VALUES (7, 1, 'billing', 'Billing assistant', 'active')");
        $this->pdo->prepare('INSERT INTO agent_assignments (id, tenant_id, agent_id, profile_id) VALUES (31, 1, 7, ?)')->execute([self::RANA]);
        $agents      = new AgentRepository($this->pdo);
        $assignments = new AgentAssignmentRepository($this->pdo);
        $inbox       = new AgentProposalsApiHandler($this->proposals, $this->toolsCall, null, $assignments);
        $propose     = fn (): int => $this->proposals->create(self::TENANT, 'agent:31', self::RANA, 'post_api_forms', ['name' => 'Leave request'], ['tools:call'], ['forms:manage'], 'Create a form');
        $first       = $propose();
        $second      = $propose();

        $agents->setPaused(7, self::TENANT, true, self::RANA);

        $approve = $inbox->approve($this->as(self::RANA), ['id' => (string) $first]);
        self::assertSame(409, $approve->getStatusCode());
        self::assertStringContainsString('paused', $approve->getBody());
        self::assertSame([], $this->created, 'nothing ran');
        self::assertSame('waiting', $this->stored($first, self::TENANT)['status'], 'and it keeps waiting');
        self::assertTrue(json_decode($inbox->list($this->as(self::RANA))->getBody(), true)['data'][0]['agentPaused']);

        self::assertSame(200, $inbox->decline($this->as(self::RANA), ['id' => (string) $second])->getStatusCode(), 'a person can still say no');

        $agents->setPaused(7, self::TENANT, false, self::RANA);

        self::assertSame(200, $inbox->approve($this->as(self::RANA), ['id' => (string) $first])->getStatusCode(), $approve->getBody());
        self::assertCount(1, $this->created, 'resumed, it runs');
    }

    public function testAWorkspaceAgentIsNamedByItsDefinitionEvenOnceRetired(): void
    {
        $this->pdo->exec("INSERT INTO agents (id, tenant_id, slug, name, name_ar, status)
                          VALUES (7, 1, 'billing', 'Billing assistant', 'مساعد الفواتير', 'archived')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Example Clinic', 'example-clinic')");
        $this->pdo->exec("INSERT INTO agents (id, tenant_id, slug, name) VALUES (8, 2, 'front-desk', 'Front desk')");
        $this->pdo->prepare('INSERT INTO agent_assignments (id, tenant_id, agent_id, profile_id, revoked_at) VALUES (31, 1, 7, ?, CURRENT_TIMESTAMP)')->execute([self::RANA]);
        $this->pdo->prepare('INSERT INTO agent_assignments (id, tenant_id, agent_id, profile_id) VALUES (32, 2, 8, ?)')->execute([self::RANA]);

        self::assertSame(
            ['agent:31' => ['name' => 'Billing assistant', 'nameAr' => 'مساعد الفواتير']],
            $this->proposals->agentNames(self::TENANT, ['agent:31', 'agent:32']),
            "revoked and archived still named; another workspace's assignment is not",
        );
    }

    public function testAnAgentWhoseCredentialIsGoneHasNoName(): void
    {
        ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => ['name' => 'Leave request']], 'bearer');

        self::assertSame(['name' => null, 'nameAr' => null], json_decode($this->inbox->list($this->as(self::RANA))->getBody(), true)['data'][0]['agent']);
    }

    public function testAnInvalidCallIsNeverPutToAPerson(): void
    {
        $result = ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => []], 'bearer');

        self::assertTrue($result['isError']);
        self::assertSame([], $this->proposals->listFor(self::TENANT, self::RANA));
    }

    public function testApprovalRunsItOnceAsTheirDelegate(): void
    {
        $id = $this->ask(['name' => 'Leave request']);

        $first  = $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id]);
        $second = $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id]);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(409, $second->getStatusCode(), 'a second approval runs nothing');
        self::assertSame([['name' => 'Leave request']], $this->created);
        self::assertSame('approved', $this->stored($id, self::TENANT)['status']);
        self::assertNotNull($this->stored($id, self::TENANT)['result']);
    }

    public function testApprovalCanEditTheArguments(): void
    {
        $id = $this->ask(['name' => 'Leave reqest']);

        $this->inbox->approve($this->as(self::RANA, '{"arguments":{"name":"Leave request"}}'), ['id' => (string) $id]);

        self::assertSame([['name' => 'Leave request']], $this->created);
    }

    public function testDecliningRunsNothing(): void
    {
        $id = $this->ask(['name' => 'Leave request']);

        self::assertSame(200, $this->inbox->decline($this->as(self::RANA), ['id' => (string) $id])->getStatusCode());
        self::assertSame(409, $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id])->getStatusCode());
        self::assertSame([], $this->created);
    }

    public function testOnlyTheGrantingPersonDecides(): void
    {
        $id = $this->ask(['name' => 'Leave request']);

        self::assertSame(404, $this->inbox->approve($this->as(self::OMAR), ['id' => (string) $id])->getStatusCode());
        self::assertSame(404, $this->inbox->decline($this->as(self::OMAR), ['id' => (string) $id])->getStatusCode());
        self::assertSame([], json_decode($this->inbox->list($this->as(self::OMAR))->getBody(), true)['data']);
        self::assertSame([], $this->created);
    }

    public function testAnExpiredProposalCanNotBeApproved(): void
    {
        $id = $this->ask(['name' => 'Leave request']);
        $this->pdo->exec("UPDATE agent_proposals SET expires_at = '2000-01-01 00:00:00' WHERE id = {$id}");

        self::assertSame(409, $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id])->getStatusCode());
        self::assertSame('expired', json_decode($this->inbox->list($this->as(self::RANA))->getBody(), true)['data'][0]['status']);
        self::assertSame([], $this->created);
    }

    public function testApprovalChecksAgainAndRecordsARefusal(): void
    {
        $id = $this->ask(['name' => 'Leave request']);
        $this->ranaMayManageForms = false;

        self::assertSame(200, $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id])->getStatusCode());
        self::assertSame([], $this->created, 'the person no longer may, so nothing ran');
        self::assertTrue($this->stored($id, self::TENANT)['result']['isError']);
    }

    /**
     * WHIT-1042 (TplImpactPlan): a Whity Builder publish whose tests failed
     * can't be approved, alone or together; the person declines it with the
     * failing tests named, and that reason is kept for the agent.
     */
    public function testAnImpactPlanWithFailedTestsCanNotBeApproved(): void
    {
        $id = $this->plan(['level' => 'safe', 'changes' => [], 'tests' => ['passed' => 23, 'failed' => 1]]);

        $refused = $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('tests_failed', $refused->getBody());
        $bulk = json_decode($this->inbox->bulkApprove($this->as(self::RANA, (string) json_encode(['ids' => [$id]])))->getBody(), true)['data'];
        self::assertSame([['id' => $id, 'reason' => 'open_on_its_own']], $bulk['refused']);
        self::assertSame('waiting', $this->stored($id, self::TENANT)['status']);

        $declined = $this->inbox->decline($this->as(self::RANA, '{"reason":"Fix the failing test: Orders with no notes keep an empty pickup time."}'), ['id' => (string) $id]);
        self::assertSame(200, $declined->getStatusCode());
        self::assertSame('Fix the failing test: Orders with no notes keep an empty pickup time.', json_decode($declined->getBody(), true)['data']['declineReason']);
        self::assertSame('declined', $this->stored($id, self::TENANT)['status']);
    }

    public function testADestructiveImpactPlanNeedsTheTick(): void
    {
        $id = $this->plan(['level' => 'destructive', 'changes' => [], 'tests' => ['passed' => 24, 'failed' => 0]]);

        $refused = $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('acknowledge_destructive', $refused->getBody());
        self::assertSame('waiting', $this->stored($id, self::TENANT)['status']);

        self::assertSame(200, $this->inbox->approve($this->as(self::RANA, '{"acknowledgedDestructive":true}'), ['id' => (string) $id])->getStatusCode());
        self::assertSame('approved', $this->stored($id, self::TENANT)['status']);
    }

    public function testADeclineWithoutAReasonKeepsNone(): void
    {
        $id = $this->ask(['name' => 'Leave request']);

        $declined = $this->inbox->decline($this->as(self::RANA, '{"reason":"   "}'), ['id' => (string) $id]);

        self::assertNull(json_decode($declined->getBody(), true)['data']['declineReason']);
    }

    public function testAgentsCanNotReachTheApprovalRoutes(): void
    {
        ToolDeriver::clearCache();
        $deriver = new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), null, static fn (string $m): null => null);
        $names   = array_column($deriver->deriveTools(), 'name');

        foreach (['get_api_me_agent_proposals', 'post_api_me_agent_proposals_id_approve', 'post_api_me_agent_proposals_id_decline', 'post_api_me_agent_proposals_id_identity_check'] as $name) {
            self::assertContains($name, $names);
            self::assertFalse($deriver->isExposed($name), "{$name} must not be on the MCP surface");
        }
    }

    public function testANeedsVerifyingProposalRunsOnlyOnceThePersonConfirmsItsThem(): void
    {
        $id    = $this->needsVerifying(['name' => 'Roles review']);
        $inbox = $this->gatedInbox();

        foreach (['', '{"code":""}', '{"code":"000000"}'] as $body) {
            $res = $inbox->approve($this->as(self::RANA, $body), ['id' => (string) $id]);
            self::assertSame(403, $res->getStatusCode());
            self::assertStringContainsString('identity_check_failed', $res->getBody());
        }
        self::assertSame([], $this->created, 'nothing runs before the check');
        self::assertSame('waiting', $this->stored($id, self::TENANT)['status']);

        $res = $inbox->approve($this->as(self::RANA, '{"code":"' . self::GOOD_CODE . '"}'), ['id' => (string) $id]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame([['name' => 'Roles review']], $this->created);
    }

    public function testWithoutTheCheckANeedsVerifyingProposalNeverRuns(): void
    {
        $id = $this->needsVerifying(['name' => 'Roles review']);

        $res = $this->inbox->approve($this->as(self::RANA, '{"code":"' . self::GOOD_CODE . '"}'), ['id' => (string) $id]);

        self::assertSame(503, $res->getStatusCode());
        self::assertSame([], $this->created);
        self::assertSame('waiting', $this->stored($id, self::TENANT)['status']);
    }

    public function testBulkApprovalNeverRunsANeedsVerifyingProposal(): void
    {
        $id = $this->needsVerifying(['name' => 'Roles review']);

        $res  = $this->gatedInbox()->bulkApprove($this->as(self::RANA, '{"ids":[' . $id . '],"code":"' . self::GOOD_CODE . '"}'));
        $data = json_decode($res->getBody(), true)['data'];

        self::assertSame([], $data['approved']);
        self::assertSame([['id' => $id, 'reason' => 'open_on_its_own']], $data['refused']);
        self::assertSame([], $this->created);
    }

    public function testOnlyANeedsVerifyingProposalStartsACheck(): void
    {
        $ordinary = $this->ask(['name' => 'Leave request']);
        $gated    = $this->needsVerifying(['name' => 'Roles review']);
        $inbox    = $this->gatedInbox();

        self::assertSame(422, $inbox->identityCheck($this->as(self::RANA), ['id' => (string) $ordinary])->getStatusCode());
        self::assertSame(404, $inbox->identityCheck($this->as(self::OMAR), ['id' => (string) $gated])->getStatusCode(), 'only the person it acts for');
    }

    public function testACodeForAnotherProposalDoesNotApproveThisOne(): void
    {
        $id = $this->needsVerifying(['name' => 'Roles review']);
        $this->goodPurpose = GatedApproval::purpose(GatedApproval::AGENT_PROPOSAL, (string) ($id + 1));

        $res = $this->gatedInbox()->approve($this->as(self::RANA, '{"code":"' . self::GOOD_CODE . '"}'), ['id' => (string) $id]);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame([], $this->created);
    }

    /**
     * A proposal that changes who can do what: Needs verifying (tier 3).
     *
     * @param array<string, mixed> $arguments
     */
    private function needsVerifying(array $arguments): int
    {
        return $this->proposals->create(
            self::TENANT,
            'jti-billing-assistant',
            self::RANA,
            'post_api_forms',
            $arguments,
            ['tools:call'],
            ['roles:manage'],
            'Change who can review forms',
        );
    }

    /** The inbox with the gate; a fixed code stands in for the person's check. */
    private function gatedInbox(): AgentProposalsApiHandler
    {
        $test = $this;
        $identity = new class ($this->pdo, $test) extends IdentityCheck {
            public function __construct(PDO $pdo, private readonly AgentProposalsRealEngineTest $test)
            {
                parent::__construct($pdo, new SigningChallengeRepository($pdo));
            }

            public function verify(int $tenantId, int $profileId, string $purpose, ?string $code): ?string
            {
                return $code === AgentProposalsRealEngineTest::GOOD_CODE && $this->test->purposeIsGood($purpose) ? self::TOTP : null;
            }
        };

        return new AgentProposalsApiHandler($this->proposals, $this->toolsCall, gate: new GatedApproval($identity));
    }

    /** @internal for the stand-in check */
    public function purposeIsGood(string $purpose): bool
    {
        return $this->goodPurpose === null
            ? str_starts_with($purpose, GatedApproval::AGENT_PROPOSAL . ':')
            : $purpose === $this->goodPurpose;
    }

    /** @return array<string, mixed> */
    private function stored(int $id, int $tenantId): array
    {
        $proposal = $this->proposals->find($id, $tenantId);
        self::assertIsArray($proposal);
        return $proposal;
    }

    /** @param array<string, mixed> $arguments */
    private function ask(array $arguments): int
    {
        $result = ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => $arguments], 'bearer');
        return (int) json_decode($result['content'][0]['text'], true)['proposal']['id'];
    }

    /**
     * A Whity Builder publish proposal with this impact plan, as the agent's
     * call would file it.
     *
     * @param array<string, mixed> $diff
     */
    private function plan(array $diff): int
    {
        return $this->proposals->create(
            self::TENANT,
            'jti-whity-builder',
            self::RANA,
            WorkspacePluginProposalDiffs::PUBLISH,
            ['manifest' => ['plugin' => ['name' => 'CakeOrders']]],
            ['tools:call'],
            ['plugins:publish'],
            'Publish a workspace plugin',
            diff: $diff,
        );
    }

    private function as(int $profileId, string $body = ''): Request
    {
        $request       = new Request('POST', '/api/me/agent-proposals', ['Content-Type' => 'application/json'], $body);
        $request->user = (object) ['profile_id' => $profileId];
        return $request;
    }
}
