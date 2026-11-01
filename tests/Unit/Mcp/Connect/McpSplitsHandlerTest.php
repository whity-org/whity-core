<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Connect;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Connect\McpSplitsHandler;
use Whity\Mcp\Tools\McpSplits;

/**
 * GET /api/mcp/splits (WHIT-765) lists only what the caller can reach, so it
 * never names an area they have no access to.
 */
final class McpSplitsHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(1);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testItListsOnlyTheSplitsWithSomethingTheCallerMayUse(): void
    {
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(static fn (int $profile, string $permission): bool => $permission === 'forms:read');

        $handler = new McpSplitsHandler(
            new McpSplits(static fn (): array => ['acme']),
            $roles,
            static fn (): array => [
                ['toolset' => 'core.forms', 'permission' => 'forms:read'],
                ['toolset' => 'core.billing', 'permission' => 'billing:view'],
                ['toolset' => 'acme', 'permission' => null],
                ['toolset' => 'acme.invoices', 'permission' => 'acme:manage'],
            ],
            'https://app.whity.test/mcp',
        );

        $request       = new Request('GET', '/api/mcp/splits', [], '');
        $request->user = (object) ['profile_id' => 7];
        $data = json_decode($handler->list($request)->getBody(), true)['data'];

        self::assertSame([
            ['name' => 'forms', 'url' => 'https://app.whity.test/mcp/forms', 'toolsets' => ['core.forms']],
            ['name' => 'acme', 'url' => 'https://app.whity.test/mcp/acme', 'toolsets' => ['acme']],
        ], $data);
    }

    public function testASignedOutCallerIsRefused(): void
    {
        $handler = new McpSplitsHandler(new McpSplits(), $this->createMock(RoleChecker::class), static fn (): array => [], null);

        self::assertSame(401, $handler->list(new Request('GET', '/api/mcp/splits', [], ''))->getStatusCode());
    }
}
