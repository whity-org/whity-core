<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inbox\InboxSourceRegistry;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * Inbox sources and message channels through the real PluginLoader (SDK 1.97,
 * #881, ADR 0016): each lands under the plugin's own namespace, and a plugin
 * whose declaration is bad, or whose getter throws, keeps loading without
 * contributing anything.
 */
final class PluginLoaderMessagingTest extends TestCase
{
    private static string $dir;

    /** name => [getInboxSources body, getMessageChannels body] */
    private const PLUGINS = [
        'GoodMessaging' => ["['tasks' => new MsgSource()]", '[new MsgChannel()]'],
        'BadKeys' => ["['Bad Key' => new MsgSource()]", "['not a channel']"],
        'Throws' => ["throw new \\RuntimeException('boom')", "throw new \\RuntimeException('boom')"],
    ];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_messaging_' . uniqid();
        foreach (self::PLUGINS as $name => [$sources, $channels]) {
            mkdir(self::$dir . '/' . $name, 0755, true);
            $sourcesBody = str_starts_with($sources, 'throw') ? "{$sources};" : "return {$sources};";
            $channelsBody = str_starts_with($channels, 'throw') ? "{$channels};" : "return {$channels};";
            file_put_contents(self::$dir . "/{$name}/Plugin.php", <<<PHP
<?php
declare(strict_types=1);
namespace {$name};

use Whity\Sdk\Inbox\InboxSource;
use Whity\Sdk\Messaging\ChannelCapabilities;
use Whity\Sdk\Messaging\MessageChannel;
use Whity\Sdk\Messaging\OutboundMessage;
use Whity\Sdk\Messaging\SendOutcome;
use Whity\Sdk\PluginInboxSourcesInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginMessageChannelsInterface;

final class MsgSource implements InboxSource
{
    public function label(): string { return 'Tasks'; }
    public function list(int \$t, int \$p, bool \$o, int \$l, int \$f): array { return []; }
    public function count(int \$t, int \$p, bool \$o): int { return 0; }
}

final class MsgChannel implements MessageChannel
{
    public function key(): string { return 'sms'; }
    public function label(): array { return ['en' => 'SMS', 'ar' => 'رسائل نصية']; }
    public function isEnabled(int \$t): bool { return true; }
    public function capabilities(): ChannelCapabilities { return new ChannelCapabilities(maxBodyLength: 1600); }
    public function send(OutboundMessage \$m): SendOutcome { return SendOutcome::sent('x'); }
    public function receive(int \$t, string \$b, array \$h): array { return []; }
    public function templates(int \$t): array { return []; }
}

final class Plugin implements PluginInterface, PluginInboxSourcesInterface, PluginMessageChannelsInterface
{
    public function getName(): string    { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
    public function getInboxSources(): array { {$sourcesBody} }
    public function getMessageChannels(): array { {$channelsBody} }
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

    public function testContributionsAreNamespacedAndBadOnesAreDropped(): void
    {
        $permissions = new PermissionRegistry();
        $permissions->registerCorePermissions();
        $loader = new PluginLoader(self::$dir, new Router(''), $permissions, new HookManager());
        $inbox = new InboxSourceRegistry();
        $channels = new MessageChannelRegistry();
        $loader->setInboxSources($inbox);
        $loader->setMessageChannels($channels);
        $loader->load();

        $names = array_map(static fn ($plugin): string => $plugin->getName(), $loader->getPlugins());
        self::assertContains('BadKeys', $names, 'A plugin with a bad declaration keeps loading.');

        self::assertSame(['goodmessaging:tasks'], $inbox->keys());
        self::assertSame(['goodmessaging:sms'], $channels->keys());
        self::assertSame('GoodMessaging', $channels->origin('goodmessaging:sms'));
    }
}
