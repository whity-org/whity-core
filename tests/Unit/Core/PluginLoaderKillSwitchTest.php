<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\PluginState;
use Whity\Core\Router;

/**
 * A plugin a signed revocation list killed is never loaded (WHIT-1338, TM-3).
 *
 * The kill already turned the plugin off at every request surface (WHIT-1277).
 * What these tests pin is the part that gate cannot reach: the plugin's own
 * code. Each fixture's file records, at top level, that it ran, so "not loaded"
 * is observed directly rather than inferred from a missing route.
 *
 * Every test makes its own plugin names: a class `require`d once stays in the
 * process, and a later test reusing the name would prove nothing.
 */
final class PluginLoaderKillSwitchTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $names = [];

    /** Flipped by a test to kill a plugin after it loaded. */
    private bool $killedNow = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/whity_kill_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
        $GLOBALS['whity_kill_switch_ran'] = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->names as $name) {
            @unlink($this->dir . '/' . $name . '/Plugin.php');
            @rmdir($this->dir . '/' . $name);
        }
        @rmdir($this->dir);
        unset($GLOBALS['whity_kill_switch_ran']);
    }

    public function testWithoutAKillSwitchThePluginLoadsAndItsCodeRuns(): void
    {
        // The control: proves the fixture's "I ran" marker works, so the
        // assertions below that it did NOT run mean something.
        $name = $this->plugin('Control');

        $loader = $this->loader();
        $loader->load();

        self::assertContains($name, $this->ran());
        self::assertContains($name, $this->loadedNames($loader));
    }

    public function testAKilledPluginsCodeNeverRunsAndTheOthersStillLoad(): void
    {
        $killed = $this->plugin('Killed');
        $kept = $this->plugin('Kept');

        $loader = $this->loader();
        $asked = [];
        $loader->setKillSwitch(static function (string $plugin) use ($killed, &$asked): bool {
            $asked[] = $plugin;

            return $plugin === $killed;
        });
        $loader->load();

        self::assertNotContains($killed, $this->ran(), 'the killed plugin\'s file was required');
        self::assertFalse(class_exists($killed . '\\Plugin', false), 'the killed plugin\'s class is in the process');
        self::assertNotContains($killed, $this->loadedNames($loader));
        self::assertContains($kept, $this->ran());
        self::assertContains($kept, $this->loadedNames($loader));
        self::assertContains($killed, $asked, 'discovery never asked about the killed plugin');

        // Listed, as a disabled plugin is, so the operator still sees it.
        $lifecycle = $loader->getLifecycle($killed . '\\Plugin');
        self::assertNotNull($lifecycle);
        self::assertSame(PluginState::Disabled, $lifecycle->getState());

        // And no enable path can pull its code in afterwards.
        self::assertNull($loader->getPluginInstance($killed . '\\Plugin'));
        self::assertNotContains($killed, $this->ran());
    }

    public function testAKillSwitchThatFailsLoadsPluginsAsBefore(): void
    {
        // No database yet, a broken query: refusing every plugin would take the
        // host down, and the request-time gate still applies.
        $name = $this->plugin('Unreadable');

        $loader = $this->loader();
        $loader->setKillSwitch(static function (string $plugin): bool {
            throw new \RuntimeException('no trust tables');
        });
        $loader->load();

        self::assertContains($name, $this->loadedNames($loader));
    }

    public function testAWorkerHoldingAPluginKilledAfterItLoadedAsksToRecycle(): void
    {
        $name = $this->plugin('Late');
        $this->killedNow = false;

        $loader = $this->loader();
        $loader->setKillSwitch(function (string $plugin) use ($name): bool {
            return $this->killedNow && $plugin === $name;
        });
        $loader->load();
        self::assertContains($name, $this->loadedNames($loader));

        self::assertFalse($loader->holdsKilledPlugin(30, 1000), 'nothing killed yet');

        $this->killedNow = true;
        self::assertFalse($loader->holdsKilledPlugin(30, 1010), 'asked again before the interval passed');
        self::assertTrue($loader->holdsKilledPlugin(30, 1031), 'the kill was not noticed after the interval');
    }

    public function testWithoutAKillSwitchAWorkerNeverAsksToRecycle(): void
    {
        $this->plugin('NoSwitch');
        $loader = $this->loader();
        $loader->load();

        self::assertFalse($loader->holdsKilledPlugin(0, 5000));
    }

    private function loader(): PluginLoader
    {
        return new PluginLoader($this->dir, new Router(''), null, new HookManager());
    }

    /** Write a directory plugin whose file records that it ran; returns its name. */
    private function plugin(string $prefix): string
    {
        $name = $prefix . bin2hex(random_bytes(4));
        $this->names[] = $name;
        mkdir($this->dir . '/' . $name, 0755, true);
        file_put_contents($this->dir . '/' . $name . '/Plugin.php', <<<PHP
<?php
declare(strict_types=1);
namespace {$name};

use Whity\\Sdk\\PluginInterface;

\$GLOBALS['whity_kill_switch_ran'][] = '{$name}';

final class Plugin implements PluginInterface
{
    public function getName(): string    { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
}
PHP);

        return $name;
    }

    /** @return list<string> */
    private function ran(): array
    {
        return $GLOBALS['whity_kill_switch_ran'] ?? [];
    }

    /** @return list<string> */
    private function loadedNames(PluginLoader $loader): array
    {
        return array_values(array_map(static fn ($plugin): string => $plugin->getName(), $loader->getPlugins()));
    }
}
