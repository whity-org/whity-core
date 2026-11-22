<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\PluginCapabilities;
use Whity\Core\Plugin\PluginRouteLimits;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;

/**
 * The loader keeps a plugin route's declared `rateLimit` for that route only,
 * refuses a bad value without refusing the route, and records the plugin's
 * declared services and tables (SDK 1.133, #1686, #1693).
 */
final class PluginRouteLimitsLoaderTest extends TestCase
{
    private string $dir;
    private string $name;
    private string $slug;
    private Router $router;

    protected function setUp(): void
    {
        PluginRouteLimits::reset();
        PluginCapabilities::reset();
        $this->name = 'Lim' . bin2hex(random_bytes(4));
        $this->slug = strtolower($this->name);
        $this->dir = sys_get_temp_dir() . '/whity_' . $this->slug;
        mkdir($this->dir . '/' . $this->name, 0755, true);
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace __NAME__;

use Whity\Sdk\Context\Service;
use Whity\Sdk\Contribute\PluginCapabilitiesInterface;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginCapabilitiesInterface
{
    public function getName(): string    { return '__NAME__'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        $ok = fn (): Response => Response::json([]);
        return [
            ['method' => 'POST', 'path' => '/api/__SLUG__/sync/{id}', 'handler' => $ok, 'requiredPermission' => '__SLUG__:write', 'rateLimit' => 5],
            ['method' => 'GET', 'path' => '/api/__SLUG__/bad', 'handler' => $ok, 'requiredPermission' => '__SLUG__:write', 'rateLimit' => 'lots'],
            ['method' => 'GET', 'path' => '/api/__SLUG__/free', 'handler' => $ok, 'requiredPermission' => '__SLUG__:write'],
        ];
    }
    public function getPermissions(): array { return ['__SLUG__:write']; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
    public function getServices(): array    { return [Service::DATABASE, 'everything']; }
    public function getTables(): array      { return ['__SLUG___rows', 'tenants']; }
}
PHP;
        file_put_contents(
            $this->dir . '/' . $this->name . '/Plugin.php',
            str_replace(['__NAME__', '__SLUG__'], [$this->name, $this->slug], $source)
        );
        $this->router = new Router();
        (new PluginLoader($this->dir, $this->router, new PermissionRegistry(), new HookManager()))->load();
    }

    protected function tearDown(): void
    {
        PluginRouteLimits::reset();
        PluginCapabilities::reset();
        @unlink($this->dir . '/' . $this->name . '/Plugin.php');
        @rmdir($this->dir . '/' . $this->name);
        @rmdir($this->dir);
    }

    private function limit(string $method, string $path): ?int
    {
        return PluginRouteLimits::limitFor(new Request($method, '/api/v1/' . $this->slug . $path, [], ''), $this->router);
    }

    public function testTheDeclaredLimitIsKeptForItsRoute(): void
    {
        self::assertSame(5, $this->limit('POST', '/sync/42'));
        self::assertNull($this->limit('GET', '/free'));
    }

    public function testABadLimitLeavesTheRouteRegisteredWithoutOne(): void
    {
        self::assertNotNull($this->router->match(new Request('GET', '/api/v1/' . $this->slug . '/bad', [], '')));
        self::assertNull($this->limit('GET', '/bad'));
    }

    public function testTheDeclarationIsRecordedWithoutWhatItMayNotHave(): void
    {
        self::assertSame(
            ['services' => ['database'], 'tables' => [$this->slug . '_rows']],
            PluginCapabilities::of($this->name)
        );
    }
}
