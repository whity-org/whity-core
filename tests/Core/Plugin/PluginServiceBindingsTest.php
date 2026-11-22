<?php

declare(strict_types=1);

namespace Tests\Core\Plugin;

use PHPUnit\Framework\TestCase;

/**
 * Which SDK services each entry point binds (WHIT-1243, WHIT-1247).
 *
 * A plugin's route, command, job and async listener are the same code, so a
 * service its route reaches and its job doesn't fails in production only, at
 * night, in the worker.
 *
 * Since WHIT-1247 every entry point that runs plugin code boots the web
 * kernel's own wiring first ({@see \Whity\Core\Kernel\HostKernel::boot()},
 * which runs public/index.php up to request handling), then adds its own. So
 * each one binds everything the web binds, by construction. This reads each
 * entry point's `register_service()` calls, follows the kernel boot into
 * public/index.php, and checks that:
 *
 *  - every entry point binds the plugin stores and what a job needs, and
 *  - no entry point binds fewer SDK services than the web. There is no list of
 *    allowed gaps.
 */
final class PluginServiceBindingsTest extends TestCase
{
    /** Each entry point's own wiring; one that calls HostKernel::boot() also gets public/index.php's. */
    private const ENTRY_POINTS = [
        'web' => ['public/index.php', 'src/Core/Plugin/HostPluginServices.php'],
        'cli' => ['src/Cli/Commands/BaseCommand.php', 'src/Core/Plugin/HostPluginServices.php'],
        'worker' => ['src/Cli/Commands/QueueWorkCommand.php', 'src/Core/Plugin/HostPluginServices.php', 'src/Core/Document/Issuing/WorkerDocumentServices.php'],
        'relay' => ['src/Cli/Commands/EventRelayCommand.php'],
    ];

    /** Every entry point where plugin code runs binds these. */
    private const EVERYWHERE = [
        'Whity\Sdk\PluginContext',
        'Whity\Sdk\Settings\PluginSettings',
        'Whity\Sdk\Secrets\PluginSecrets',
        'Whity\Sdk\Storage\PluginFiles',
        'Whity\Sdk\Documents\PluginDocuments',
        'Whity\Sdk\Notification\PluginNotifier',
        'Whity\Sdk\Jobs\PluginJobQueue',
        'Whity\Sdk\Audit\PluginAudit',
        'Whity\Sdk\Documents\Documents',
        'Whity\Sdk\Documents\Templates',
        'Whity\Sdk\Render\DocumentRenderer',
        'Whity\Sdk\Billing\PlanCatalogue',
        'Whity\Sdk\Contacts\Contacts',
        'Whity\Sdk\Calendar\TimeOff',
        'Whity\Sdk\Messaging\CustomerMessages',
    ];

    public function testEveryEntryPointBootsTheWebKernel(): void
    {
        foreach (['cli', 'worker', 'relay'] as $entry) {
            self::assertTrue(self::bootsTheKernel($entry), "{$entry} doesn't call HostKernel::boot(), so it misses the web kernel's services");
        }
    }

    public function testTheKernelStopsBeforeServingAndReturnsItsObjects(): void
    {
        $index = (string) file_get_contents(self::root() . '/public/index.php');
        $stop = strpos($index, "if (defined('WHITY_HOST_KERNEL_BOOT')) {");
        $serve = strpos($index, '$isWorker = function_exists(\'frankenphp_handle_request\');');

        self::assertNotFalse($stop, 'public/index.php no longer stops for HostKernel::boot()');
        self::assertNotFalse($serve);
        self::assertLessThan($serve, $stop, 'the boot-only return comes before request handling');
        self::assertSame('WHITY_HOST_KERNEL_BOOT', \Whity\Core\Kernel\HostKernel::BOOT_FLAG);
    }

    public function testEveryEntryPointBindsThePluginStoresAndWhatAJobNeeds(): void
    {
        foreach (array_keys(self::ENTRY_POINTS) as $entry) {
            $missing = array_values(array_diff(self::EVERYWHERE, self::bound($entry)));
            self::assertSame([], $missing, "{$entry} doesn't bind these, so plugin code there can't reach them");
        }
    }

    public function testNoEntryPointBindsFewerSdkServicesThanTheWeb(): void
    {
        $web = self::bound('web');
        foreach (['cli', 'worker', 'relay'] as $entry) {
            $gaps = array_values(array_diff($web, self::bound($entry)));
            self::assertSame([], $gaps, "The web binds these and {$entry} doesn't");
        }
    }

    public function testTheRelayDeliversIntoTheKernelsHookManager(): void
    {
        $relay = (string) file_get_contents(self::root() . '/src/Cli/Commands/EventRelayCommand.php');

        self::assertStringContainsString("\$hooks = \$kernel['hookManager'];", $relay, 'the relay must dispatch where the plugins are loaded');
        self::assertStringNotContainsString('new HookManager()', $relay, 'an empty HookManager reaches no plugin listener');
    }

    private static function bootsTheKernel(string $entry): bool
    {
        $source = (string) file_get_contents(self::root() . '/' . self::ENTRY_POINTS[$entry][0]);

        return str_contains($source, '\Whity\Core\Kernel\HostKernel::boot()');
    }

    /** @return list<string> */
    private static function bound(string $entry): array
    {
        $files = self::ENTRY_POINTS[$entry];
        if ($entry !== 'web' && self::bootsTheKernel($entry)) {
            $files = [...$files, ...self::ENTRY_POINTS['web']];
        }

        $bound = [];
        foreach (array_unique($files) as $file) {
            $source = (string) file_get_contents(self::root() . '/' . $file);
            $uses = [];
            preg_match_all('/^use\s+(Whity\\\\[A-Za-z\\\\]+?)(?:\s+as\s+(\w+))?;/m', $source, $useMatches, PREG_SET_ORDER);
            foreach ($useMatches as $use) {
                $alias = $use[2] ?? '';
                $uses[$alias !== '' ? $alias : substr($use[1], (int) strrpos($use[1], '\\') + 1)] = $use[1];
            }
            preg_match_all('/register_service\(\s*\\\\?([A-Za-z\\\\]+)::class/', $source, $matches);
            foreach ($matches[1] as $name) {
                $fqn = str_contains($name, '\\') ? $name : ($uses[$name] ?? $name);
                if (str_starts_with($fqn, 'Whity\Sdk\\')) {
                    $bound[] = $fqn;
                }
            }
        }
        $bound = array_values(array_unique($bound));
        sort($bound);

        return $bound;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
