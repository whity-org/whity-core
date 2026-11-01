<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Agent;

use PHPUnit\Framework\TestCase;
use Whity\Core\Agent\AgentPresetRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Mcp\Tools\CoreToolsets;
use Whity\OpenAPI\CoreApiSchemas;
use Whity\Sdk\Manifest\ManifestValidator;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * Core's agent presets (WHIT-815, database/agent-presets) are our own data, so
 * every rule is checked here against the real files, and the loader's own
 * refusals are proved on bad ones.
 */
final class AgentPresetRegistryTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*.json') ?: []);
            rmdir($this->dir);
        }
    }

    public function testCoreShipsFivePresetsInBothLanguages(): void
    {
        $presets = (new AgentPresetRegistry())->all();

        self::assertSame(
            ['core.billing-assistant', 'core.document-drafter', 'core.finance-clerk', 'core.front-desk', 'core.hr-assistant'],
            array_column($presets, 'key'),
        );
        foreach ($presets as $preset) {
            foreach (['name', 'name_ar', 'role', 'role_ar', 'instructions', 'instructions_ar'] as $field) {
                self::assertNotSame('', $preset[$field], "{$preset['key']} {$field}");
            }
            // Register 5.6a: agents don't say "I".
            self::assertDoesNotMatchRegularExpression('/\bI\b/', $preset['instructions'] . ' ' . $preset['role'], $preset['key']);
        }
    }

    public function testEveryToolsetAndPermissionExists(): void
    {
        $permissions = CorePermissions::all();
        foreach ((new AgentPresetRegistry())->all() as $preset) {
            foreach ($preset['toolsets'] as $toolset) {
                $id = str_ends_with($toolset, '.*') ? substr($toolset, 0, -2) : $toolset;
                self::assertArrayHasKey($id, CoreToolsets::TOOLSETS, "{$preset['key']} toolset {$toolset}");
            }
            foreach (array_keys($preset['grant']) as $permission) {
                self::assertContains($permission, $permissions, "{$preset['key']} permission {$permission}");
            }
        }
    }

    public function testNoPresetAllowsAWrite(): void
    {
        // Presets set where a grant starts; only the person sets a write to
        // Allowed (design §4.4). Reads are `:read` and `:view` here.
        foreach ((new AgentPresetRegistry())->all() as $preset) {
            foreach ($preset['grant'] as $permission => $mode) {
                if ($mode === 'allow') {
                    self::assertMatchesRegularExpression('/:(read|view)$/', $permission, "{$preset['key']} allows {$permission}");
                }
            }
        }
    }

    public function testAgentWritesAreRefusedToAgentsAndOnTheAgentsToolset(): void
    {
        $seen = 0;
        foreach (CoreApiSchemas::routes() as $route) {
            if (!str_starts_with($route['path'], '/api/agent')) {
                continue;
            }
            $seen++;
            self::assertSame('agents:manage', $route['requiredPermission'], $route['path']);
            self::assertSame(['agents'], $route['schema']['tags']);
            if ($route['method'] !== 'GET') {
                self::assertSame('refuse', $route['schema']['mcp']['agentPolicy'] ?? null, "{$route['method']} {$route['path']}");
            }
        }
        self::assertSame(7, $seen, 'every agents route is checked');
        self::assertSame('core.agents', CoreToolsets::TAG_TOOLSET['agents']);
    }

    public function testABadPresetIsABuildError(): void
    {
        $this->dir = sys_get_temp_dir() . '/agent-presets-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/clerk.json', (string) json_encode([
            'key' => 'core.clerk', 'version' => 1, 'name' => 'Clerk', 'name_ar' => 'كاتب', 'role' => 'r', 'role_ar' => 'r',
            'instructions' => 'i', 'instructions_ar' => 'i', 'toolsets' => ['core.nowhere'],
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('core.nowhere');
        new AgentPresetRegistry($this->dir);
    }

    public function testAPresetThatStartsAWriteAllowedIsABuildError(): void
    {
        $this->dir = sys_get_temp_dir() . '/agent-presets-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/clerk.json', (string) json_encode([
            'key' => 'core.clerk', 'version' => 1, 'name' => 'Clerk', 'name_ar' => 'كاتب', 'role' => 'r', 'role_ar' => 'r',
            'instructions' => 'i', 'instructions_ar' => 'i', 'grant' => ['documents:issue' => 'allow'],
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("documents:issue can't start Allowed");
        new AgentPresetRegistry($this->dir);
    }

    public function testACodePluginsPresetsJoinUnderItsOwnRoot(): void
    {
        $registry = new AgentPresetRegistry();

        $refused = $registry->registerPlugin('Plugins\HelloWorld\HelloWorldPlugin', [self::pluginPreset('greeter')], static fn (string $p): bool => true);

        self::assertSame([], $refused);
        $preset = $registry->find(1, 'hello-world.greeter');
        self::assertSame('Greeter', $preset['name'] ?? null);
        self::assertSame('مرحِّب', $preset['name_ar'] ?? null);
        self::assertCount(6, $registry->forTenant(1));

        $registry->unregisterPlugin('Plugins\HelloWorld\HelloWorldPlugin');
        self::assertNull($registry->find(1, 'hello-world.greeter'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badPluginPresets(): iterable
    {
        yield 'a write starting Allowed' => [['grant' => ['documents:issue' => 'allow']], "can't start Allowed"];
        yield "another plugin's toolset" => [['toolsets' => ['elmak.invoices']], "another plugin's"];
        yield 'an unknown core toolset' => [['toolsets' => ['core.nowhere']], 'not a core toolset'];
        yield 'an unknown permission' => [['grant' => ['nothing:read' => 'allow']], "doesn't exist"];
    }

    /**
     * @dataProvider badPluginPresets
     * @param array<string, mixed> $overrides
     */
    public function testABadPluginPresetIsDroppedWithAReason(array $overrides, string $reason): void
    {
        $registry = new AgentPresetRegistry();

        $refused = $registry->registerPlugin(
            'Plugins\HelloWorld\HelloWorldPlugin',
            [self::pluginPreset('bad', $overrides), self::pluginPreset('good')],
            static fn (string $p): bool => $p !== 'nothing:read',
        );

        self::assertStringContainsString($reason, implode("\n", $refused));
        self::assertNull($registry->find(1, 'hello-world.bad'));
        self::assertNotNull($registry->find(1, 'hello-world.good'), 'the rest of the plugin still loads');
    }

    public function testAWorkspacesPluginPresetsReachOnlyThatWorkspace(): void
    {
        $good = self::pluginPreset('order-desk', ['toolsets' => ['cake-orders.*', 'core.documents'], 'grant' => ['cake_orders:read' => 'allow']]);
        unset($good['key']);
        $manifest = PluginManifest::fromArray([
            'manifest'     => ManifestValidator::FORMAT,
            'plugin'       => ['name' => 'CakeOrders', 'version' => '1.0.0', 'label' => ['en' => 'Cake orders', 'ar' => 'طلبات الكعك']],
            'requires'     => ['sdk' => '^1.61'],
            'permissions'  => [['slug' => 'cake_orders:read', 'label' => ['en' => 'Read', 'ar' => 'قراءة']]],
            'agentPresets' => ['order-desk' => $good],
        ]);

        $registry = new AgentPresetRegistry();
        $registry->useManifests(static fn (int $tenant): array => $tenant === 1 ? [$manifest] : []);

        self::assertNotNull($registry->find(1, 'cake-orders.order-desk'));
        self::assertNull($registry->find(2, 'cake-orders.order-desk'), 'another workspace never sees it');
        self::assertNull($registry->find(0, 'cake-orders.order-desk'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function pluginPreset(string $key, array $overrides = []): array
    {
        return $overrides + [
            'key'          => $key,
            'name'         => ['en' => 'Greeter', 'ar' => 'مرحِّب'],
            'role'         => ['en' => 'Greets people.', 'ar' => 'يرحّب بالناس.'],
            'instructions' => ['en' => 'Be brief.', 'ar' => 'كن موجزًا.'],
        ];
    }

    public function testAPresetKeyMustMatchItsFile(): void
    {
        $this->dir = sys_get_temp_dir() . '/agent-presets-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/clerk.json', (string) json_encode(['key' => 'core.other', 'version' => 1, 'name' => 'Clerk']));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('key must be core.clerk');
        new AgentPresetRegistry($this->dir);
    }
}
