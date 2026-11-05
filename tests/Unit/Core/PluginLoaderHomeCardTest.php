<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * Home cards through the real PluginLoader (SDK 1.62, WHIT-872): a card's source must
 * be a GET route the plugin registered under the card's own permission, and a bad
 * card is dropped while the plugin keeps loading.
 */
final class PluginLoaderHomeCardTest extends TestCase
{
    private static string $dir;

    /** name => the `getHomeCard()` body */
    private const PLUGINS = [
        'GoodCard' => "['label' => ['en' => 'Good', 'ar' => 'جيد'], 'permission' => 'good:read', 'source' => '/api/good/card']",
        'ForeignSource' => "['label' => ['en' => 'Bad', 'ar' => 'سيئ'], 'permission' => 'foreign:read', 'source' => '/api/users']",
        'OtherGate' => "['label' => ['en' => 'Bad', 'ar' => 'سيئ'], 'permission' => 'other:read', 'source' => '/api/other/card']",
        'BadShape' => "['label' => ['en' => 'Bad'], 'permission' => 'shape:read', 'source' => '/api/shape/card']",
        'NoCard' => 'null',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_home_cards_' . uniqid();
        foreach (self::PLUGINS as $name => $card) {
            mkdir(self::$dir . '/' . $name, 0755, true);
            $slug = strtolower($name);
            $otherGate = $name === 'OtherGate' ? 'other:write' : 'x:read';
            $permission = ['GoodCard' => 'good:read', 'OtherGate' => $otherGate][$name] ?? "{$slug}:read";
            $path = ['GoodCard' => '/api/good/card', 'OtherGate' => '/api/other/card', 'BadShape' => '/api/shape/card'][$name] ?? "/api/{$slug}/card";
            file_put_contents(self::$dir . "/{$name}/Plugin.php", <<<PHP
<?php
declare(strict_types=1);
namespace {$name};

use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginHomeCardInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginHomeCardInterface
{
    public function getName(): string    { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [['method' => 'GET', 'path' => '{$path}', 'handler' => static fn (): Response => Response::json(['data' => ['value' => '1']]), 'requiredPermission' => '{$permission}']];
    }
    public function getPermissions(): array { return ['{$permission}', 'good:read', 'foreign:read', 'other:read', 'shape:read']; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
    public function getHomeCard(): ?array   { return {$card}; }
}
PHP);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (array_keys(self::PLUGINS) as $name) {
            @unlink(self::$dir . "/{$name}/Plugin.php");
            @rmdir(self::$dir . "/{$name}");
        }
        @rmdir(self::$dir);
    }

    public function testOnlyACardBackedByThePluginsOwnGatedRouteSurvives(): void
    {
        $permissions = new PermissionRegistry();
        $permissions->registerCorePermissions();
        $loader = new PluginLoader(self::$dir, new Router(''), $permissions, new HookManager());
        $loader->load();

        $names = array_map(static fn ($plugin): string => $plugin->getName(), $loader->getPlugins());
        self::assertContains('ForeignSource', $names, 'A plugin with a bad card keeps loading.');
        self::assertContains('BadShape', $names, 'A plugin with a bad card keeps loading.');

        $cards = $loader->getHomeCards();
        self::assertSame(['GoodCard'], array_column($cards, 'plugin'));
        self::assertSame('/api/good/card', $cards[0]['source']);
        self::assertSame('good:read', $cards[0]['permission']);
    }
}
