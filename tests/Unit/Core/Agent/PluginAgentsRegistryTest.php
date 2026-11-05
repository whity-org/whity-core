<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Agent;

use PHPUnit\Framework\TestCase;
use Whity\Core\Agent\AgentPresetRegistry;
use Whity\Mcp\Tools\CoreToolsets;

/**
 * SDK 1.64 (WHIT-893): a code plugin's declared agents join its presets, so a
 * workspace starts an agent from one as a copy. Each lands under the plugin's
 * own root; another plugin's, core's and a write at Allowed are refused.
 */
final class PluginAgentsRegistryTest extends TestCase
{
    private const PLUGIN = 'Plugins\\Acme\\Collections\\CollectionsPlugin';
    private const OTHER  = 'Plugins\\Other\\Thing\\ThingPlugin';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function agent(string $key, array $overrides = []): array
    {
        return array_merge([
            'key'          => $key,
            'version'      => 1,
            'name'         => ['en' => 'Clerk', 'ar' => 'موظف'],
            'role'         => ['en' => 'Follows up invoices.', 'ar' => 'يتابع الفواتير.'],
            'instructions' => ['en' => 'Be brief.', 'ar' => 'كن موجزا.'],
        ], $overrides);
    }

    private static function allow(): callable
    {
        return static fn (string $p): bool => in_array($p, ['documents:read', 'documents:issue'], true);
    }

    public function testADeclaredAgentAppearsUnderThePluginRootWithItsFeatures(): void
    {
        $registry = new AgentPresetRegistry(self::emptyDir());
        $refused  = $registry->registerPluginAgents(self::PLUGIN, [
            self::agent('clerk', [
                'toolsets' => ['core.documents'],
                'grant'    => ['documents:read' => 'allow', 'documents:issue' => 'ask'],
                'features' => ['suggestions' => true, 'proposals' => false],
            ]),
        ], self::allow());

        self::assertSame([], $refused);
        $root   = CoreToolsets::pluginToolsetIdForClass(self::PLUGIN);
        $preset = $registry->find(1, $root . '.clerk');
        self::assertNotNull($preset);
        self::assertSame(['suggestions' => true, 'proposals' => false], $preset['features']);
    }

    public function testADeclaredAgentIsOfferedToEveryWorkspaceAsACopySource(): void
    {
        $registry = new AgentPresetRegistry(self::emptyDir());
        $registry->registerPluginAgents(self::PLUGIN, [self::agent('clerk')], self::allow());

        $root = CoreToolsets::pluginToolsetIdForClass(self::PLUGIN);
        self::assertNotNull($registry->find(1, $root . '.clerk'));
        self::assertNotNull($registry->find(2, $root . '.clerk'));
    }

    public function testAPluginCannotDeclareAnAgentUnderCoreOrAnotherPlugin(): void
    {
        $registry = new AgentPresetRegistry(self::emptyDir());
        $registry->registerPluginAgents(self::PLUGIN, [
            self::agent('finance-clerk'),
            self::agent('clerk', ['toolsets' => ['other.thing']]),
        ], self::allow());

        $root = CoreToolsets::pluginToolsetIdForClass(self::PLUGIN);
        // The key is always under the plugin's own root, never core's.
        self::assertNull($registry->find(1, 'core.finance-clerk'));
        self::assertNotNull($registry->find(1, $root . '.finance-clerk'));
        // A toolset that is another plugin's is refused, so that agent is not offered.
        self::assertNull($registry->find(1, $root . '.clerk'));
    }

    public function testAWriteCannotStartAtAllowed(): void
    {
        $registry = new AgentPresetRegistry(self::emptyDir());
        $refused  = $registry->registerPluginAgents(self::PLUGIN, [
            self::agent('clerk', ['grant' => ['documents:issue' => 'allow']]),
        ], self::allow());

        $root = CoreToolsets::pluginToolsetIdForClass(self::PLUGIN);
        self::assertNull($registry->find(1, $root . '.clerk'));
        self::assertNotEmpty($refused);
    }

    public function testAnUnexposedFeatureIsDroppedNotEnabled(): void
    {
        $registry = new AgentPresetRegistry(self::emptyDir());
        $registry->registerPluginAgents(self::PLUGIN, [
            self::agent('clerk', ['features' => ['tenant_wide_memory' => true]]),
        ], self::allow());

        $root = CoreToolsets::pluginToolsetIdForClass(self::PLUGIN);
        // Refused by the validator; nothing is switched on.
        self::assertNull($registry->find(1, $root . '.clerk'));
    }

    public function testARedeclarationWithAHigherVersionCustomizesTheAgentForNewCopies(): void
    {
        $registry = new AgentPresetRegistry(self::emptyDir());
        $registry->registerPluginAgents(self::PLUGIN, [
            self::agent('clerk', ['version' => 1, 'features' => ['suggestions' => true]]),
        ], self::allow());
        $registry->registerPluginAgents(self::PLUGIN, [
            self::agent('clerk', ['version' => 2, 'features' => ['suggestions' => false, 'proposals' => true]]),
        ], self::allow());

        $root   = CoreToolsets::pluginToolsetIdForClass(self::PLUGIN);
        $preset = $registry->find(1, $root . '.clerk');
        assert($preset !== null);
        self::assertSame(2, $preset['version']);
        self::assertSame(['suggestions' => false, 'proposals' => true], $preset['features']);
    }

    public function testOneRegistrationNeverChangesAnotherPluginsAgents(): void
    {
        $registry = new AgentPresetRegistry(self::emptyDir());
        $registry->registerPluginAgents(self::PLUGIN, [self::agent('clerk')], self::allow());
        $registry->registerPluginAgents(self::OTHER, [], self::allow());

        $root = CoreToolsets::pluginToolsetIdForClass(self::PLUGIN);
        self::assertNotNull($registry->find(1, $root . '.clerk'));
    }

    public function testUnregisteringAPluginRemovesItsAgents(): void
    {
        $registry = new AgentPresetRegistry(self::emptyDir());
        $registry->registerPluginAgents(self::PLUGIN, [self::agent('clerk')], self::allow());
        $registry->unregisterPlugin(self::PLUGIN);

        $root = CoreToolsets::pluginToolsetIdForClass(self::PLUGIN);
        self::assertNull($registry->find(1, $root . '.clerk'));
    }

    private static function emptyDir(): string
    {
        $dir = sys_get_temp_dir() . '/whity-agents-empty-' . bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }
}
