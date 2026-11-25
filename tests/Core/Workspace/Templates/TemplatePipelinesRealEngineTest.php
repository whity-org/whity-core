<?php

declare(strict_types=1);

namespace Tests\Core\Workspace\Templates;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExampleClinicTemplates as Clinic;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentRouteTemplatesApiHandler;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Setup\WorkspaceSetup;
use Whity\Sdk\Templates\MemoryLedger;
use Whity\Sdk\Templates\TemplateEngine;
use Whity\Sdk\Testing\FakeWorkspace;

/**
 * WHIT-1419 (SDK 1.172): the review routes a template's pipelines become are
 * ones core's own route-template API accepts and stores, on SQLite and
 * PostgreSQL. The route-template routes are core's real handler, graph
 * validator and rule registry, wired as public/index.php wires them; the rest
 * of the workspace is the SDK's FakeWorkspace.
 */
final class TemplatePipelinesRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const AMRO = 7;

    private PDO $pdo;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-clinic')");
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([self::AMRO]);
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testCoreAcceptsAndStoresTheRouteAPipelineDraws(): void
    {
        $workspace = new FakeWorkspace();
        $setup = $this->withRealRouteTemplates($workspace);
        $engine = new TemplateEngine($setup, new MemoryLedger(), static fn (): bool => true);

        $result = $engine->apply(self::TENANT, self::AMRO, Clinic::clinicWithReview(), false);

        self::assertSame([], $result['skipped']);
        self::assertSame(1, $result['made']['pipelines'] ?? null);
        $route = $this->rows('SELECT id, name, description FROM document_route_templates WHERE tenant_id = 1');
        self::assertCount(1, $route);
        self::assertSame('Intake review', $route[0]['name']);
        self::assertSame('Reception checks each intake, then a doctor approves it.', $route[0]['description']);

        $steps = $this->rows('SELECT position, rule_kind, rule_config, label, decision, allow_send_back FROM document_route_template_steps WHERE tenant_id = 1 ORDER BY position');
        self::assertSame(['1', '2'], array_map('strval', array_column($steps, 'position')));
        self::assertSame(['role', 'role'], array_column($steps, 'rule_kind'));
        self::assertSame(['Reception checks', 'Doctor approves'], array_column($steps, 'label'));
        self::assertTrue((bool) $steps[0]['decision']);
        self::assertTrue((bool) $steps[0]['allow_send_back']);
        self::assertFalse((bool) $steps[1]['allow_send_back']);
        $roleIds = array_column(array_values($workspace->roles), 'id', 'name');
        self::assertSame(['role_id' => $roleIds['Doctor']], json_decode((string) $steps[1]['rule_config'], true));
        self::assertSame((int) $route[0]['id'], array_values($workspace->forms)[0]['route_template_id']);

        $again = $engine->apply(self::TENANT, self::AMRO, Clinic::clinicWithReview(), false);
        self::assertSame([], $again['made']);
        self::assertCount(1, $this->rows('SELECT id FROM document_route_templates WHERE tenant_id = 1'), 'Applying again makes no second route');
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Core's route-template routes, as the person; everything else from the fake. */
    private function withRealRouteTemplates(FakeWorkspace $workspace): WorkspaceSetup
    {
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $rules = new RoutingRuleRegistry();
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver($this->pdo, new UserGroupRepository($this->pdo), static fn (): RoutingRuleRegistry => $rules)),
        );
        $handler = new DocumentRouteTemplatesApiHandler(new RouteTemplateRepository($this->pdo), new RouteTemplateGraph($rules), $settings);

        return new class ($workspace, $handler, self::AMRO) implements WorkspaceSetup {
            public function __construct(
                private readonly FakeWorkspace $workspace,
                private readonly DocumentRouteTemplatesApiHandler $handler,
                private readonly int $profileId,
            ) {
            }

            public function call(string $method, string $path, array $body = []): array
            {
                $request = new Request($method, $path, ['content-type' => 'application/json'], (string) json_encode($body));
                $request->user = (object) ['profile_id' => $this->profileId];
                if ($method === 'POST' && $path === '/api/document-route-templates') {
                    return self::answer($this->handler->create($request));
                }
                if ($method === 'PUT' && preg_match('#^/api/document-route-templates/(\d+)/graph$#', $path, $m) === 1) {
                    return self::answer($this->handler->replaceGraph($request, ['id' => $m[1]]));
                }

                return $this->workspace->call($method, $path, $body);
            }

            /** @return array{status: int, body: mixed} */
            private static function answer(Response $response): array
            {
                return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getBody(), true)];
            }
        };
    }
}
