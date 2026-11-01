<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AgentActionsApiHandler;
use Whity\Api\FormsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentActionRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\PrefillResolver;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * The undo the form routes declare (WHIT-812), against the real handler and
 * repository: creating a form is undone by archiving the draft, and editing it
 * by restoring what it replaced.
 */
final class FormsUndoRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;

    private PDO $pdo;
    private ToolsCallHandler $toolsCall;
    private AgentActionsApiHandler $undoApi;

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                          VALUES (101, 'Rana', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($this->pdo);

        $fields   = new FormFieldRepository($this->pdo);
        $formsApi = new FormsApiHandler(
            new FormRepository($this->pdo),
            $fields,
            new FormRenderer($fields, new PrefillResolver($this->pdo)),
            new FormSubmissionRepository($this->pdo),
            new PublicFormLink('https://records.example.test'),
        );
        $router = new Router('');
        foreach ([
            ['POST', '/api/forms', 'create'], ['GET', '/api/forms/{id:\d+}', 'show'],
            ['PATCH', '/api/forms/{id:\d+}', 'update'], ['POST', '/api/forms/{id:\d+}/archive', 'archive'],
        ] as [$method, $path, $action]) {
            $router->registerUnversioned($method, $path, [$formsApi, $action]);
        }
        $declarations = array_values(array_filter(
            CoreApiSchemas::routes(),
            static fn (array $route): bool => in_array($route['path'], ['/api/forms', '/api/forms/{id:\d+}', '/api/forms/{id:\d+}/archive'], true),
        ));
        $deriver = new ToolDeriver($declarations, [], $router, static fn (string $m): null => null);

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(new McpPrincipal(
            self::RANA, self::RANA, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call', 'grant:forms:manage=allow'], 'jti-forms-agent',
        ));
        $actions         = new AgentActionRepository($this->pdo);
        $this->toolsCall = new ToolsCallHandler($deriver, $router, $roles, $tokens, proposals: new AgentProposalRepository($this->pdo), actions: $actions);
        $this->undoApi   = new AgentActionsApiHandler($actions, $this->toolsCall);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testCreatingAFormIsUndoneByArchivingTheDraft(): void
    {
        $form = $this->call('post_api_forms', ['form_key' => 'leave_request', 'name' => ['en' => 'Leave request']]);
        self::assertSame('draft', $this->formStatus($form['data']['id']));

        $this->undo($form['undo']);
        self::assertSame('archived', $this->formStatus($form['data']['id']));
    }

    public function testEditingAFormIsUndoneByRestoringWhatItReplaced(): void
    {
        $form = $this->call('post_api_forms', ['form_key' => 'leave_request', 'name' => ['en' => 'Leave request'], 'description' => 'For time off']);

        $edit = $this->call('patch_api_forms_id', ['id' => $form['data']['id'], 'name' => ['en' => 'Holiday request'], 'description' => null]);

        $this->undo($edit['undo']);
        $stmt = $this->pdo->prepare('SELECT name, description FROM forms WHERE id = ?');
        $stmt->execute([$form['data']['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertEquals(['en' => 'Leave request'], json_decode((string) $row['name'], true));
        self::assertSame('For time off', $row['description']);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{data: array<string, mixed>, undo: int}
     */
    private function call(string $tool, array $arguments): array
    {
        $result = ($this->toolsCall)(['name' => $tool, 'arguments' => $arguments], 'bearer');
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? false, (string) ($result['content'][0]['text'] ?? ''));
        $undo = $result['_meta'][ToolsCallHandler::META_UNDO]['actionId'] ?? null;
        self::assertIsInt($undo, "{$tool} offered no undo");
        return ['data' => json_decode($result['content'][0]['text'], true)['data'], 'undo' => $undo];
    }

    private function undo(int $actionId): void
    {
        $request       = new Request('POST', '/api/me/agent-actions', [], '');
        $request->user = (object) ['profile_id' => self::RANA, 'active_tenant_id' => self::TENANT];
        $response      = $this->undoApi->undo($request, ['id' => (string) $actionId]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
    }

    private function formStatus(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM forms WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }
}
