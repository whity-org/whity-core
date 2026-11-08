<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\PluginHost\HookRegistrar;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\PluginInterface;

/**
 * An observe-only listener sees the event and can change nothing about it, on
 * both hosts that load plugins.
 */
final class PluginLoaderObserveOnlyHooksTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/whity_observe_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        unset($GLOBALS['observe_only_seen']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
        unset($GLOBALS['observe_only_seen']);
    }

    /** Loads a real plugin file whose getHooks() body is $hooks. */
    private function load(string $class, string $hooks): HookManager
    {
        file_put_contents($this->tempDir . '/' . $class . '.php', <<<PHP
<?php

namespace Whity\Plugins;

use Whity\Sdk\PluginInterface;
use Whity\Sdk\Hooks\HookVetoException;

class {$class} implements PluginInterface
{
    public function getName(): string { return '{$class}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getHooks(): array { {$hooks} }
}
PHP);
        $hooks = new HookManager();
        (new PluginLoader($this->tempDir, new Router(''), new PermissionRegistry(), $hooks))->load();

        return $hooks;
    }

    public function testAReturnedPayloadIsIgnoredAndTheListenerStillRan(): void
    {
        $hooks = $this->load('ObserveReturns', <<<'PHP'
return ['e.x' => ['observe' => true, 'callback' => function (array $d, array $c): array {
    $GLOBALS['observe_only_seen'] = $d['role_id'];
    $d['role_id'] = 1;
    return $d;
}]];
PHP);

        $this->assertSame(['role_id' => 7], $hooks->dispatch('e.x', ['role_id' => 7]));
        $this->assertSame(7, $GLOBALS['observe_only_seen']);
    }

    public function testAByReferenceListenerCannotChangeThePayload(): void
    {
        $hooks = $this->load('ObserveByRef', <<<'PHP'
return ['e.x' => ['observe' => true, 'callback' => function (array &$d, array $c): void {
    $d['role_id'] = 1;
}]];
PHP);

        $this->assertSame(['role_id' => 7], $hooks->dispatch('e.x', ['role_id' => 7]));
    }

    public function testAVetoFromAnObserverDoesNotStopTheOperation(): void
    {
        $hooks = $this->load('ObserveVetoes', <<<'PHP'
return ['e.x' => ['observe' => true, 'callback' => function (array $d, array $c): void {
    throw new HookVetoException('e.x', 'no');
}]];
PHP);

        $this->assertSame(['a' => 1], $hooks->dispatch('e.x', ['a' => 1]));
    }

    public function testAnObserverThatThrowsDoesNotBreakTheDispatch(): void
    {
        $hooks = $this->load('ObserveThrows', <<<'PHP'
return ['e.x' => ['observe' => true, 'callback' => function (array $d, array $c): void {
    throw new \RuntimeException('boom');
}]];
PHP);

        $this->assertSame(['a' => 1], $hooks->dispatch('e.x', ['a' => 1]));
    }

    public function testAListCanMixObserversAndFiltersAndPriorityIsKept(): void
    {
        $hooks = $this->load('ObserveMixed', <<<'PHP'
return ['e.x' => [
    ['priority' => 20, 'observe' => true, 'callback' => function (array $d, array $c): array {
        $GLOBALS['observe_only_seen'] = $d['a'];
        return ['a' => 'observer'];
    }],
    ['priority' => 5, 'callback' => function (array $d, array $c): array {
        $d['a'] = 'filter';
        return $d;
    }],
]];
PHP);

        $this->assertSame(['a' => 'filter'], $hooks->dispatch('e.x', ['a' => 0]));
        $this->assertSame('filter', $GLOBALS['observe_only_seen']);
    }

    public function testAFilterListenerCanStillChangeThePayloadAndVeto(): void
    {
        $hooks = $this->load('StillFilters', <<<'PHP'
return [
    'e.change' => ['callback' => function (array $d, array $c): array { $d['a'] = 2; return $d; }],
    'e.veto' => function (array $d, array $c): array { throw new HookVetoException('e.veto', 'no'); },
];
PHP);

        $this->assertSame(['a' => 2], $hooks->dispatch('e.change', ['a' => 1]));
        $this->expectException(HookVetoException::class);
        $hooks->dispatch('e.veto', []);
    }

    /** The offline host has its own registrar; it has to keep the same promise. */
    public function testTheDesktopHostKeepsTheSamePromise(): void
    {
        require_once dirname(__DIR__, 2) . '/templates/tauri-desktop/php-host/src/PluginHost/HookRegistrar.php';

        $plugin = $this->createStub(PluginInterface::class);
        $plugin->method('getHooks')->willReturn([
            'e.changes' => ['observe' => true, 'callback' => static fn (array $d, array $c): array => ['role_id' => 1]],
            'e.vetoes' => ['observe' => true, 'callback' => static function (array $d, array $c): void {
                throw new HookVetoException('e.vetoes', 'no');
            }],
            'e.filter' => ['callback' => static fn (array $d, array $c): array => ['role_id' => 2]],
        ]);
        $hooks = new HookManager();
        // @phpstan-ignore class.notFound (the desktop host is not on the analysed autoload path; required above)
        HookRegistrar::registerAll($hooks, 'P', $plugin);

        $this->assertSame(['role_id' => 7], $hooks->dispatch('e.changes', ['role_id' => 7]));
        $this->assertSame(['role_id' => 7], $hooks->dispatch('e.vetoes', ['role_id' => 7]));
        $this->assertSame(['role_id' => 2], $hooks->dispatch('e.filter', ['role_id' => 7]));
    }
}
