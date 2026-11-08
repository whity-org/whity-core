<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * WHIT-1016: a `secretField` block only names a secret its plugin declares, and
 * the host alone says where the field reads and writes.
 */
final class PluginLoaderSecretFieldBlockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/whity_secret_field_' . uniqid();
    }

    protected function tearDown(): void
    {
        $file = glob($this->dir . '/*/Plugin.php') ?: [];
        foreach ($file as $path) {
            unlink($path);
            rmdir(dirname($path));
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testADeclaredSecretGetsTheHostsEndpoint(): void
    {
        $node = $this->served('SecretDeclared', "['type' => 'secretField', 'secret' => 'api_key', 'label' => 'API key']");

        self::assertNotNull($node);
        self::assertSame('/api/v1/plugin-secrets/secretdeclared/api_key', $node['endpoint']);
    }

    public function testAnEndpointThePluginWroteIsReplaced(): void
    {
        $node = $this->served('SecretSneaky', "['type' => 'secretField', 'secret' => 'api_key', 'label' => 'API key', 'endpoint' => '/api/other/steal']");

        self::assertNotNull($node);
        self::assertSame('/api/v1/plugin-secrets/secretsneaky/api_key', $node['endpoint']);
    }

    public function testAnUndeclaredSecretDropsTheScreen(): void
    {
        self::assertNull($this->served('SecretUnknown', "['type' => 'secretField', 'secret' => 'not_declared', 'label' => 'API key']"));
    }

    /** @return array<string, mixed>|null the served block, null when the screen was dropped */
    private function served(string $name, string $block): ?array
    {
        mkdir($this->dir . '/' . $name, 0755, true);
        file_put_contents($this->dir . '/' . $name . '/Plugin.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$name};

use Whity\\Sdk\\PluginFrontendInterface;
use Whity\\Sdk\\PluginInterface;
use Whity\\Sdk\\Secrets\\PluginSecretFieldsInterface;

final class Plugin implements PluginInterface, PluginFrontendInterface, PluginSecretFieldsInterface
{
    public function getName(): string { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getSdkConstraint(): string { return '^1.7'; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return []; }
    public function getPermissions(): array { return ['x:manage']; }
    public function getRoutes(): array { return []; }
    public function getSecretFields(): array { return ['api_key' => ['permission' => 'x:manage']]; }
    public function getFrontendFeatures(): array
    {
        return [['id' => 'secrets', 'label' => 'Secrets', 'screen' => 'blocks', 'requiredPermission' => 'x:manage', 'blocks' => [{$block}]]];
    }
}
PHP);

        $loader = new PluginLoader($this->dir, new Router('/v1'), new PermissionRegistry(), new HookManager());
        $loader->load();
        $feature = array_column($loader->getFrontendFeatures(), null, 'id')['secrets'] ?? null;

        return $feature === null ? null : $feature['blocks'][0];
    }
}
