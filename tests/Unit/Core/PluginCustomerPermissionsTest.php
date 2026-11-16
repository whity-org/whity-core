<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\CustomerGradePermissions;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * A plugin declares which of its own permissions customers may hold (#1872,
 * SDK 1.104), through the real loader: only its own declared permissions on a
 * resource it alone owns count, never a core or admin one, and disabling the
 * plugin withdraws them.
 */
final class PluginCustomerPermissionsTest extends TestCase
{
    private string $dir;
    private string $clinic;
    private string $other;
    /** Lowercase resource prefixes, unique per test. */
    private string $a;
    private string $b;

    protected function setUp(): void
    {
        CustomerGradePermissions::reset();
        $suffix = bin2hex(random_bytes(4));
        $this->clinic = 'Clinic' . $suffix;
        $this->other = 'Other' . $suffix;
        // A plugin's customer-grade resources must sit in its own namespace.
        $this->a = strtolower($this->clinic);
        $this->b = strtolower($this->other);
        $this->dir = sys_get_temp_dir() . '/whity_cgp_' . $suffix;

        $this->writePlugin($this->clinic, <<<'PHP'
<?php
declare(strict_types=1);
namespace __NAME__;

use Whity\Sdk\PluginCustomerPermissionsInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginCustomerPermissionsInterface
{
    public function getName(): string    { return '__NAME__'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array   { return []; }
    public function getPermissions(): array
    {
        return ['__A___patient:read', '__A___patient:manage', '__A___scan:upload', 'users:write', 'settings:manage', 'bakery_order:manage'];
    }
    public function getCustomerPermissions(): array
    {
        return [
            '__A___patient:manage',   // counts
            '__A___scan:upload',      // refused: the other plugin also declares __A___scan
            'users:write',            // refused: core
            'settings:manage',        // refused: admin resource
            '__A___undeclared:manage',// refused: not in getPermissions()
            '__B___thing:manage',     // refused: the other plugin's
            'bakery_order:manage',    // refused: declared, but outside this plugin's namespace
        ];
    }
    public function getHooks(): array      { return []; }
    public function getMigrations(): array { return []; }
}
PHP);

        $this->writePlugin($this->other, <<<'PHP'
<?php
declare(strict_types=1);
namespace __NAME__;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string    { return '__NAME__'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array   { return []; }
    public function getPermissions(): array { return ['__B___thing:manage', '__A___scan:delete']; }
    public function getHooks(): array      { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
    }

    protected function tearDown(): void
    {
        CustomerGradePermissions::reset();
        foreach ([$this->clinic, $this->other] as $name) {
            @unlink($this->dir . '/' . $name . '/Plugin.php');
            @rmdir($this->dir . '/' . $name);
        }
        @rmdir($this->dir);
    }

    public function testOnlyThePluginsOwnPermissionsOnAResourceItAloneOwnsCount(): void
    {
        $this->load();

        self::assertTrue(CustomerGradePermissions::isCustomerGrade($this->a . '_patient:manage'));
        self::assertFalse(CustomerSafeRole::isPrivilegedPermission($this->a . '_patient:manage'));

        foreach (
            [
                $this->a . '_scan:upload',
                'users:write',
                'settings:manage',
                $this->a . '_undeclared:manage',
                $this->b . '_thing:manage',
                'bakery_order:manage',
            ] as $refused
        ) {
            self::assertFalse(CustomerGradePermissions::isCustomerGrade($refused), $refused);
            self::assertTrue(CustomerSafeRole::isPrivilegedPermission($refused), $refused);
        }
        self::assertSame([$this->a . '_patient:manage'], CustomerGradePermissions::all());
    }

    public function testDisablingThePluginWithdrawsItAndReEnablingRestoresIt(): void
    {
        $loader = $this->load();
        $key = $this->clinic . '\\Plugin';

        self::assertTrue($loader->disablePlugin($key));
        self::assertFalse(CustomerGradePermissions::isCustomerGrade($this->a . '_patient:manage'));
        self::assertTrue(CustomerSafeRole::isPrivilegedPermission($this->a . '_patient:manage'));

        self::assertTrue($loader->reEnablePlugin($key));
        self::assertTrue(CustomerGradePermissions::isCustomerGrade($this->a . '_patient:manage'));
    }

    public function testAnotherPluginDisabledNoLongerSharesTheResource(): void
    {
        $loader = $this->load();
        self::assertFalse(CustomerGradePermissions::isCustomerGrade($this->a . '_scan:upload'));

        $loader->disablePlugin($this->other . '\\Plugin');
        self::assertTrue(CustomerGradePermissions::isCustomerGrade($this->a . '_scan:upload'));
    }

    public function testReloadingWithoutThePluginWithdrawsIt(): void
    {
        $loader = $this->load();
        self::assertTrue(CustomerGradePermissions::isCustomerGrade($this->a . '_patient:manage'));

        @unlink($this->dir . '/' . $this->clinic . '/Plugin.php');
        @rmdir($this->dir . '/' . $this->clinic);
        $loader->reload();

        self::assertFalse(CustomerGradePermissions::isCustomerGrade($this->a . '_patient:manage'));
    }

    private function load(): PluginLoader
    {
        $loader = new PluginLoader($this->dir, new Router(), new PermissionRegistry(), new HookManager());
        $loader->load();

        return $loader;
    }

    private function writePlugin(string $name, string $source): void
    {
        mkdir($this->dir . '/' . $name, 0755, true);
        file_put_contents(
            $this->dir . '/' . $name . '/Plugin.php',
            str_replace(['__NAME__', '__A__', '__B__'], [$name, $this->a, $this->b], $source)
        );
    }
}
