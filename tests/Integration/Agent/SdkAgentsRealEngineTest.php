<?php

declare(strict_types=1);

namespace Tests\Integration\Agent;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AgentsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Agent\AgentActivityRepository;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentPresetRegistry;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Agent\SdkAgents;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Agents\Agents;
use Whity\Sdk\Hooks\Events;

/**
 * The SDK's Agents service (WHIT-990): a plugin reads the workspace's agents and
 * their activity as the person whose request it is, never more.
 *
 * Runs on real Postgres in CI (PHPUNIT_PG_DSN), SQLite locally.
 */
final class SdkAgentsRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const CAFE   = 2;
    private const RANA   = 10; // manages agents in the bakery
    private const OMAR   = 11; // a bakery member who uses one agent
    private const LINA   = 12; // the cafe

    private \PDO $pdo;
    private AgentRepository $agents;
    private AgentAssignmentRepository $assignments;
    private AgentProposalRepository $proposals;
    private int $clerk;
    private int $drafter;
    private int $cafeAgent;

    protected function setUp(): void
    {
        TenantContext::reset();
        AuditContext::reset();

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

        $this->clerk     = $this->agents->create(self::BAKERY, ['slug' => 'finance-clerk', 'name' => 'Finance clerk', 'name_ar' => 'موظف المالية', 'toolsets' => ['core.documents']], self::RANA);
        $this->drafter   = $this->agents->create(self::BAKERY, ['slug' => 'drafter', 'name' => 'Document drafter'], self::RANA);
        $this->cafeAgent = $this->agents->create(self::CAFE, ['slug' => 'front-desk', 'name' => 'Front desk'], self::LINA);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    public function testAManagerSeesEveryAgentInTheirWorkspaceAndNoOtherWorkspaces(): void
    {
        $this->as(self::BAKERY, self::RANA);
        $agents = $this->service(managers: [self::RANA]);

        $names = array_map(static fn (array $a): string => $a['name']['en'], $agents->list());
        self::assertSame(['Document drafter', 'Finance clerk'], $names);
        self::assertNull($agents->find($this->cafeAgent), "another workspace's agent is not there");

        $clerk = $agents->find($this->clerk);
        self::assertNotNull($clerk);
        self::assertSame(['en' => 'Finance clerk', 'ar' => 'موظف المالية'], $clerk['name']);
        self::assertSame(['core.documents'], $clerk['toolsets']);
        self::assertSame('active', $clerk['status']);
        self::assertFalse($clerk['paused']);
        self::assertArrayNotHasKey('instructions', $clerk);
    }

    public function testArchivedAgentsOnlyWhenAManagerAsks(): void
    {
        $this->agents->setStatus($this->drafter, self::BAKERY, AgentRepository::ARCHIVED, self::RANA);
        $this->as(self::BAKERY, self::RANA);
        $agents = $this->service(managers: [self::RANA]);

        self::assertCount(1, $agents->list());
        self::assertCount(2, $agents->list(includeArchived: true));
    }

    public function testSomebodyWhoDoesNotManageAgentsSeesOnlyTheAgentsTheyUse(): void
    {
        $this->assignments->assign($this->clerk, self::OMAR, self::BAKERY, []);
        $this->as(self::BAKERY, self::OMAR);
        $agents = $this->service(managers: [self::RANA]);

        self::assertSame([$this->clerk], array_column($agents->list(includeArchived: true), 'id'));
        self::assertNotNull($agents->find($this->clerk));
        self::assertNull($agents->find($this->drafter), 'an agent they do not use');
    }

    public function testActivityIsTheWorkspacesForAManagerAndTheirOwnForAnybodyElse(): void
    {
        $omars = $this->assignments->assign($this->clerk, self::OMAR, self::BAKERY, []);
        $ranas = $this->assignments->assign($this->clerk, self::RANA, self::BAKERY, []);
        $this->proposals->create(self::BAKERY, 'agent:' . $omars, self::OMAR, 'post_api_documents', ['title' => 'Omar private'], [], ['documents:write'], 'Create a document for Omar');
        $this->proposals->create(self::BAKERY, 'agent:' . $ranas, self::RANA, 'post_api_documents', ['title' => 'Rana private'], [], ['documents:write'], 'Create a document for Rana');
        $cafe = $this->assignments->assign($this->cafeAgent, self::LINA, self::CAFE, []);
        $this->proposals->create(self::CAFE, 'agent:' . $cafe, self::LINA, 'post_api_documents', [], [], [], 'Cafe');

        $this->as(self::BAKERY, self::RANA);
        $all = $this->service(managers: [self::RANA])->activity();
        self::assertSame(['Create a document for Omar', 'Create a document for Rana'], $this->summaries($all));
        self::assertSame('waiting', $all[0]['status']);
        self::assertSame('proposal', $all[0]['kind']);
        self::assertSame($this->clerk, $all[0]['agentId']);
        foreach ($all as $row) {
            self::assertArrayNotHasKey('arguments', $row, 'never the call arguments');
            self::assertStringNotContainsString('private', json_encode($row, JSON_THROW_ON_ERROR));
        }

        $this->as(self::BAKERY, self::OMAR);
        $own = $this->service(managers: [self::RANA])->activity();
        self::assertSame(['Create a document for Omar'], $this->summaries($own));
        self::assertSame([], $this->service(managers: [self::RANA])->activity(status: 'nonsense'));
    }

    public function testNoPersonOrNoWorkspaceReadsNothing(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $agents = $this->service(managers: [self::RANA]);
        self::assertSame([], $agents->list(), 'a job: no person');
        self::assertSame([], $agents->activity());
        self::assertNull($agents->find($this->clerk));

        TenantContext::reset();
        AuditContext::set(self::RANA, null);
        self::assertSame([], $agents->list(), 'no workspace');
    }

    public function testLifecycleEventsCarryIdsOnlyAndAListenerCannotUndoTheChange(): void
    {
        $hooks = new HookManager();
        $seen  = [];
        foreach ([Events::AGENT_CREATED, Events::AGENT_UPDATED, Events::AGENT_ARCHIVED, Events::AGENT_RESTORED, Events::AGENT_PAUSED, Events::AGENT_RESUMED] as $event) {
            $hooks->listen($event, static function (array $payload) use (&$seen, $event): array {
                $seen[] = [$event, $payload];
                throw new \RuntimeException('a listener that fails');
            });
        }
        $handler = new AgentsApiHandler($this->agents, new AgentPresetRegistry(), hooks: $hooks);
        TenantContext::setTenantId(self::BAKERY);
        $id = (string) $this->drafter;

        self::assertSame(200, $handler->pause($this->request(self::RANA), ['id' => $id])->getStatusCode());
        self::assertSame(200, $handler->resume($this->request(self::RANA), ['id' => $id])->getStatusCode());
        self::assertSame(200, $handler->archive($this->request(self::RANA), ['id' => $id])->getStatusCode());
        self::assertSame(200, $handler->restore($this->request(self::RANA), ['id' => $id])->getStatusCode());

        self::assertSame([Events::AGENT_PAUSED, Events::AGENT_RESUMED, Events::AGENT_ARCHIVED, Events::AGENT_RESTORED], array_column($seen, 0));
        self::assertSame(['tenant_id' => self::BAKERY, 'agent_id' => $this->drafter, 'actor_profile_id' => self::RANA], $seen[0][1]);
        self::assertSame(AgentRepository::ACTIVE, $this->agents->find($this->drafter, self::BAKERY)['status'] ?? null);
    }

    /** @param list<int> $managers */
    private function service(array $managers): Agents
    {
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            static fn (int $profileId, string $permission, int $tenantId): bool => $permission === 'agents:manage' && in_array($profileId, $managers, true),
        );

        return new SdkAgents($this->agents, $this->assignments, new AgentActivityRepository($this->pdo), $this->proposals, $roles);
    }

    private function as(int $tenantId, int $profileId): void
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        AuditContext::set($profileId, null);
    }

    private function request(int $profileId): Request
    {
        $request = new Request('POST', '/api/agents/x', ['Content-Type' => 'application/json'], '');
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function summaries(array $rows): array
    {
        $out = array_map(static fn (array $r): string => (string) $r['summary'], $rows);
        sort($out);

        return $out;
    }
}
