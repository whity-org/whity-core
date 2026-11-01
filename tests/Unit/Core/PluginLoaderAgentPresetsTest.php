<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Agent\AgentPresetRegistry;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * Agent presets through the real PluginLoader (SDK 1.61, WHIT-818): a plugin's
 * presets reach the registry under its own root, and a bad one is dropped while
 * the plugin keeps loading.
 */
final class PluginLoaderAgentPresetsTest extends TestCase
{
    private static string $dir;
    private AgentPresetRegistry $presets;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_presets_' . uniqid();
        mkdir(self::$dir . '/CollectionsDesk', 0755, true);
        file_put_contents(self::$dir . '/CollectionsDesk/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace CollectionsDesk;

use Whity\Sdk\PluginAgentPresetsInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginAgentPresetsInterface
{
    public function getName(): string    { return 'CollectionsDesk'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return ['collections:read', 'collections:send']; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getAgentPresets(): array
    {
        $persona = [
            'name'         => ['en' => 'Collections clerk', 'ar' => 'موظف التحصيل'],
            'role'         => ['en' => 'Follows up unpaid invoices.', 'ar' => 'يتابع الفواتير غير المدفوعة.'],
            'instructions' => ['en' => 'Be brief.', 'ar' => 'كن موجزًا.'],
        ];

        return [
            $persona + ['key' => 'clerk', 'toolsets' => ['collections-desk', 'core.documents'], 'grant' => ['collections:read' => 'allow', 'collections:send' => 'ask']],
            $persona + ['key' => 'pushy', 'grant' => ['collections:send' => 'allow']],
        ];
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/CollectionsDesk/Plugin.php');
        @rmdir(self::$dir . '/CollectionsDesk');
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        $permissions = new PermissionRegistry();
        $permissions->registerCorePermissions();
        $loader = new PluginLoader(self::$dir, new Router(''), $permissions, new HookManager());
        $this->presets = new AgentPresetRegistry();
        $loader->setAgentPresetRegistry($this->presets);
        $loader->load();
    }

    public function testAPluginsPresetReachesTheRegistryUnderItsOwnRoot(): void
    {
        $preset = $this->presets->find(1, 'collections-desk.clerk');

        self::assertNotNull($preset, 'the loader registered it');
        self::assertSame('Collections clerk', $preset['name']);
        self::assertSame('موظف التحصيل', $preset['name_ar']);
        self::assertSame(['collections:read' => 'allow', 'collections:send' => 'ask'], $preset['grant']);
    }

    public function testAPresetThatStartsAWriteAllowedIsDroppedAndThePluginKeepsLoading(): void
    {
        self::assertNull($this->presets->find(1, 'collections-desk.pushy'));
        self::assertNotNull($this->presets->find(1, 'core.finance-clerk'), 'core\'s are still there');
    }
}
