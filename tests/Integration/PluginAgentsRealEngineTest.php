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
use Whity\Mcp\Tools\CoreToolsets;

/**
 * SDK 1.64 (WHIT-893): a plugin's declared agent reaches a workspace as a copy,
 * with its feature flags, on the real schema from migrations (SQLite, or
 * Postgres with PHPUNIT_PG_DSN). Tenant isolation: one workspace's agents never
 * show in another's, and a later declaration never changes an agent already made.
 */
final class PluginAgentsRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const CAFE   = 2;
    private const RANA   = 101;
    private const PLUGIN = 'Plugins\\Acme\\Collections\\CollectionsPlugin';

    private PDO $pdo;
    private AgentRepository $agents;
    private AgentPresetRegistry $presets;
    private AgentsApiHandler $api;
    private string $emptyDir;

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::BAKERY);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Rana', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([self::RANA]);
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->emptyDir = sys_get_temp_dir() . '/whity-plugin-agents-' . bin2hex(random_bytes(4));
        mkdir($this->emptyDir);

        $this->agents  = new AgentRepository($this->pdo);
        $this->presets = new AgentPresetRegistry($this->emptyDir);
        $this->api     = new AgentsApiHandler($this->agents, $this->presets);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAPluginAgentReachesAWorkspaceWithItsFeatureFlags(): void
    {
        $this->declare(1, ['suggestions' => true, 'proposals' => false]);

        $agent = $this->create(self::BAKERY, ['presetKey' => $this->key()]);

        self::assertSame($this->key(), $agent['preset']['key']);
        self::assertSame(['proposals' => false, 'suggestions' => true], $this->storedFeatures($agent['id']));
    }

    public function testAnotherWorkspaceSeesNoneOfThisWorkspacesAgents(): void
    {
        $this->declare(1, ['suggestions' => true]);
        $this->create(self::BAKERY, ['presetKey' => $this->key()]);

        $this->switchTo(self::CAFE);
        $list = json_decode((string) $this->api->list($this->requestFor([], 'GET', '/api/agents'))->getBody(), true)['data'];

        self::assertSame([], $list);
    }

    public function testEachWorkspaceGetsItsOwnCopyFromTheSameDeclaration(): void
    {
        $this->declare(1, ['suggestions' => true]);
        $bakery = $this->create(self::BAKERY, ['presetKey' => $this->key()]);
        $cafe   = $this->create(self::CAFE, ['presetKey' => $this->key()]);

        self::assertNotSame($bakery['id'], $cafe['id']);
        self::assertSame(['suggestions' => true], $this->storedFeatures($cafe['id'], self::CAFE));
    }

    public function testARedeclarationDoesNotChangeAnAgentAlreadyCreated(): void
    {
        $this->declare(1, ['suggestions' => true]);
        $made = $this->create(self::BAKERY, ['presetKey' => $this->key()]);

        $this->declare(2, ['suggestions' => false, 'proposals' => true]);

        self::assertSame(['suggestions' => true], $this->storedFeatures($made['id']));
        self::assertSame(1, $made['preset']['version']);
    }

    public function testAWorkspaceCannotChangeAnotherWorkspacesAgent(): void
    {
        $this->declare(1, ['suggestions' => true]);
        $made = $this->create(self::BAKERY, ['presetKey' => $this->key()]);

        self::assertFalse($this->agents->update($made['id'], self::CAFE, ['name' => 'Taken over'], self::RANA));
        self::assertFalse($this->agents->setStatus($made['id'], self::CAFE, 'archived', self::RANA));
    }

    public function testFeatureFlagsComeOnlyFromTheDeclarationNotTheRequest(): void
    {
        $this->declare(1, ['suggestions' => true]);

        $agent = $this->create(self::BAKERY, [
            'presetKey' => $this->key(),
            'features'  => ['proposals' => true, 'reference_documents' => true],
        ]);

        self::assertSame(['suggestions' => true], $this->storedFeatures($agent['id']));
    }

    public function testAnAgentFromScratchHasNoFeatureFlags(): void
    {
        $agent = $this->create(self::BAKERY, ['name' => 'Scratch', 'features' => ['proposals' => true]]);

        self::assertSame([], $this->storedFeatures($agent['id']));
    }

    /** @param array<string, bool> $features */
    private function declare(int $version, array $features): void
    {
        $refused = $this->presets->registerPluginAgents(self::PLUGIN, [[
            'key'          => 'clerk',
            'version'      => $version,
            'name'         => ['en' => 'Clerk', 'ar' => 'موظف'],
            'role'         => ['en' => 'Follows up invoices.', 'ar' => 'يتابع الفواتير.'],
            'instructions' => ['en' => 'Be brief.', 'ar' => 'كن موجزا.'],
            'toolsets'     => ['core.documents'],
            'grant'        => ['documents:read' => 'allow'],
            'features'     => $features,
        ]], static fn (string $p): bool => $p === 'documents:read');

        self::assertSame([], $refused);
    }

    /** The tenant context locks once set per request, so each switch resets it first. */
    private function switchTo(int $tenantId): void
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
    }

    private function key(): string
    {
        return CoreToolsets::pluginToolsetIdForClass(self::PLUGIN) . '.clerk';
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function create(int $tenantId, array $body): array
    {
        $this->switchTo($tenantId);
        $response = $this->api->create($this->requestFor($body));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->data($response);
    }

    /** @return array<string, bool> */
    private function storedFeatures(int $id, int $tenantId = self::BAKERY): array
    {
        $row = $this->agents->find($id, $tenantId);
        self::assertNotNull($row);

        // JSONB does not keep key order, so compare the flags sorted by name.
        $features = $row['features'];
        ksort($features);

        return $features;
    }

    /** @param array<string, mixed> $body */
    private function requestFor(array $body, string $method = 'POST', string $path = '/api/agents'): Request
    {
        $request       = new Request($method, $path, ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => self::RANA];
        return $request;
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded['data'] ?? null, (string) $response->getBody());
        return $decoded['data'];
    }
}
