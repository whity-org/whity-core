<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Workspace;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Plugin\Workspace\HostWorkspaceSetup;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-1129: a pack sets a workspace up through core's own routes, as the
 * person acting there, only on the allow-listed routes, and only with that
 * person's permissions.
 */
final class HostWorkspaceSetupTest extends TestCase
{
    private Router $router;
    /** @var list<array{string, mixed, mixed}> */
    private array $seen = [];
    /** @var list<string> */
    private array $granted = ['calendar:manage'];

    protected function setUp(): void
    {
        TenantContext::reset();
        AuditContext::reset();
        $this->router = new Router();
        $this->router->register('POST', '/api/calendar/services', function (Request $request): Response {
            $this->seen[] = [$request->getMethod(), json_decode($request->getBody(), true), $request->user->profile_id ?? null];

            return Response::json(['data' => ['id' => 41]], 201);
        }, null, null, 'calendar:manage');
        $this->router->register('POST', '/api/users', static fn (): Response => Response::json(['data' => []], 201), null, null, 'users:create');
        $this->router->register('PUT', '/api/calendar/settings', static fn (): Response => Response::json(['data' => []]), null, null, 'calendar:settings');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    public function testItRunsAnAllowedRouteAsThePersonActing(): void
    {
        $setup = $this->service();
        TenantContext::setTenantId(5);
        AuditContext::set(4, null);

        $result = $setup->call('POST', '/api/calendar/services', ['name' => ['en' => 'Haircut', 'ar' => 'قص شعر'], 'duration_minutes' => 30]);

        $this->assertSame(['status' => 201, 'body' => ['data' => ['id' => 41]]], $result);
        $this->assertSame([['POST', ['name' => ['en' => 'Haircut', 'ar' => 'قص شعر'], 'duration_minutes' => 30], 4]], $this->seen);
    }

    public function testOnlyTheListedRoutesAndOnlyWithThePersonsPermissions(): void
    {
        $setup = $this->service();
        TenantContext::setTenantId(5);
        AuditContext::set(4, null);

        $this->assertSame(403, $setup->call('POST', '/api/users', ['email' => 'x@example.com'])['status'], 'Not a setup route');
        $this->assertSame(403, $setup->call('PUT', '/api/calendar/settings', ['min_notice_minutes' => 30])['status'], 'The person lacks the permission');
        $this->assertSame([], $this->seen);
    }

    public function testNothingRunsWithoutAWorkspaceAndAPerson(): void
    {
        $setup = $this->service();
        $this->assertSame(403, $setup->call('POST', '/api/calendar/services', [])['status']);

        TenantContext::setTenantId(0);
        AuditContext::set(4, null);
        $this->assertSame(403, $setup->call('POST', '/api/calendar/services', [])['status'], 'Never the system workspace');
        $this->assertSame([], $this->seen);
    }

    private function service(): HostWorkspaceSetup
    {
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            fn (int $profileId, string $permission, int $tenantId): bool => in_array($permission, $this->granted, true)
        );

        return new HostWorkspaceSetup($this->router, $roles);
    }
}
