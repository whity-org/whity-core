<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use Database\Migrations\AddAgentPauseReadsAndLastActive;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AgentActivityApiHandler;
use Whity\Api\AgentsApiHandler;
use Whity\Api\MyAgentsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentActionRepository;
use Whity\Core\Agent\AgentActivityRepository;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentPresetRegistry;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Agent\AgentStatsRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Mcp\Auth\McpTokenService;
use Whity\Sdk\Http\Response;

/**
 * Pausing an agent, what the workspace sees of its agents, and what an agent may
 * read (WHIT-867, design 2.9 S6).
 *
 * Runs on real Postgres in CI (PHPUNIT_PG_DSN), which is the point of the tenant
 * cases: every query spans several tables, and only a real engine shows which
 * predicate is missing.
 */
final class AgentPauseAndActivityRealEngineTest extends TestCase
{
    private const JWT_SECRET = 'agent-pause-activity-test-secret-pad-32';
    private const BAKERY     = 1;
    private const CAFE       = 2;
    private const RANA       = 10;
    private const OMAR       = 11;
    private const LINA       = 12;

    private const OPERATIONS = [
        ['permission' => 'documents:read',  'readOnly' => true,  'agentPolicy' => 'allow', 'toolset' => 'core.documents'],
        ['permission' => 'documents:write', 'readOnly' => false, 'agentPolicy' => 'allow', 'toolset' => 'core.documents'],
        ['permission' => 'users:delete',    'readOnly' => false, 'agentPolicy' => 'allow', 'toolset' => 'core.people'],
        ['permission' => 'agents:manage',   'readOnly' => false, 'agentPolicy' => 'refuse', 'toolset' => 'core.documents'],
    ];

    private \PDO $pdo;
    private TokenValidator $validator;
    private AgentRepository $agents;
    private AgentAssignmentRepository $assignments;
    private AgentProposalRepository $proposals;
    private AgentActionRepository $actions;
    private AgentStatsRepository $stats;
    private AgentsApiHandler $admin;
    private MyAgentsApiHandler $mine;
    private AgentActivityApiHandler $activity;
    private int $clerk;
    private int $drafter;
    private int $cafeAgent;

    /** @var array<int, list<int>> document source ids each person can read */
    private array $readable = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::BAKERY);

        $jwt       = new JwtParser(self::JWT_SECRET);
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

        $this->agents      = new AgentRepository($this->pdo);
        $this->assignments = new AgentAssignmentRepository($this->pdo);
        $this->proposals   = new AgentProposalRepository($this->pdo);
        $this->actions     = new AgentActionRepository($this->pdo);
        $this->stats       = new AgentStatsRepository($this->pdo);
        $this->validator   = new TokenValidator($jwt, $this->pdo);

        $this->clerk     = $this->agents->create(self::BAKERY, ['slug' => 'finance-clerk', 'name' => 'Finance clerk', 'name_ar' => 'موظف المالية', 'toolsets' => ['core.documents'], 'grant' => ['documents:read' => 'allow']], self::RANA);
        $this->drafter   = $this->agents->create(self::BAKERY, ['slug' => 'drafter', 'name' => 'Document drafter'], self::RANA);
        $this->cafeAgent = $this->agents->create(self::CAFE, ['slug' => 'front-desk', 'name' => 'Front desk'], self::LINA);

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);

        $operations = static fn (): array => self::OPERATIONS;
        $readable   = fn (int $id, int $tenantId, int $profileId): ?array => in_array($id, $this->readable[$profileId] ?? [], true)
            ? ['id' => $id, 'title' => 'Reference ' . $id, 'kind' => 'wiki']
            : null;
        $today = static fn (int $tenantId): string => gmdate('Y-m-d 00:00:00');

        $this->admin    = new AgentsApiHandler($this->agents, new AgentPresetRegistry(), null, $this->stats, $today, $operations, $readable);
        $this->mine     = new MyAgentsApiHandler($this->agents, $this->assignments, new McpTokenService($this->pdo, $jwt), $roles, $operations, null, $this->stats, $today, $readable);
        $this->activity = new AgentActivityApiHandler(new AgentActivityRepository($this->pdo), $this->proposals, static fn (): array => [
            'post_api_documents' => ['plainName' => 'Create a document', 'plainNameAr' => 'إنشاء مستند', 'area' => ['id' => 'core.documents', 'title' => 'Documents', 'titleAr' => 'المستندات'], 'agentAccess' => 'proposes', 'readOnly' => false, 'undoWindowSeconds' => null],
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        unset($_GET['agent'], $_GET['status'], $_GET['limit'], $_GET['offset']);
    }

    // ── Pausing ─────────────────────────────────────────────────────────────

    public function testAPausedAgentsCredentialIsRefusedUntilItIsResumed(): void
    {
        $this->use($this->clerk, self::RANA);
        $token = $this->credential($this->clerk, self::RANA);
        self::assertNotNull($this->validator->validateMcpToken($token), 'works before the pause');

        $paused = $this->ok($this->admin->pause($this->as(self::RANA), ['id' => (string) $this->clerk]));
        self::assertTrue($paused['paused']);
        self::assertSame('Rana', $paused['pausedBy']);

        self::assertNull($this->validator->validateMcpToken($token), 'refused on the very next call, nothing cached');

        self::assertFalse($this->ok($this->admin->resume($this->as(self::RANA), ['id' => (string) $this->clerk]))['paused']);
        self::assertNotNull($this->validator->validateMcpToken($token), 'resuming puts it back: nothing was revoked');
    }

    public function testAPausedAgentGetsNoNewCredentialAndNoPersona(): void
    {
        $assignment = $this->use($this->clerk, self::RANA);
        $this->agents->setPaused($this->clerk, self::BAKERY, true, self::RANA);

        $response = $this->mine->credential($this->as(self::RANA, '{}'), ['id' => (string) $this->clerk]);
        self::assertSame(409, $response->getStatusCode());
        self::assertNull($this->assignments->personaFor(AgentAssignmentRepository::principalId($assignment), self::BAKERY, $this->agents));
        self::assertNull($this->assignments->activeScope($assignment, self::BAKERY, self::RANA));
        self::assertNull($this->assignments->activeScopeBySlug('finance-clerk', self::BAKERY, self::RANA));
    }

    public function testPausingAndArchivingAreSeparateDecisions(): void
    {
        $this->agents->setPaused($this->clerk, self::BAKERY, true, self::RANA);
        $this->agents->setStatus($this->clerk, self::BAKERY, AgentRepository::ARCHIVED, self::RANA);
        $this->agents->setStatus($this->clerk, self::BAKERY, AgentRepository::ACTIVE, self::RANA);

        self::assertNotNull($this->agents->find($this->clerk, self::BAKERY)['paused_at'] ?? null, "restoring doesn't resume");
    }

    public function testPausingAgainKeepsWhoPausedIt(): void
    {
        $this->agents->setPaused($this->clerk, self::BAKERY, true, self::RANA);
        $this->agents->setPaused($this->clerk, self::BAKERY, true, self::OMAR);

        self::assertSame(self::RANA, $this->agents->find($this->clerk, self::BAKERY)['paused_by_profile_id'] ?? null);
    }

    public function testAnotherWorkspacesAgentCannotBePaused(): void
    {
        self::assertSame(404, $this->admin->pause($this->as(self::RANA), ['id' => (string) $this->cafeAgent])->getStatusCode());
        self::assertSame(404, $this->admin->resume($this->as(self::RANA), ['id' => (string) $this->cafeAgent])->getStatusCode());
        self::assertNull($this->agents->find($this->cafeAgent, self::CAFE)['paused_at'] ?? null);
    }

    // ── Counts and last active ──────────────────────────────────────────────

    public function testCountsAndWhoItWorksForAreTheWorkspacesOwn(): void
    {
        $rana = AgentAssignmentRepository::principalId($this->use($this->clerk, self::RANA));
        $omar = AgentAssignmentRepository::principalId($this->use($this->clerk, self::OMAR));
        $this->action(self::BAKERY, $rana, self::RANA);
        $this->action(self::BAKERY, $omar, self::OMAR);
        $this->proposal(self::BAKERY, $rana, self::RANA);

        // The cafe's agent is busy too, and must not show here.
        $lina = AgentAssignmentRepository::principalId($this->assignments->assign($this->cafeAgent, self::LINA, self::CAFE, []));
        $this->action(self::CAFE, $lina, self::LINA);
        $this->proposal(self::CAFE, $lina, self::LINA);

        $agents = $this->byName($this->list($this->admin->list($this->as(self::RANA, '', 'GET'))));
        self::assertSame(['Document drafter', 'Finance clerk'], array_keys($agents));

        $clerk = $agents['Finance clerk'];
        self::assertSame(['Omar', 'Rana'], array_column($clerk['worksFor'], 'name'));
        self::assertSame(2, $clerk['actionsToday']);
        self::assertSame(1, $clerk['proposalsToday']);
        self::assertSame(1, $clerk['waiting']);
        self::assertNotNull($clerk['lastActiveAt']);

        self::assertSame([], $agents['Document drafter']['worksFor']);
        self::assertSame(0, $agents['Document drafter']['actionsToday']);
        self::assertNull($agents['Document drafter']['lastActiveAt']);
    }

    public function testAPersonSeesOnlyTheirOwnUse(): void
    {
        $rana = AgentAssignmentRepository::principalId($this->use($this->clerk, self::RANA));
        $omar = AgentAssignmentRepository::principalId($this->use($this->clerk, self::OMAR));
        $this->action(self::BAKERY, $rana, self::RANA);
        $this->action(self::BAKERY, $omar, self::OMAR);
        $this->action(self::BAKERY, $omar, self::OMAR);

        $agents = $this->byName($this->list($this->mine->list($this->as(self::RANA, '', 'GET'))));

        self::assertSame(1, $agents['Finance clerk']['actionsToday']);
        self::assertArrayNotHasKey('worksFor', $agents['Finance clerk'], 'a member is not told who else uses it');
    }

    public function testOnItsOwnFollowsTheGrantAndTheCeilings(): void
    {
        $this->use($this->clerk, self::RANA);

        $agent = $this->byName($this->list($this->mine->list($this->as(self::RANA, '', 'GET'))))['Finance clerk'];

        // In core.documents: the read runs, the write asks (no undo), and the
        // refused operation is off. users:delete is outside its toolsets.
        self::assertSame(['allowed' => 1, 'askFirst' => 1, 'off' => 1], $agent['onItsOwn']);
    }

    public function testLastActiveIsThrottledAndCountsReads(): void
    {
        $assignment = $this->use($this->clerk, self::RANA);
        $principal  = AgentAssignmentRepository::principalId($assignment);
        $at         = new \DateTimeImmutable('2026-11-03 09:00:00', new \DateTimeZone('UTC'));

        $this->assignments->recordActivity($principal, self::BAKERY, $at);
        $this->assignments->recordActivity($principal, self::BAKERY, $at->modify('+30 seconds'));
        self::assertSame('2026-11-03 09:00:00', $this->lastActive($assignment), 'within the minute: no write');

        $this->assignments->recordActivity($principal, self::BAKERY, $at->modify('+61 seconds'));
        self::assertSame('2026-11-03 09:01:01', $this->lastActive($assignment));

        // The wrong workspace, and a credential with no assignment, change nothing.
        $this->assignments->recordActivity($principal, self::CAFE, $at->modify('+1 hour'));
        $this->assignments->recordActivity('jti-abc', self::BAKERY, $at->modify('+1 hour'));
        self::assertSame('2026-11-03 09:01:01', $this->lastActive($assignment));
    }

    public function testTodayStartsAtMidnightInTheWorkspacesZone(): void
    {
        $now = new \DateTimeImmutable('2026-11-03 22:30:00', new \DateTimeZone('UTC'));

        // 00:30 on the 4th in Amman (UTC+3), so today began at 21:00 UTC.
        self::assertSame('2026-11-03 21:00:00', AgentStatsRepository::todayStart(new \DateTimeZone('Asia/Amman'), $now));
        self::assertSame('2026-11-03 00:00:00', AgentStatsRepository::todayStart(new \DateTimeZone('UTC'), $now));
    }

    // ── Admin activity ──────────────────────────────────────────────────────

    public function testActivityNeverShowsAnotherWorkspace(): void
    {
        $rana = AgentAssignmentRepository::principalId($this->use($this->clerk, self::RANA));
        $this->action(self::BAKERY, $rana, self::RANA);

        $lina = AgentAssignmentRepository::principalId($this->assignments->assign($this->cafeAgent, self::LINA, self::CAFE, []));
        $this->action(self::CAFE, $lina, self::LINA);
        $this->proposal(self::CAFE, $lina, self::LINA);

        // A principal id the cafe used, recorded under the bakery by mistake, must
        // not borrow the cafe agent's name either.
        $this->action(self::BAKERY, $lina, self::RANA);

        $rows = $this->list($this->activity->list($this->as(self::RANA, '', 'GET')));

        self::assertCount(2, $rows);
        self::assertNotContains('Lina', array_column(array_column($rows, 'person'), 'name'));
        self::assertNotContains('Front desk', array_column(array_column($rows, 'agent'), 'name'));

        $_GET['agent'] = (string) $this->cafeAgent;
        self::assertSame([], $this->list($this->activity->list($this->as(self::RANA, '', 'GET'))), "another workspace's agent id finds nothing");
    }

    public function testActivityFiltersByAgentAndStatus(): void
    {
        $clerk   = AgentAssignmentRepository::principalId($this->use($this->clerk, self::RANA));
        $drafter = AgentAssignmentRepository::principalId($this->use($this->drafter, self::OMAR));
        $this->action(self::BAKERY, $clerk, self::RANA);
        $this->action(self::BAKERY, $drafter, self::OMAR);
        $this->proposal(self::BAKERY, $drafter, self::OMAR);

        $_GET['agent'] = (string) $this->clerk;
        $rows = $this->list($this->activity->list($this->as(self::RANA, '', 'GET')));
        self::assertSame(['Finance clerk'], array_values(array_unique(array_column(array_column($rows, 'agent'), 'name'))));
        self::assertSame(['Rana'], array_column(array_column($rows, 'person'), 'name'));

        unset($_GET['agent']);
        $_GET['status'] = 'waiting';
        $rows = $this->list($this->activity->list($this->as(self::RANA, '', 'GET')));
        self::assertSame(['Document drafter'], array_column(array_column($rows, 'agent'), 'name'));
        self::assertSame('proposal', $rows[0]['kind']);
    }

    public function testAProposalThatRanAppearsOnceAsItsAction(): void
    {
        $principal = AgentAssignmentRepository::principalId($this->use($this->clerk, self::RANA));
        $ran       = $this->proposal(self::BAKERY, $principal, self::RANA);
        $this->proposals->decide($ran, self::BAKERY, self::RANA, AgentProposalRepository::APPROVED);
        $this->actions->record(self::BAKERY, $principal, self::RANA, $ran, 'post_api_documents', [], 'Create a document', ['documents:write'], null, null);

        // Approved, and a check refused it at run time: no action row.
        $refused = $this->proposal(self::BAKERY, $principal, self::RANA);
        $this->proposals->decide($refused, self::BAKERY, self::RANA, AgentProposalRepository::APPROVED);

        $declined = $this->proposal(self::BAKERY, $principal, self::RANA);
        $this->proposals->decide($declined, self::BAKERY, self::RANA, AgentProposalRepository::DECLINED);

        $rows = $this->list($this->activity->list($this->as(self::RANA, '', 'GET')));

        self::assertEqualsCanonicalizing(['done', 'failed', 'declined'], array_column($rows, 'status'));
        self::assertCount(3, $rows);
    }

    public function testActivityNamesThingsAndLeavesOutTheArguments(): void
    {
        $principal = AgentAssignmentRepository::principalId($this->use($this->clerk, self::RANA));
        $this->actions->record(self::BAKERY, $principal, self::RANA, null, 'post_api_documents', ['salary' => 1234], 'Create a document', ['documents:write'], null, null);

        $response = $this->activity->list($this->as(self::RANA, '', 'GET'));
        $row      = $this->list($response)[0];

        self::assertSame(['id' => $this->clerk, 'name' => 'Finance clerk', 'nameAr' => 'موظف المالية'], $row['agent']);
        self::assertSame('Rana', $row['person']['name']);
        self::assertSame('إنشاء مستند', $row['toolInfo']['plainNameAr']);
        self::assertArrayNotHasKey('arguments', $row);
        self::assertStringNotContainsString('1234', $response->getBody());
    }

    public function testACredentialWithNoWorkspaceAgentGoesByItsOwnName(): void
    {
        $this->pdo->prepare("INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
                             VALUES ('jti-old', ?, ?, 'Old assistant', 'ai-agent', '[]', '2099-01-01 00:00:00', CURRENT_TIMESTAMP)")->execute([self::RANA, self::BAKERY]);
        $this->action(self::BAKERY, 'jti-old', self::RANA);

        $row = $this->list($this->activity->list($this->as(self::RANA, '', 'GET')))[0];

        self::assertSame(['id' => null, 'name' => 'Old assistant', 'nameAr' => null], $row['agent']);
    }

    public function testActivityPages(): void
    {
        $principal = AgentAssignmentRepository::principalId($this->use($this->clerk, self::RANA));
        for ($i = 0; $i < 3; $i++) {
            $this->action(self::BAKERY, $principal, self::RANA);
        }

        $_GET['limit'] = '2';
        $page = json_decode($this->activity->list($this->as(self::RANA, '', 'GET'))->getBody(), true);
        self::assertCount(2, $page['data']);
        self::assertTrue($page['hasMore']);

        $_GET['offset'] = '2';
        $page = json_decode($this->activity->list($this->as(self::RANA, '', 'GET'))->getBody(), true);
        self::assertCount(1, $page['data']);
        self::assertFalse($page['hasMore']);
    }

    public function testAPersonsOwnActivityFiltersByAgentAndStaysTheirOwn(): void
    {
        $clerk   = AgentAssignmentRepository::principalId($this->use($this->clerk, self::RANA));
        $drafter = AgentAssignmentRepository::principalId($this->use($this->drafter, self::RANA));
        $omars   = AgentAssignmentRepository::principalId($this->use($this->clerk, self::OMAR));
        $this->action(self::BAKERY, $clerk, self::RANA);
        $this->action(self::BAKERY, $drafter, self::RANA);
        $this->action(self::BAKERY, $omars, self::OMAR);

        self::assertCount(1, $this->actions->listFor(self::BAKERY, self::RANA, principalId: $clerk));
        self::assertSame([], $this->actions->listFor(self::BAKERY, self::RANA, principalId: $omars), "someone else's principal finds nothing");

        $this->proposal(self::BAKERY, $omars, self::OMAR);
        self::assertSame([], $this->proposals->listFor(self::BAKERY, self::RANA, principalId: $omars));
    }

    // ── What it may read ────────────────────────────────────────────────────

    public function testOnlyWhatTheEditorCanReadCanBeKeptForReference(): void
    {
        $this->readable = [self::RANA => [5]];

        $saved = $this->ok($this->admin->update($this->as(self::RANA, '{"mayRead":[5]}', 'PATCH'), ['id' => (string) $this->clerk]));
        self::assertSame([['id' => 5, 'title' => 'Reference 5', 'kind' => 'wiki', 'readable' => true]], $saved['mayRead']);

        $refused = $this->admin->update($this->as(self::RANA, '{"mayRead":[5,6]}', 'PATCH'), ['id' => (string) $this->clerk]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame([5], $this->mayRead());

        self::assertSame(422, $this->admin->update($this->as(self::RANA, '{"mayRead":"all"}', 'PATCH'), ['id' => (string) $this->clerk])->getStatusCode());
    }

    public function testAViewerIsNeverToldTheNameOfADocumentTheyCantRead(): void
    {
        $this->readable = [self::RANA => [5, 6], self::OMAR => [5]];
        $this->agents->update($this->clerk, self::BAKERY, ['may_read' => [5, 6]], self::RANA);
        $this->use($this->clerk, self::OMAR);

        $admin = $this->byName($this->list($this->admin->list($this->as(self::OMAR, '', 'GET'))))['Finance clerk'];
        self::assertSame([
            ['id' => 5, 'title' => 'Reference 5', 'kind' => 'wiki', 'readable' => true],
            ['id' => 6, 'title' => null, 'kind' => null, 'readable' => false],
        ], $admin['mayRead']);

        $mine = $this->byName($this->list($this->mine->list($this->as(self::OMAR, '', 'GET'))))['Finance clerk'];
        self::assertSame([['id' => 5, 'title' => 'Reference 5', 'kind' => 'wiki']], $mine['mayRead'], 'and a member sees only what they can read');
    }

    public function testADocumentAnEditorCantReadStaysWhenTheyEditTheRest(): void
    {
        $this->readable = [self::RANA => [5, 6], self::OMAR => [5, 7]];
        $this->agents->update($this->clerk, self::BAKERY, ['may_read' => [5, 6]], self::RANA);

        // Omar can't read 6, and sends back what the page sent him.
        $this->ok($this->admin->update($this->as(self::OMAR, '{"mayRead":[5,6],"role":"Prepares invoices"}', 'PATCH'), ['id' => (string) $this->clerk]));
        self::assertSame([5, 6], $this->mayRead());

        // He can add what he can read, and remove what he can't.
        $this->ok($this->admin->update($this->as(self::OMAR, '{"mayRead":[5,7]}', 'PATCH'), ['id' => (string) $this->clerk]));
        self::assertSame([5, 7], $this->mayRead());

        // But once removed, he can't put it back.
        self::assertSame(422, $this->admin->update($this->as(self::OMAR, '{"mayRead":[5,6,7]}', 'PATCH'), ['id' => (string) $this->clerk])->getStatusCode());
    }

    public function testTheAreasAreNamedInBothLanguages(): void
    {
        $areas = [];
        foreach ($this->list($this->mine->toolsets($this->as(self::RANA, '', 'GET'))) as $area) {
            $areas[$area['id']] = $area;
        }

        self::assertSame('People and roles', $areas['core.people']['title']);
        self::assertSame('الأشخاص والأدوار', $areas['core.people']['titleAr']);
    }

    // ── Presets ─────────────────────────────────────────────────────────────

    public function testPresetsSayWhatTheyDoAndCountOnlyThisWorkspace(): void
    {
        $this->agents->create(self::BAKERY, ['slug' => 'clerk-2', 'name' => 'Clerk'], self::RANA, 'core.finance-clerk', 1);
        $this->agents->create(self::CAFE, ['slug' => 'clerk-3', 'name' => 'Clerk'], self::LINA, 'core.finance-clerk', 1);

        $presets = [];
        foreach ($this->list($this->admin->presets($this->as(self::RANA, '', 'GET'))) as $preset) {
            $presets[$preset['key']] = $preset;
        }

        self::assertSame(1, $presets['core.finance-clerk']['agentsFromIt'], "another workspace's use isn't counted");
        self::assertSame(0, $presets['core.hr-assistant']['agentsFromIt']);
        foreach ($presets as $preset) {
            self::assertCount(3, $preset['does']);
            self::assertCount(3, $preset['doesAr']);
            self::assertNull($preset['needs']);
            self::assertNotNull($preset['icon']);
        }
    }

    // ── Migration ───────────────────────────────────────────────────────────

    public function testTheMigrationReverses(): void
    {
        if (getenv('PHPUNIT_PG_DSN') === false || getenv('PHPUNIT_PG_DSN') === '') {
            self::markTestSkipped('DROP COLUMN IF EXISTS needs Postgres');
        }
        require_once dirname(__DIR__, 3) . '/database/migrations/202_add_agent_pause_reads_and_last_active.php';
        $pdo = $this->pdo;
        $db  = Database::withFactory(static fn (): \PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        $has = function (string $table, string $column): bool {
            $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?');
            $stmt->execute([$table, $column]);

            return $stmt->fetchColumn() !== false;
        };
        $index = function (string $name): bool {
            $stmt = $this->pdo->prepare('SELECT 1 FROM pg_indexes WHERE indexname = ?');
            $stmt->execute([$name]);

            return $stmt->fetchColumn() !== false;
        };

        AddAgentPauseReadsAndLastActive::down($db);
        foreach ([['agents', 'paused_at'], ['agents', 'paused_by_profile_id'], ['agents', 'may_read'], ['agent_assignments', 'last_active_at']] as [$table, $column]) {
            self::assertFalse($has($table, $column), "{$table}.{$column} is gone");
        }
        self::assertFalse($index('idx_agent_actions_principal'));
        self::assertFalse($index('idx_agent_proposals_principal'));
        self::assertFalse($index('idx_agent_assignments_agent'));

        AddAgentPauseReadsAndLastActive::up($db);
        AddAgentPauseReadsAndLastActive::up($db);
        self::assertTrue($has('agents', 'paused_at'), 'and comes back, twice over');
        self::assertTrue($index('idx_agent_actions_principal'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function use(int $agentId, int $profileId): int
    {
        return $this->assignments->assign($agentId, $profileId, self::BAKERY, []);
    }

    private function credential(int $agentId, int $profileId): string
    {
        $response = $this->mine->credential($this->as($profileId, '{}'), ['id' => (string) $agentId]);
        self::assertSame(201, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['data']['token'];
    }

    private function proposal(int $tenantId, string $principal, int $profileId): int
    {
        return $this->proposals->create($tenantId, $principal, $profileId, 'post_api_documents', [], [], ['documents:write'], 'Create a document');
    }

    private function action(int $tenantId, string $principal, int $profileId): int
    {
        return $this->actions->record($tenantId, $principal, $profileId, null, 'post_api_documents', [], 'Create a document', ['documents:write'], null, null);
    }

    /** @return list<int>|null */
    private function mayRead(): ?array
    {
        return $this->agents->find($this->clerk, self::BAKERY)['may_read'] ?? null;
    }

    /** @phpstan-impure Reads the row the calls before it just wrote. */
    private function lastActive(int $assignmentId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT last_active_at FROM agent_assignments WHERE id = ?');
        $stmt->execute([$assignmentId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? substr($value, 0, 19) : null;
    }

    private function as(int $profileId, string $body = '', string $method = 'POST'): Request
    {
        $request       = new Request($method, '/api/agents', ['Content-Type' => 'application/json'], $body);
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /** @return array<string, mixed> */
    private function ok(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['data'];
    }

    /** @return list<array<string, mixed>> */
    private function list(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['data'];
    }

    /**
     * @param list<array<string, mixed>> $agents
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $agents): array
    {
        $out = [];
        foreach ($agents as $agent) {
            $out[$agent['name']] = $agent;
        }

        return $out;
    }
}
