<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AgentsApiHandler;
use Whity\Core\Agent\AgentPresetRegistry;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Workspace agents (WHIT-815): created from scratch or from a preset, edited,
 * archived and restored, always inside the caller's tenant, on the real
 * schema from migrations (SQLite, or Postgres with PHPUNIT_PG_DSN).
 */
final class AgentsApiRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const CAFE   = 2;
    private const RANA   = 101;

    private PDO $pdo;
    private AgentRepository $agents;
    private AgentsApiHandler $api;

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::BAKERY);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Rana', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([self::RANA]);
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->agents = new AgentRepository($this->pdo);
        $this->api    = new AgentsApiHandler($this->agents, new AgentPresetRegistry());
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testCreatingFromAPresetCopiesItAndRecordsWhereItCameFrom(): void
    {
        $response = $this->api->create($this->request(['presetKey' => 'core.finance-clerk']));

        self::assertSame(201, $response->getStatusCode());
        $agent = $this->data($response);
        self::assertSame('finance-clerk', $agent['slug']);
        self::assertSame('Finance clerk', $agent['name']);
        self::assertSame('موظف المالية', $agent['nameAr']);
        self::assertSame(['key' => 'core.finance-clerk', 'version' => 1], $agent['preset']);
        self::assertContains('core.billing', $agent['toolsets']);
        self::assertSame('ask', $agent['grant']['documents:issue']);
        self::assertSame('active', $agent['status']);

        $stored = $this->agents->find($agent['id'], self::BAKERY);
        self::assertIsArray($stored);
        self::assertSame(self::RANA, $stored['created_by_profile_id']);
    }

    public function testFieldsSentOverrideThePreset(): void
    {
        $agent = $this->data($this->api->create($this->request([
            'presetKey' => 'core.front-desk',
            'name'      => 'Bakery front desk',
            'nameAr'    => 'استقبال المخبز',
            'tone'      => 'plain',
        ])));

        self::assertSame('bakery-front-desk', $agent['slug']);
        self::assertSame('استقبال المخبز', $agent['nameAr']);
        self::assertSame('plain', $agent['tone']);
        self::assertSame(['key' => 'core.front-desk', 'version' => 1], $agent['preset']);
    }

    public function testSlugsStayUniqueInTheWorkspace(): void
    {
        $first  = $this->data($this->api->create($this->request(['presetKey' => 'core.hr-assistant'])));
        $second = $this->data($this->api->create($this->request(['presetKey' => 'core.hr-assistant'])));

        self::assertSame('hr-assistant', $first['slug']);
        self::assertSame('hr-assistant-2', $second['slug']);
        self::assertSame(409, $this->api->create($this->request(['name' => 'Payroll', 'slug' => 'hr-assistant']))->getStatusCode());
    }

    public function testAnArabicOnlyNameStillGetsASlug(): void
    {
        $agent = $this->data($this->api->create($this->request(['name' => 'مساعد المبيعات'])));

        self::assertSame('agent', $agent['slug']);
    }

    public function testInvalidFieldsAreRefusedWithReasons(): void
    {
        $response = $this->api->create($this->request([
            'name'     => '',
            'tone'     => 'cheerful',
            'toolsets' => ['core.nowhere'],
            'grant'    => ['documents:issue' => 'always'],
        ]));

        self::assertSame(422, $response->getStatusCode());
        $errors = json_decode((string) $response->getBody(), true)['errors'];
        self::assertCount(4, $errors);
        self::assertSame(422, $this->api->create($this->request(['presetKey' => 'core.nothing']))->getStatusCode());
        // Owner decision Q6: a workspace agent can't start a write Allowed either.
        self::assertSame(422, $this->api->create($this->request(['name' => 'Sender', 'grant' => ['documents:issue' => 'allow']]))->getStatusCode());
    }

    public function testEditingChangesOnlyWhatWasSent(): void
    {
        $agent = $this->data($this->api->create($this->request(['presetKey' => 'core.document-drafter'])));

        $response = $this->api->update(
            $this->request(['instructionsAr' => 'اكتب بالعربية أولًا.', 'grant' => ['documents:read' => 'allow', 'documents:write' => 'off']], 'PATCH'),
            ['id' => (string) $agent['id']],
        );

        self::assertSame(200, $response->getStatusCode());
        $edited = $this->data($response);
        self::assertSame('اكتب بالعربية أولًا.', $edited['instructionsAr']);
        self::assertSame($agent['instructions'], $edited['instructions']);
        self::assertSame(['documents:read' => 'allow', 'documents:write' => 'off'], $edited['grant']);
        self::assertSame(422, $this->api->update($this->request([], 'PATCH'), ['id' => (string) $agent['id']])->getStatusCode());
    }

    public function testArchivedAgentsLeaveTheListUntilRestored(): void
    {
        $agent = $this->data($this->api->create($this->request(['presetKey' => 'core.billing-assistant'])));

        self::assertSame('archived', $this->data($this->api->archive($this->request([]), ['id' => (string) $agent['id']]))['status']);
        self::assertSame([], $this->list(''));
        self::assertCount(1, $this->list('?archived=1'));

        $this->api->restore($this->request([]), ['id' => (string) $agent['id']]);
        self::assertCount(1, $this->list(''));
    }

    public function testAnotherWorkspacesAgentIsNotFound(): void
    {
        $agent = $this->data($this->api->create($this->request(['presetKey' => 'core.finance-clerk'])));

        TenantContext::reset();
        TenantContext::setTenantId(self::CAFE);
        $id = ['id' => (string) $agent['id']];
        self::assertSame(404, $this->api->get($this->request([]), $id)->getStatusCode());
        self::assertSame(404, $this->api->update($this->request(['name' => 'Taken over'], 'PATCH'), $id)->getStatusCode());
        self::assertSame(404, $this->api->archive($this->request([]), $id)->getStatusCode());
        self::assertSame([], $this->list(''));

        // And the cafe may use the same slug for its own agent.
        self::assertSame('finance-clerk', $this->data($this->api->create($this->request(['presetKey' => 'core.finance-clerk'])))['slug']);
    }

    public function testPresetsAreListedInBothLanguages(): void
    {
        $presets = json_decode((string) $this->api->presets($this->request([]))->getBody(), true)['data'];

        self::assertSame(
            ['core.billing-assistant', 'core.document-drafter', 'core.finance-clerk', 'core.front-desk', 'core.hr-assistant'],
            array_column($presets, 'key'),
        );
        foreach ($presets as $preset) {
            self::assertNotSame('', $preset['nameAr']);
            self::assertNotSame('', $preset['instructionsAr']);
        }
    }

    public function testAnUnauthenticatedCallerIsRefused(): void
    {
        $request = new Request('GET', '/api/agents', [], '');

        self::assertSame(401, $this->api->list($request)->getStatusCode());
    }

    /** @param array<mixed> $body */
    private function request(array $body, string $method = 'POST', string $path = '/api/agents'): Request
    {
        $request       = new Request($method, $path, ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => self::RANA];
        return $request;
    }

    /** @return list<array<string, mixed>> */
    private function list(string $query): array
    {
        return json_decode((string) $this->api->list($this->request([], 'GET', '/api/agents' . $query))->getBody(), true)['data'];
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded['data'] ?? null, (string) $response->getBody());
        return $decoded['data'];
    }
}
