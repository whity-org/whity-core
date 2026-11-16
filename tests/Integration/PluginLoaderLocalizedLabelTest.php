<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\PluginNavigationBridge;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Sdk\Frontend\Blocks\BlockText;

/**
 * SDK 1.101 lets a plugin write a screen's own label as {en, ar}. The loader
 * resolved it to English once, at load, so an Arabic reader got an English
 * page title and nav entry above an Arabic screen (found installing Front
 * Office on a running core). The label is now kept as written and resolved per
 * request, where the reader's language is known.
 */
final class PluginLoaderLocalizedLabelTest extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_label_ar_' . uniqid();
        mkdir(self::$dir . '/LabelAr', 0755, true);
        file_put_contents(self::$dir . '/LabelAr/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace LabelAr;

use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginFrontendInterface
{
    public function getName(): string { return 'LabelAr'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getSdkConstraint(): string { return '^1.101'; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return []; }
    public function getPermissions(): array { return ['labelar:view']; }
    public function getRoutes(): array { return []; }
    public function getFrontendFeatures(): array
    {
        return [[
            'id' => 'labelar-sell',
            'label' => ['en' => 'Sell', 'ar' => 'البيع'],
            'screen' => 'blocks',
            'requiredPermission' => 'labelar:view',
            'blocks' => [['type' => 'heading', 'level' => 2, 'text' => ['en' => 'New sale', 'ar' => 'بيع جديد']]],
        ]];
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/LabelAr/Plugin.php');
        @rmdir(self::$dir . '/LabelAr');
        @rmdir(self::$dir);
    }

    public function testTheLabelIsServedInTheReadersLanguage(): void
    {
        $loader = new PluginLoader(self::$dir, new Router(''), new PermissionRegistry(), new HookManager());
        $loader->load();

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        self::assertArrayHasKey('labelar-sell', $byId, 'A localized label must not drop the screen.');
        $feature = $byId['labelar-sell'];

        // What GET /api/frontend/features serves.
        self::assertSame('البيع', BlockText::resolve($feature, 'ar')['label']);
        self::assertSame('Sell', BlockText::resolve($feature, 'en')['label']);
        self::assertSame('بيع جديد', BlockText::resolve($feature, 'ar')['blocks'][0]['text']);

        // What the sidebar shows.
        self::assertSame('البيع', PluginNavigationBridge::toNavItem($feature, 'ar')['label']);
        self::assertSame('Sell', PluginNavigationBridge::toNavItem($feature, 'en')['label']);
    }
}
