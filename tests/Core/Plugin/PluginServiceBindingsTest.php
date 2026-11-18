<?php

declare(strict_types=1);

namespace Tests\Core\Plugin;

use PHPUnit\Framework\TestCase;

/**
 * Which SDK services each entry point binds (WHIT-1243).
 *
 * A plugin's route, command and job are the same code, so a service its route
 * reaches and its job doesn't fails in production only, at night, in the
 * worker. This reads each entry point's `register_service()` calls and pins:
 *
 *  - what every entry point must bind (the plugin's own stores and the services
 *    a job needs), and
 *  - what each one is KNOWN not to bind yet, so a new gap fails here and a
 *    closed gap is taken off the list.
 *
 * `events:relay` loads no plugins at all, so no plugin code runs there and a
 * plugin's `*.async` listener is never called: that is the relay's own gap,
 * pinned below until the relay loads plugins (WHIT-1243 follow-up).
 */
final class PluginServiceBindingsTest extends TestCase
{
    private const ENTRY_POINTS = [
        'web' => ['public/index.php', 'src/Core/Plugin/HostPluginServices.php'],
        'cli' => ['src/Cli/Commands/BaseCommand.php', 'src/Core/Plugin/HostPluginServices.php'],
        'worker' => ['src/Cli/Commands/QueueWorkCommand.php', 'src/Core/Plugin/HostPluginServices.php', 'src/Core/Document/Issuing/WorkerDocumentServices.php'],
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
    ];

    /**
     * SDK services the web binds and this entry point doesn't, today. Each needs
     * the web kernel's collaborators (role checker, mailer, billing portal),
     * so closing one means sharing that wiring, not copying a line. Take an
     * entry off when it is bound; never add one to make a test pass.
     */
    private const KNOWN_GAPS = [
        'cli' => [
            'Whity\Sdk\Billing\AccessRefresh',
            'Whity\Sdk\Billing\LicenceSales',
            'Whity\Sdk\Billing\WorkspaceAddons',
            'Whity\Sdk\Calendar\Bookings',
            'Whity\Sdk\Calendar\CalendarEvents',
            'Whity\Sdk\Calendar\TimeOff',
            'Whity\Sdk\Contacts\Contacts',
            'Whity\Sdk\Delegation\Away',
            'Whity\Sdk\Identity\LinkedPeople',
            'Whity\Sdk\Licensing\StoreLicence',
            'Whity\Sdk\Mail\BillingEmails',
            'Whity\Sdk\Mail\WorkspaceEmails',
            'Whity\Sdk\Messaging\CustomerMessages',
            'Whity\Sdk\Setup\WorkspaceSetup',
        ],
        'worker' => [
            'Whity\Sdk\Agents\Agents',
            'Whity\Sdk\Billing\AccessRefresh',
            'Whity\Sdk\Billing\LicenceSales',
            'Whity\Sdk\Billing\WorkspaceAddons',
            'Whity\Sdk\Calendar\Bookings',
            'Whity\Sdk\Calendar\CalendarEvents',
            'Whity\Sdk\Calendar\TimeOff',
            'Whity\Sdk\Contacts\Contacts',
            'Whity\Sdk\DataType\DataTypeGuard',
            'Whity\Sdk\DataType\DataTypeLifecycle',
            'Whity\Sdk\Delegation\Away',
            'Whity\Sdk\Identity\LinkedPeople',
            'Whity\Sdk\Licensing\StoreLicence',
            'Whity\Sdk\Mail\BillingEmails',
            'Whity\Sdk\Mail\WorkspaceEmails',
            'Whity\Sdk\Mcp\McpConnections',
            'Whity\Sdk\Rbac\ResourceScope',
            'Whity\Sdk\Setup\WorkspaceSetup',
            'Whity\Sdk\Sql\SequenceAllocator',
        ],
    ];

    public function testEveryEntryPointBindsThePluginStoresAndWhatAJobNeeds(): void
    {
        foreach (array_keys(self::ENTRY_POINTS) as $entry) {
            $missing = array_values(array_diff(self::EVERYWHERE, self::bound($entry)));
            self::assertSame([], $missing, "{$entry} doesn't bind these, so plugin code there can't reach them");
        }
    }

    public function testTheGapsBehindTheWebAreTheKnownOnes(): void
    {
        $web = self::bound('web');
        foreach (self::KNOWN_GAPS as $entry => $known) {
            $gaps = array_values(array_diff($web, self::bound($entry)));
            sort($gaps);
            // PermissionResolver is bound at every entry point through its own
            // wiring helper rather than a literal register_service() call.
            $gaps = array_values(array_diff($gaps, ['Whity\Sdk\Rbac\PermissionResolver']));
            self::assertSame($known, $gaps, "The SDK services the web binds and {$entry} doesn't changed. Bind a new one there too, or take a closed gap off KNOWN_GAPS.");
        }
    }

    public function testTheEventRelayLoadsNoPluginsYet(): void
    {
        $relay = (string) file_get_contents(self::root() . '/src/Cli/Commands/EventRelayCommand.php');

        self::assertStringNotContainsString(
            'PluginLoader',
            $relay,
            'The relay now loads plugins: good. Remove this pin and add "relay" to ENTRY_POINTS, so its bindings are checked like the others.',
        );
    }

    /** @return list<string> */
    private static function bound(string $entry): array
    {
        $bound = [];
        foreach (self::ENTRY_POINTS[$entry] as $file) {
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
