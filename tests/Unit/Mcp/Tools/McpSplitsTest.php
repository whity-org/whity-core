<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\OAuth\ProtectedResource;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\Lifecycle\InitializeHandler;
use Whity\Mcp\Tools\Discovery\DiscoveryTools;
use Whity\Mcp\Tools\McpSplits;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Mcp\Transport\McpTransportHandler;
use Whity\Sdk\Http\Response;

/**
 * Splits (WHIT-765, MCP-TOOL-SURFACE.md §5.12): one slice of the server at its
 * own URL, a hard boundary, and its own OAuth resource.
 */
final class McpSplitsTest extends TestCase
{
    private const TENANT = 1;

    private McpTransportHandler $transport;
    private ?string $boundTo = null;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $router = new Router('');
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/forms', 'requiredPermission' => 'forms:read', 'schema' => ['summary' => 'List forms', 'tags' => ['forms']]],
            ['method' => 'GET', 'path' => '/api/ous', 'requiredPermission' => 'ous:read', 'schema' => ['summary' => 'List units', 'tags' => ['ous']]],
        ], [], $router);
        foreach (['forms', 'ous'] as $name) {
            $router->registerUnversioned('GET', "/api/{$name}", function () use ($name): Response {
                $this->ran[] = $name;
                return Response::json(['ok' => true]);
            }, null, null, "{$name}:read");
        }

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturnCallback(
            fn (): McpPrincipal => new McpPrincipal(7, 7, self::TENANT, 'user', ['tools:call'], 'jti', null, $this->boundTo),
        );

        $list      = new ToolsListHandler($deriver, $roles, $tokens);
        $call      = new ToolsCallHandler($deriver, $router, $roles, $tokens);
        $discovery = new DiscoveryTools($list, $call, $tokens);
        $handlers  = ['initialize' => new InitializeHandler()];

        $this->transport = new McpTransportHandler(
            new Dispatcher($handlers + ['tools/list' => $list, 'tools/call' => $call], $tokens),
            discoveryDispatcher: new Dispatcher($handlers + ['tools/list' => $discovery->listHandler(), 'tools/call' => $discovery->callHandler()], $tokens),
            protectedResource: new ProtectedResource('https://id.whity.test', 'https://app.whity.test/mcp'),
            defaultDispatcher: new Dispatcher($handlers + ['tools/list' => $discovery->defaultListHandler(), 'tools/call' => $discovery->callHandler()], $tokens),
            splits: new McpSplits(),
        );
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testASplitServesSearchAndCallAndItsFullListIsOnlyItsOwn(): void
    {
        self::assertSame(['init_session', 'search_operations', 'call_operation'], $this->names('forms', ''));
        self::assertSame(['get_api_forms'], $this->names('forms', '?tools=full'));
    }

    public function testACallerCannotWidenASplitWithTheQuery(): void
    {
        self::assertSame(['get_api_forms'], $this->names('forms', '?tools=full&toolsets=core.people'));
    }

    public function testSearchCannotFindWhatIsOutsideTheSplit(): void
    {
        $inside  = $this->callTool('forms', 'search_operations', ['operationId' => 'get_api_forms']);
        $outside = $this->callTool('forms', 'search_operations', ['operationId' => 'get_api_ous']);

        self::assertArrayNotHasKey('isError', $inside['result']);
        self::assertTrue($outside['result']['isError'] ?? false);
    }

    public function testCallOperationCannotReachOutsideTheSplit(): void
    {
        $outside = $this->callTool('forms', 'call_operation', ['operationId' => 'get_api_ous', 'arguments' => []]);
        self::assertTrue($outside['result']['isError'] ?? false);
        self::assertStringContainsString('No operation', $outside['result']['content'][0]['text']);
        self::assertSame([], $this->ran);

        $inside = $this->callTool('forms', 'call_operation', ['operationId' => 'get_api_forms', 'arguments' => []]);
        self::assertFalse($inside['result']['isError'] ?? false, (string) json_encode($inside));
        self::assertSame(['forms'], $this->ran);
    }

    public function testCallingByNameOutsideTheSplitIsUnknownNotForbidden(): void
    {
        $response = $this->callTool('forms', 'get_api_ous', [], '?tools=full');

        self::assertStringContainsString('Unknown tool', $response['error']['message'] ?? '');
        self::assertSame([], $this->ran);
    }

    public function testTheSameCallWorksOnTheWholeServer(): void
    {
        $this->post(null, '?tools=full', 'tools/call', ['name' => 'get_api_ous', 'arguments' => []]);

        self::assertSame(['ous'], $this->ran);
    }

    public function testAnUnknownSplitDoesNotExist(): void
    {
        self::assertSame(404, $this->post('nowhere', '', 'tools/list')->getStatusCode());
    }

    public function testATokenForOneSplitWorksOnlyThere(): void
    {
        $this->boundTo = 'forms';

        self::assertSame(200, $this->post('forms', '', 'tools/list')->getStatusCode());

        $elsewhere = $this->post('people', '', 'tools/list');
        self::assertSame(401, $elsewhere->getStatusCode());
        self::assertStringContainsString('/.well-known/oauth-protected-resource/mcp/people', $elsewhere->getHeaders()['www-authenticate'] ?? '');

        self::assertSame(401, $this->post(null, '', 'tools/list')->getStatusCode(), 'nor on the whole server');
    }

    public function testATokenForTheWholeServerWorksOnEverySplit(): void
    {
        self::assertSame(200, $this->post('forms', '', 'tools/list')->getStatusCode());
        self::assertSame(200, $this->post('people', '', 'tools/list')->getStatusCode());
    }

    public function testTheServerNamesItsSplit(): void
    {
        $split = json_decode($this->post('forms', '', 'initialize', ['protocolVersion' => '2025-06-18'])->getBody(), true);
        $bare  = json_decode($this->post(null, '', 'initialize', ['protocolVersion' => '2025-06-18'])->getBody(), true);

        self::assertSame('whity-core/forms', $split['result']['serverInfo']['name']);
        self::assertSame('whity-core', $bare['result']['serverInfo']['name']);
    }

    public function testASplitHasItsOwnResourceMetadata(): void
    {
        $resource = (new ProtectedResource('https://id.whity.test', 'https://app.whity.test/mcp'))->forSplit('forms');

        self::assertSame('https://app.whity.test/mcp/forms', $resource->resource());
        self::assertSame('https://app.whity.test/.well-known/oauth-protected-resource/mcp/forms', $resource->metadataUrl());
        self::assertSame('https://app.whity.test/mcp/forms', json_decode($resource->metadata(new Request('GET', '/', [], ''))->getBody(), true)['resource']);
    }

    public function testTheRegistryKnowsCoreOperatorAndPluginSplits(): void
    {
        $splits = new McpSplits(
            static fn (): array => ['acme', 'documents', 'agents', 'Not Valid'],
            'sales=core.forms,acme.*;documents=core.time;agents=core.time;bad name=core.time;empty=',
        );

        self::assertSame(['core.documents.*'], $splits->toolsetsOf('documents'), 'core wins over an operator');
        self::assertSame(['core.forms', 'acme.*'], $splits->toolsetsOf('sales'));
        self::assertSame(['acme.*'], $splits->toolsetsOf('acme'));
        self::assertSame(['documents.*'], $splits->toolsetsOf('x-documents'), 'a plugin colliding with a core name');
        self::assertSame(['agents.*'], $splits->toolsetsOf('x-agents'), 'agents is kept for workspace agents');
        self::assertNull($splits->toolsetsOf('agents'));
        self::assertNull($splits->toolsetsOf('bad name'));
        self::assertNull($splits->toolsetsOf('empty'));
        self::assertNull($splits->toolsetsOf('Not Valid'));
    }

    public function testSplitsBypassTenantResolutionOneSegmentDeep(): void
    {
        self::assertTrue(EnforceTenantIsolation::pathBypassesTenantResolution('/mcp/forms'));
        self::assertTrue(EnforceTenantIsolation::pathBypassesTenantResolution('/.well-known/oauth-protected-resource/mcp/forms'));
        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/mcp/forms/other'));
        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/mcpx/forms'));
    }

    public function testTheRouterMatchesASplitsPath(): void
    {
        $router = new Router('');
        $router->registerUnversioned('POST', '/mcp/{split:[a-z0-9][a-z0-9_.-]*}', static fn (): Response => Response::json([]));

        $matched = $router->match(new Request('POST', '/mcp/acme.invoices', [], ''));

        self::assertSame('acme.invoices', $matched['params']['split'] ?? null);
        self::assertNull($router->match(new Request('POST', '/mcp/acme/invoices', [], '')));
    }

    /** @param array<string, mixed> $params */
    private function post(?string $split, string $query, string $method, array $params = []): Response
    {
        TenantContext::reset();
        $path    = ($split === null ? '/mcp' : "/mcp/{$split}") . $query;
        $request = new Request('POST', $path, ['Content-Type' => 'application/json', 'Authorization' => 'Bearer t'], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));

        return $this->transport->handlePost($request, $split === null ? [] : ['split' => $split]);
    }

    /** @return list<string> */
    private function names(string $split, string $query): array
    {
        $body = json_decode($this->post($split, $query, 'tools/list')->getBody(), true);

        return array_column($body['result']['tools'] ?? [], 'name');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $split, string $name, array $arguments, string $query = ''): array
    {
        return json_decode($this->post($split, $query, 'tools/call', ['name' => $name, 'arguments' => $arguments])->getBody(), true);
    }
}
