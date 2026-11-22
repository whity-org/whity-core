<?php

declare(strict_types=1);

namespace Whity\Core\Kernel;

use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Database\Database;

/**
 * The web kernel's wiring, booted in a process that serves no requests
 * (WHIT-1247).
 *
 * `public/index.php` is where every host service is built and registered,
 * and where plugins load with their hooks. The queue worker, the event relay
 * and the whity-cli kernel each used to build their own, smaller copy, so a
 * plugin's job or async listener found services its routes had (Contacts,
 * TimeOff, WorkspaceSetup, …) missing, and the relay loaded no plugins at all:
 * no plugin `*.async` listener ever ran.
 *
 * Now they run the same file, which stops before request handling when
 * {@see self::BOOT_FLAG} is defined, and then add what is theirs on top. It
 * boots once per process; a second call returns the first boot's objects.
 */
final class HostKernel
{
    public const BOOT_FLAG = 'WHITY_HOST_KERNEL_BOOT';

    /** @var array{db: Database, hookManager: HookManager, pluginLoader: PluginLoader, resetRequest: \Closure(): void}|null */
    private static ?array $booted = null;

    private function __construct()
    {
    }

    /**
     * Run the web kernel's wiring in this process, once.
     *
     * @return array{db: Database, hookManager: HookManager, pluginLoader: PluginLoader, resetRequest: \Closure(): void}
     */
    public static function boot(): array
    {
        if (self::$booted !== null) {
            return self::$booted;
        }
        if (!defined(self::BOOT_FLAG)) {
            define(self::BOOT_FLAG, true);
        }

        // A closure, so index.php's variables stay in its own scope (it reads
        // $argv for its own commands; here there is none) and only what it
        // returns comes out.
        $kernel = (static function (): mixed {
            return require dirname(__DIR__, 3) . '/public/index.php';
        })();

        if (!is_array($kernel)
            || !($kernel['db'] ?? null) instanceof Database
            || !($kernel['hookManager'] ?? null) instanceof HookManager
            || !($kernel['pluginLoader'] ?? null) instanceof PluginLoader
        ) {
            throw new \RuntimeException('The web kernel did not boot: public/index.php returned no kernel.');
        }

        return self::$booted = [
            'db' => $kernel['db'],
            'hookManager' => $kernel['hookManager'],
            'pluginLoader' => $kernel['pluginLoader'],
            'resetRequest' => ($kernel['resetRequest'] ?? null) instanceof \Closure
                ? $kernel['resetRequest']
                : static function (): void {
                },
        ];
    }

    /** Whether this process has booted the kernel. */
    public static function booted(): bool
    {
        return self::$booted !== null;
    }
}
