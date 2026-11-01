<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\MyAgentsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpTokenService;
use Whity\Sdk\Http\Response;

/**
 * A person's use of a workspace agent (WHIT-816): the grant starts clamped to
 * the person, lives on the server, applies at once to every credential, and
 * the agent is one stable principal, `agent:<assignmentId>`.
 */
final class AgentAssignmentsRealEngineTest extends TestCase
{
    private const JWT_SECRET = 'agent-assignments-test-secret-padded-32';
    private const TENANT     = 1;
    private const CAFE       = 2;
    private const RANA       = 10;
    private const OMAR       = 11;

    private const OPERATIONS = [
        ['permission' => 'documents:read',  'readOnly' => true,  'agentPolicy' => 'allow'],
        ['permission' => 'documents:write', 'readOnly' => false, 'agentPolicy' => 'allow'],
        ['permission' => 'billing:view',    'readOnly' => true,  'agentPolicy' => 'allow'],
    ];

    private \PDO $pdo;
    private JwtParser $jwt;
    private TokenValidator $validator;
    private AgentRepository $agents;
    private AgentAssignmentRepository $assignments;
    private MyAgentsApiHandler $api;
    private int $clerk;

    /** @var list<string> what the person holds */
    private array $held = ['documents:read', 'documents:write'];

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->jwt = new JwtParser(self::JWT_SECRET);
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT DO NOTHING");
        foreach ([self::RANA => 'Rana', self::OMAR => 'Omar'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
            $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, 1, 1, 'active', CURRENT_TIMESTAMP)")->execute([$id]);
        }
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->agents = new AgentRepository($this->pdo);
        $this->clerk  = $this->agents->create(self::TENANT, [
            'slug'     => 'finance-clerk',
            'name'     => 'Finance clerk',
            'name_ar'  => 'موظف المالية',
            'toolsets' => ['core.documents', 'core.billing'],
            // billing:view is a read Rana doesn't hold; documents:write is a write.
            'grant'    => ['billing:view' => 'allow', 'documents:read' => 'allow', 'documents:write' => 'ask'],
        ], self::RANA);

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            fn (int $profile, string $permission): bool => in_array($permission, $this->held, true),
        );

        $this->validator   = new TokenValidator($this->jwt, $this->pdo);
        $this->assignments = new AgentAssignmentRepository($this->pdo);
        $this->api         = new MyAgentsApiHandler(
            $this->agents,
            $this->assignments,
            new McpTokenService($this->pdo, $this->jwt),
            $roles,
            static fn (): array => self::OPERATIONS,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testTheGrantStartsFromTheTemplateClampedToThePerson(): void
    {
        $response = $this->api->assign($this->as(self::RANA), ['id' => (string) $this->clerk]);

        self::assertSame(201, $response->getStatusCode(), $response->getBody());
        $data = $this->data($response);
        self::assertSame('agent:' . $data['assignmentId'], $data['principalId']);
        $rows = array_column($data['permissions'], null, 'permission');
        self::assertSame(['documents:read', 'documents:write'], array_keys($rows), 'billing:view is not held, so it is gone');
        self::assertSame('allow', $rows['documents:read']['mode']);
        self::assertSame('ask', $rows['documents:write']['mode']);

        self::assertSame(409, $this->api->assign($this->as(self::RANA), ['id' => (string) $this->clerk])->getStatusCode());
    }

    public function testACredentialActsAsTheAgentWithTheAssignmentsGrant(): void
    {
        $assignmentId = $this->assign();
        $token        = $this->credential();

        $principal = $this->principal($token);

        self::assertSame(AgentScopeGrant::AGENT_KIND, $principal->principalKind);
        self::assertSame('agent:' . $assignmentId, $principal->agentPrincipalId());
        self::assertNotSame($principal->jti, $principal->agentPrincipalId(), 'the jti stays the credential\'s own');
        self::assertContains('toolset:core.documents', $principal->scope);
        self::assertSame('allow', AgentScopeGrant::of($principal)->modeFor('documents:read', true));
        self::assertSame('agent:' . $assignmentId, $principal->actor()->agentPrincipalId);
    }

    public function testAGrantChangeAppliesAtOnceToEveryCredential(): void
    {
        $this->assign();
        $first  = $this->credential();
        $second = $this->credential();

        $response = $this->api->updateGrant($this->as(self::RANA, '{"documents:read":"off"}', 'PATCH'), ['id' => (string) $this->clerk]);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        foreach ([$first, $second] as $token) {
            self::assertSame('off', AgentScopeGrant::of($this->principal($token))->modeFor('documents:read', true));
        }
    }

    public function testNothingAboveThePersonIsAccepted(): void
    {
        $this->assign();

        $notHeld = $this->api->updateGrant($this->as(self::RANA, '{"billing:view":"allow"}', 'PATCH'), ['id' => (string) $this->clerk]);
        $badMode = $this->api->updateGrant($this->as(self::RANA, '{"documents:read":"always"}', 'PATCH'), ['id' => (string) $this->clerk]);

        self::assertSame(422, $notHeld->getStatusCode());
        self::assertSame(422, $badMode->getStatusCode());
    }

    public function testStoppingRevokesEveryCredential(): void
    {
        $this->assign();
        $token = $this->credential();

        $response = $this->api->unassign($this->as(self::RANA, '', 'DELETE'), ['id' => (string) $this->clerk]);

        self::assertSame(1, $this->data($response)['revokedCredentials']);
        self::assertNull($this->validator->validateMcpToken($token));
        self::assertSame(404, $this->api->grant($this->as(self::RANA, '', 'GET'), ['id' => (string) $this->clerk])->getStatusCode());

        // Using it again brings the same agent back, with a fresh grant.
        $again = $this->data($this->api->assign($this->as(self::RANA), ['id' => (string) $this->clerk]));
        self::assertSame('agent:' . $again['assignmentId'], $again['principalId']);
    }

    public function testAnArchivedAgentsCredentialsAreRefused(): void
    {
        $this->assign();
        $token = $this->credential();

        $this->agents->setStatus($this->clerk, self::TENANT, AgentRepository::ARCHIVED, self::RANA);

        self::assertNull($this->validator->validateMcpToken($token));
        self::assertSame([], json_decode((string) $this->api->list($this->as(self::RANA, '', 'GET'))->getBody(), true)['data']);
    }

    public function testEachPersonHasTheirOwnAssignment(): void
    {
        $this->assign();

        $list = json_decode((string) $this->api->list($this->as(self::OMAR, '', 'GET'))->getBody(), true)['data'];
        self::assertNull($list[0]['assignment'], 'Omar does not use the agent Rana uses');
        self::assertSame(404, $this->api->grant($this->as(self::OMAR, '', 'GET'), ['id' => (string) $this->clerk])->getStatusCode());
        self::assertSame(404, $this->api->credential($this->as(self::OMAR), ['id' => (string) $this->clerk])->getStatusCode());
    }

    public function testAnotherWorkspacesAgentIsNotFound(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::CAFE);

        self::assertSame(404, $this->api->assign($this->as(self::RANA), ['id' => (string) $this->clerk])->getStatusCode());
    }

    public function testProposalsAndApprovalsKeyOnTheAssignment(): void
    {
        $assignmentId = $this->assign();
        $principal    = $this->principal($this->credential());
        $proposals    = new AgentProposalRepository($this->pdo);

        $id = $proposals->create(self::TENANT, $principal->agentPrincipalId(), self::RANA, 'post_api_documents', [], $principal->scope, ['documents:write'], 'Draft a letter');

        self::assertSame('agent:' . $assignmentId, $proposals->find($id, self::TENANT)['agent_principal_id'] ?? null);
        // Approval re-reads the assignment as it is now; revoked means nothing can run.
        self::assertNotNull($this->assignments->activeScope($assignmentId, self::TENANT, self::RANA));
        $this->assignments->revoke($assignmentId, self::TENANT, self::RANA);
        self::assertNull($this->assignments->activeScope($assignmentId, self::TENANT, self::RANA));
    }

    private function assign(): int
    {
        $response = $this->api->assign($this->as(self::RANA), ['id' => (string) $this->clerk]);
        self::assertSame(201, $response->getStatusCode(), $response->getBody());

        return $this->data($response)['assignmentId'];
    }

    private function credential(): string
    {
        $response = $this->api->credential($this->as(self::RANA, '{"name":"Claude Code"}'), ['id' => (string) $this->clerk]);
        self::assertSame(201, $response->getStatusCode(), $response->getBody());

        return $this->data($response)['token'];
    }

    private function principal(string $token): McpPrincipal
    {
        $principal = $this->validator->validateMcpToken($token);
        self::assertNotNull($principal);

        return $principal;
    }

    private function as(int $profileId, string $body = '', string $method = 'POST'): Request
    {
        $request       = new Request($method, '/api/me/agents', ['Content-Type' => 'application/json'], $body);
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded['data'] ?? null, $response->getBody());

        return $decoded['data'];
    }
}
