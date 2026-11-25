<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\CustomerGradePermissions;
use Whity\Core\Plugin\PluginCustomerRoutes;
use Whity\Core\Plugin\PluginFrameTokens;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\CustomerCall;

/**
 * Plugin screens for customers and the routes they may use (SDK 1.173,
 * WHIT-1424; design section 41 §9, CA.17, CA.18).
 */
final class PluginCustomerScreensTest extends TestCase
{
    private string $dir;
    private string $name;
    private string $a;
    private Router $router;

    protected function setUp(): void
    {
        CustomerGradePermissions::reset();
        PluginCustomerRoutes::reset();
        TenantContext::reset();
        $suffix = bin2hex(random_bytes(4));
        $this->name = 'Courses' . $suffix;
        $this->a = strtolower($this->name);
        $this->dir = sys_get_temp_dir() . '/whity_cscr_' . $suffix;
        mkdir($this->dir . '/' . $this->name, 0755, true);
        file_put_contents($this->dir . '/' . $this->name . '/Plugin.php', str_replace(['__NAME__', '__A__'], [$this->name, $this->a], <<<'PHP'
<?php
declare(strict_types=1);
namespace __NAME__;

use Whity\Sdk\Contribute\PluginCustomerScreensInterface;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginCustomerPermissionsInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginCustomerPermissionsInterface, PluginCustomerScreensInterface
{
    public function getName(): string    { return '__NAME__'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        $ok = static fn (Request $r): Response => Response::json(['data' => []]);
        return [
            ['method' => 'GET', 'path' => '/api/__A__/mine', 'handler' => $ok, 'requiredPermission' => '__A___course:read', 'customer' => true],
            ['method' => 'GET', 'path' => '/api/__A__/mine/count', 'handler' => $ok, 'requiredPermission' => '__A___course:read', 'customer' => true],
            ['method' => 'GET', 'path' => '/api/__A__/staff', 'handler' => $ok, 'requiredPermission' => '__A___course:read'],
            // Refused: a customer route needs a permission, and is never public.
            ['method' => 'GET', 'path' => '/api/__A__/bare', 'handler' => $ok, 'customer' => true],
            ['method' => 'POST', 'path' => '/api/__A__/open', 'handler' => $ok, 'customer' => true, 'public' => true],
        ];
    }
    public function getPermissions(): array { return ['__A___course:read', '__A___course:manage']; }
    public function getCustomerPermissions(): array { return ['__A___course:read']; }
    public function getCustomerScreens(): array
    {
        $list = ['type' => 'dataList', 'source' => '/api/__A__/mine', 'itemField' => 'title'];
        return [
            ['id' => '__A__-courses', 'label' => ['en' => 'Courses', 'ar' => 'الدورات'], 'screen' => 'blocks', 'requiredPermission' => '__A___course:read', 'blocks' => [$list], 'count' => ['apiPath' => '/api/__A__/mine/count'], 'order' => 5],
            ['id' => '__A__-next', 'label' => 'Next lesson', 'placement' => 'home', 'screen' => 'blocks', 'requiredPermission' => '__A___course:read', 'blocks' => [$list]],
            // Dropped: reads a route not declared for customers.
            ['id' => '__A__-staff-data', 'label' => 'Staff', 'screen' => 'blocks', 'requiredPermission' => '__A___course:read', 'blocks' => [['type' => 'dataList', 'source' => '/api/__A__/staff', 'itemField' => 'title']]],
            // Dropped: a staff kind.
            ['id' => '__A__-crud', 'label' => 'Crud', 'screen' => 'crud', 'requiredPermission' => '__A___course:read', 'resource' => ['basePath' => '/api/__A__/mine']],
            // Dropped: a Home card is blocks only.
            ['id' => '__A__-home-embed', 'label' => 'Embed', 'placement' => 'home', 'screen' => 'embed', 'requiredPermission' => '__A___course:read', 'embed' => ['path' => '/api/__A__/mine']],
            // Dropped: not a customer-grade permission.
            ['id' => '__A__-manage', 'label' => 'Manage', 'screen' => 'blocks', 'requiredPermission' => '__A___course:manage', 'blocks' => [$list]],
        ];
    }
    public function getHooks(): array      { return []; }
    public function getMigrations(): array { return []; }
}
PHP));
        $this->router = new Router('/v1');
        (new PluginLoader($this->dir, $this->router, new PermissionRegistry(), new HookManager()))->load();
    }

    protected function tearDown(): void
    {
        CustomerGradePermissions::reset();
        PluginCustomerRoutes::reset();
        TenantContext::reset();
        @unlink($this->dir . '/' . $this->name . '/Plugin.php');
        @rmdir($this->dir . '/' . $this->name);
        @rmdir($this->dir);
    }

    public function testOnlyScreensOnCustomerRoutesWithACustomerPermissionAreKept(): void
    {
        $loader = new PluginLoader($this->dir, new Router('/v1'), new PermissionRegistry(), new HookManager());
        PluginCustomerRoutes::reset();
        CustomerGradePermissions::reset();
        $loader->load();

        $screens = $loader->getCustomerScreens();
        self::assertSame([$this->a . '-courses', $this->a . '-next'], array_column($screens, 'id'));
        self::assertSame('tab', $screens[0]['placement']);
        self::assertSame('/api/v1/' . $this->a . '/mine/count', $screens[0]['count']['apiPath']);
        self::assertSame(5, $screens[0]['order']);
        self::assertSame('home', $screens[1]['placement']);
    }

    public function testACustomerRouteNeedsAPermissionAndIsNeverPublic(): void
    {
        $paths = PluginCustomerRoutes::pathsOf($this->namespace());
        sort($paths);
        self::assertSame(['/api/v1/' . $this->a . '/mine', '/api/v1/' . $this->a . '/mine/count'], $paths);
        self::assertNull($this->router->match(new Request('GET', '/api/v1/' . $this->a . '/bare', [], '')));
        self::assertNull($this->router->match(new Request('POST', '/api/v1/' . $this->a . '/open', [], '')));
    }

    public function testTheCustomerTokenOpensOnlyCustomerRoutes(): void
    {
        $token = ['type' => PluginFrameTokens::TYPE, PluginFrameTokens::AUDIENCE_CLAIM => PluginFrameTokens::AUDIENCE_CUSTOMER, 'plugin_ns' => $this->namespace(), 'session_jti' => 's1', 'profile_id' => 31];

        self::assertTrue(PluginCustomerRoutes::admits(new Request('GET', '/api/v1/' . $this->a . '/mine', [], ''), $this->router, $this->namespace()));
        self::assertFalse(PluginCustomerRoutes::admits(new Request('GET', '/api/v1/' . $this->a . '/staff', [], ''), $this->router, $this->namespace()));
        self::assertTrue(PluginFrameTokens::admits($token, new Request('GET', '/api/v1/' . $this->a . '/mine', [], ''), $this->router, true, null));
        self::assertFalse(PluginFrameTokens::admits($token, new Request('GET', '/api/v1/' . $this->a . '/staff', [], ''), $this->router, true, null));
        // A staff frame token is unchanged: any route of its plugin.
        unset($token[PluginFrameTokens::AUDIENCE_CLAIM]);
        self::assertTrue(PluginFrameTokens::admits($token, new Request('GET', '/api/v1/' . $this->a . '/staff', [], ''), $this->router, true, null));
    }

    public function testTheGateRefusesTheTokenElsewhereAndNamesTheCustomer(): void
    {
        PluginCustomerRoutes::useResolver(
            static fn (int $profileId, int $tenantId): bool => $profileId === 32,
            static fn (int $tenantId, int $profileId): array => $profileId === 31 ? [61, 62] : [63],
        );
        TenantContext::setTenantId(1);

        $customerToken = $this->request(['type' => PluginFrameTokens::TYPE, PluginFrameTokens::AUDIENCE_CLAIM => PluginFrameTokens::AUDIENCE_CUSTOMER, 'profile_id' => 31]);
        self::assertSame(404, PluginCustomerRoutes::guard($customerToken, false)?->getStatusCode());
        self::assertNull(PluginCustomerRoutes::guard($customerToken, true));
        $call = CustomerCall::from($customerToken);
        self::assertNotNull($call);
        self::assertSame([31, 1, [61, 62]], [$call->profileId, $call->workspaceId, $call->contactIds]);

        // A session that is a customer only here gets one too.
        $customerSession = $this->request(['type' => 'access', 'profile_id' => 32]);
        self::assertNull(PluginCustomerRoutes::guard($customerSession, true));
        self::assertSame([63], CustomerCall::from($customerSession)?->contactIds);

        // Staff keep their usual checks, with no CustomerCall, and sessions
        // reach other routes as before.
        $staff = $this->request(['type' => 'access', 'profile_id' => 11]);
        self::assertNull(PluginCustomerRoutes::guard($staff, true));
        self::assertNull(CustomerCall::from($staff));
        self::assertNull(PluginCustomerRoutes::guard($customerSession, false));
    }

    /** @param array<string, mixed> $claims */
    private function request(array $claims): Request
    {
        $request = new Request('GET', '/api/v1/' . $this->a . '/mine', [], '');
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, $claims);

        return $request;
    }

    private function namespace(): string
    {
        return $this->name;
    }
}
