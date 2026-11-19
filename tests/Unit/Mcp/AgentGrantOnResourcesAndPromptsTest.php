<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Prompts\Prompt;
use Whity\Mcp\Prompts\PromptMessage;
use Whity\Mcp\Prompts\PromptRegistry;
use Whity\Mcp\Prompts\PromptsGetHandler;
use Whity\Mcp\Prompts\PromptsListHandler;
use Whity\Mcp\Resources\PluginResourceRegistry;
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Mcp\Resources\ResourcesListHandler;
use Whity\Mcp\Resources\ResourcesReadHandler;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * #2266, #2202: an agent's grant (Off / Ask first / Allowed) and a route's
 * agentPolicy hold on resources/* and prompts/* as they do on tools/call; a
 * gated route that opts out of MCP is not readable; resources/read stamps the
 * canonical claims; and every bearer the Dispatcher accepts works here.
 *
 * The person behind the agent holds every permission throughout, so each
 * refusal here is the grant's, never RBAC's.
 */
final class AgentGrantOnResourcesAndPromptsTest extends TestCase
{
    private const PROFILE = 7;
    private const TENANT  = 3;

    private Router $router;
    private ?Request $seen = null;

    protected function setUp(): void
    {
        $this->router = new Router('');
        $capture = function (Request $request): Response {
            $this->seen = $request;
            return Response::json(['secret' => true]);
        };
        $this->router->registerUnversioned('GET', '/api/documents', $capture, null, null, 'documents:read', ['summary' => 'Documents']);
        $this->router->registerUnversioned('GET', '/api/refused', $capture, null, null, 'documents:read', ['summary' => 'Refused', 'mcp' => ['agentPolicy' => 'refuse']]);
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @param list<string> $scope */
    private static function agent(array $scope): McpPrincipal
    {
        return new McpPrincipal(self::PROFILE, self::PROFILE, self::TENANT, McpPrincipal::KIND_AGENT, $scope, 'agent-jti', 'agent:12');
    }

    private function tokens(McpPrincipal $principal): TokenValidator
    {
        $tokens = $this->createMock(TokenValidator::class);
        // Only the method the Dispatcher uses: an OAuth or session bearer is
        // not an MCP token, and must work here all the same (#2202).
        $tokens->method('validateBearerForMcp')->willReturn($principal);
        $tokens->expects(self::never())->method('validateMcpToken');
        return $tokens;
    }

    private function roles(): RoleChecker
    {
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $roles->method('hasRoleForProfile')->willReturn(true);
        return $roles;
    }

    private function reader(McpPrincipal $principal, ?ResourceDeriver $deriver = null, ?PluginResourceRegistry $plugins = null): ResourcesReadHandler
    {
        return new ResourcesReadHandler(
            $this->router,
            $this->roles(),
            $this->tokens($principal),
            resourceDeriver: $deriver ?? new ResourceDeriver([], $this->router),
            pluginResources: $plugins,
        );
    }

    private static function expectCode(int $code, \Closure $call): void
    {
        try {
            $call();
            self::fail('expected McpException ' . $code);
        } catch (McpException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    // ── resources/read ────────────────────────────────────────────────────────

    public function testAnAgentWithTheReadGrantOffCannotReadTheResource(): void
    {
        $reader = $this->reader(self::agent(['grant:documents:read=off']));

        self::expectCode(ErrorCode::FORBIDDEN, fn () => $reader(['uri' => 'whity-api:///api/documents'], 'bearer'));
        self::assertNull($this->seen, 'the route handler never ran');
    }

    public function testAnAgentWithTheReadGrantOnAskFirstCannotReadTheResource(): void
    {
        // Nothing here for a person to approve: only Allowed passes.
        $reader = $this->reader(self::agent(['grant:documents:read=ask']));

        self::expectCode(ErrorCode::FORBIDDEN, fn () => $reader(['uri' => 'whity-api:///api/documents'], 'bearer'));
        self::assertNull($this->seen);
    }

    public function testARouteThatRefusesAgentsIsNotReadableByOne(): void
    {
        $reader = $this->reader(self::agent(['grant:documents:read=allow']));

        self::expectCode(ErrorCode::FORBIDDEN, fn () => $reader(['uri' => 'whity-api:///api/refused'], 'bearer'));
        self::assertNull($this->seen);
    }

    public function testAnAllowedAgentReadsWithTheCanonicalClaims(): void
    {
        $result = ($this->reader(self::agent(['grant:documents:read=allow'])))(['uri' => 'whity-api:///api/documents'], 'bearer');

        self::assertIsArray($result);
        $user = (array) ($this->seen?->user ?? []);
        self::assertSame(self::PROFILE, $user['profile_id'] ?? null);
        self::assertSame(self::TENANT, $user['active_tenant_id'] ?? null);
        self::assertSame('agent:12', $user['agent_principal_id'] ?? null);
    }

    public function testAPersonIsNotGovernedByAGrant(): void
    {
        $person = new McpPrincipal(self::PROFILE, self::PROFILE, self::TENANT, 'oauth', ['grant:documents:read=off'], 'jti');

        $result = ($this->reader($person))(['uri' => 'whity-api:///api/refused'], 'bearer');

        self::assertIsArray($result);
    }

    public function testATokenNarrowedToOtherToolsetsCannotReadTheResource(): void
    {
        $reader = $this->reader(self::agent(['toolset:core.calendar']));

        self::expectCode(ErrorCode::FORBIDDEN, fn () => $reader(['uri' => 'whity-api:///api/documents'], 'bearer'));
        self::assertNull($this->seen);
    }

    public function testAGatedRouteThatOptsOutOfMcpIsNotReadable(): void
    {
        // Core routes carry their schema on the static declaration; the Router
        // route the read resolves to has the gate but no schema (#2202).
        $this->router->registerUnversioned('GET', '/api/agent-activity', fn (Request $r): Response => Response::json(['x' => 1]), null, null, 'agents:read');
        $deriver = new ResourceDeriver(
            [['method' => 'GET', 'path' => '/api/agent-activity', 'requiredPermission' => 'agents:read', 'schema' => ['summary' => 'Activity', 'mcp' => ['expose' => false]]]],
            $this->router,
        );
        $person = new McpPrincipal(self::PROFILE, self::PROFILE, self::TENANT, 'user', ['resources:read'], 'jti');

        self::expectCode(ErrorCode::RESOURCE_NOT_FOUND, fn () => ($this->reader($person, $deriver))(['uri' => 'whity-api:///api/agent-activity'], 'bearer'));
    }

    public function testAnAgentWithThePluginResourcesPermissionOffCannotReadIt(): void
    {
        $plugins = new PluginResourceRegistry();
        $read    = false;
        self::assertNull($plugins->register([
            'path' => 'prices', 'name' => 'Prices', 'requiredPermission' => 'prices:read',
            'read' => static function () use (&$read): string { $read = true; return 'secret'; },
        ], 'PriceBook'));
        $uri = $plugins->all()[0]['uri'];

        $reader = $this->reader(self::agent(['grant:prices:read=off']), plugins: $plugins);

        self::expectCode(ErrorCode::FORBIDDEN, fn () => $reader(['uri' => $uri], 'bearer'));
        self::assertFalse($read, 'the plugin was never asked');
    }

    // ── resources/list ────────────────────────────────────────────────────────

    public function testResourcesTheAgentMayNotReadAreNotListed(): void
    {
        $plugins = new PluginResourceRegistry();
        $plugins->register(['path' => 'prices', 'name' => 'Prices', 'requiredPermission' => 'prices:read', 'read' => static fn (): string => ''], 'PriceBook');

        $list = new ResourcesListHandler(new ResourceDeriver([], $this->router), $this->roles(), $this->tokens(self::agent(['grant:documents:read=off', 'grant:prices:read=off'])), $plugins);
        $uris = array_column($list([], 'bearer')['resources'], 'uri');

        self::assertSame([], $uris);

        $allowed = new ResourcesListHandler(new ResourceDeriver([], $this->router), $this->roles(), $this->tokens(self::agent([])), $plugins);
        $uris    = array_column($allowed([], 'bearer')['resources'], 'uri');

        // Defaults: a read the person holds starts Allowed; the refusing route stays off.
        self::assertContains('whity-api:///api/documents', $uris);
        self::assertNotContains('whity-api:///api/refused', $uris);
        self::assertContains($plugins->all()[0]['uri'], $uris);
    }

    // ── prompts/* ─────────────────────────────────────────────────────────────

    private static function prompts(): PromptRegistry
    {
        $registry = new PromptRegistry();
        $registry->register(new Prompt('summarise-documents', 'Summarise documents', [], [new PromptMessage('user', 'Summarise.')], null, 'documents:read'));
        $registry->register(new Prompt('hello', 'Open prompt', [], [new PromptMessage('user', 'Hi.')]));
        return $registry;
    }

    public function testAnAgentWithTheGrantOffCannotGetThePrompt(): void
    {
        $get = new PromptsGetHandler(self::prompts(), $this->roles(), $this->tokens(self::agent(['grant:documents:read=off'])));

        self::expectCode(ErrorCode::FORBIDDEN, fn () => $get(['name' => 'summarise-documents'], 'bearer'));
        self::assertIsArray($get(['name' => 'hello'], 'bearer'));
    }

    public function testPromptsTheAgentMayNotGetAreNotListed(): void
    {
        $off  = new PromptsListHandler(self::prompts(), $this->roles(), $this->tokens(self::agent(['grant:documents:read=off'])));
        $open = new PromptsListHandler(self::prompts(), $this->roles(), $this->tokens(self::agent([])));

        self::assertSame(['hello'], array_column($off([], 'bearer')['prompts'], 'name'));
        self::assertSame(['summarise-documents', 'hello'], array_column($open([], 'bearer')['prompts'], 'name'));
    }
}
