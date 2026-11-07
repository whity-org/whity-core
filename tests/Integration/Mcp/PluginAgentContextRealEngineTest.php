<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\Trust\AgentContextGrants;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpScope;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Prompts\PromptRegistry;
use Whity\Mcp\Prompts\PromptsGetHandler;
use Whity\Mcp\Prompts\PromptsListHandler;
use Whity\Mcp\Resources\PluginResourceRegistry;
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Mcp\Resources\ResourcesListHandler;
use Whity\Mcp\Resources\ResourcesReadHandler;

/**
 * Plugin prompts and resources reach AI agents only from plugins the operator
 * granted agent context, and only for callers holding their permission
 * (WHIT-993, SDK 1.78).
 *
 * Runs on real Postgres in CI (PHPUNIT_PG_DSN), SQLite locally.
 */
final class PluginAgentContextRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;
    private const OPERATOR = 1;

    private static string $pluginDir;

    private \PDO $pdo;
    private AgentContextGrants $grants;
    private PluginLoader $loader;
    private PromptRegistry $prompts;
    private PluginResourceRegistry $resources;
    private McpPrincipal $caller;
    private bool $holdsPermission = true;

    /** @var list<string> */
    private array $audited = [];

    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = sys_get_temp_dir() . '/whity_agent_context_' . uniqid();
        mkdir(self::$pluginDir . '/PriceBook', 0755, true);
        file_put_contents(self::$pluginDir . '/PriceBook/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace PriceBook;

use Whity\Sdk\Mcp\McpToolContext;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginMcpInterface;
use Whity\Sdk\PluginMcpResourcesInterface;

final class Plugin implements PluginInterface, PluginMcpInterface, PluginMcpResourcesInterface
{
    public function getName(): string    { return 'PriceBook'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getMcpPrompts(): array
    {
        return [
            [
                'name' => 'price-book-quote',
                'description' => 'Quote from the price book',
                'requiredPermission' => 'prices:read',
                'arguments' => [['name' => 'item', 'description' => 'What to quote', 'required' => true]],
                'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Quote {{item}} from the price book.']]],
            ],
            ['name' => 'price-book-open', 'description' => 'Open to everybody'],
        ];
    }

    public function getMcpResources(): array
    {
        return [
            [
                'path' => 'prices/current',
                'name' => 'Current prices',
                'description' => 'This workspace\'s price list',
                'mimeType' => 'text/csv',
                'requiredPermission' => 'prices:read',
                'read' => static fn (McpToolContext $context): string => "item,price\nbread,{$context->tenantId}.500",
            ],
            ['path' => 'prices/open', 'name' => 'No permission', 'read' => static fn (): string => 'x'],
            ['path' => '../escape', 'name' => 'Bad path', 'requiredPermission' => 'prices:read', 'read' => static fn (): string => 'x'],
        ];
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$pluginDir . '/PriceBook/Plugin.php');
        @rmdir(self::$pluginDir . '/PriceBook');
        @rmdir(self::$pluginDir);
    }

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery') ON CONFLICT DO NOTHING");
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Rana', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([self::RANA]);
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Operator', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING")->execute([self::OPERATOR]);
        SchemaFromMigrations::syncSequences($this->pdo);

        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->method('record')->willReturnCallback(function (string $event): void {
            $this->audited[] = $event;
        });
        $this->grants = new AgentContextGrants($this->pdo, $audit);

        $this->loader = new PluginLoader(self::$pluginDir, new Router(''), new PermissionRegistry(), new HookManager());
        $this->loader->load();
        $this->prompts   = new PromptRegistry();
        $this->resources = new PluginResourceRegistry();
        $this->caller    = new McpPrincipal(self::RANA, self::RANA, self::TENANT, 'user', McpScope::CAPABILITIES, 'jti-rana');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testNothingReachesAgentsUntilTheOperatorGrantsIt(): void
    {
        $this->collect();

        self::assertNull($this->prompts->find('price-book-quote'));
        self::assertSame([], $this->resources->all());
        self::assertSame([], $this->listPrompts());
        self::assertSame([], $this->listResources());
    }

    public function testAGrantedPluginsPromptsAndResourcesNeedTheirPermission(): void
    {
        self::assertTrue($this->grants->grant('PriceBook', self::OPERATOR));
        self::assertFalse($this->grants->grant('PriceBook', self::OPERATOR), 'granting twice changes nothing');
        self::assertSame(['plugin.agent_context.granted'], $this->audited);
        $this->collect();

        self::assertNull($this->prompts->find('price-book-open'), 'a prompt with no permission is refused');
        self::assertSame(['price-book-quote'], $this->listPrompts());
        $got = $this->getPrompt('price-book-quote', ['item' => 'bread']);
        self::assertSame('Quote bread from the price book.', $got['messages'][0]['content']['text']);

        $uris = $this->listResources();
        self::assertSame(['whity-plugin://pricebook/prices/current'], $uris, 'no resource without a permission, no escaping path');
        $read = $this->read('whity-plugin://pricebook/prices/current');
        self::assertSame("item,price\nbread,1.500", $read['contents'][0]['text'], 'read with the caller\'s workspace');
        self::assertSame('text/csv', $read['contents'][0]['mimeType']);

        $this->holdsPermission = false;
        self::assertSame([], $this->listPrompts());
        self::assertSame([], $this->listResources());
        $this->assertRefused(fn () => $this->read('whity-plugin://pricebook/prices/current'));
        $this->assertRefused(fn () => $this->getPrompt('price-book-quote', ['item' => 'bread']));
    }

    public function testATokenLimitedToOtherPermissionsReadsNothing(): void
    {
        $this->grants->grant('PriceBook', self::OPERATOR);
        $this->collect();
        $this->caller = new McpPrincipal(self::RANA, self::RANA, self::TENANT, 'user', ['resources:read', 'documents:read'], 'jti-narrow');

        self::assertSame([], $this->listResources());
        $this->assertRefused(fn () => $this->read('whity-plugin://pricebook/prices/current'));
    }

    public function testARevokeAppliesToTheNextCall(): void
    {
        $this->grants->grant('PriceBook', self::OPERATOR);
        $this->collect();
        self::assertNotSame([], $this->listResources());

        self::assertTrue($this->grants->revoke('PriceBook', self::OPERATOR));
        self::assertSame(['plugin.agent_context.granted', 'plugin.agent_context.revoked'], $this->audited);

        // Nothing reloaded: the registries still hold them, and still nothing is served.
        self::assertNotNull($this->prompts->find('price-book-quote'));
        self::assertSame([], $this->listPrompts());
        self::assertSame([], $this->listResources());
        $this->assertRefused(fn () => $this->read('whity-plugin://pricebook/prices/current'));
        $this->assertRefused(fn () => $this->getPrompt('price-book-quote', ['item' => 'bread']));
    }

    private function collect(): void
    {
        $names   = array_flip($this->grants->granted());
        $granted = static fn (string $plugin): bool => isset($names[$plugin]);
        $this->loader->collectMcpPrompts($this->prompts, $granted);
        $this->loader->collectMcpResources($this->resources, $granted);
    }

    private function roles(): RoleChecker
    {
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(fn (): bool => $this->holdsPermission);
        $roles->method('hasRoleForProfile')->willReturnCallback(fn (): bool => $this->holdsPermission);

        return $roles;
    }

    private function tokens(): TokenValidator
    {
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateMcpToken')->willReturnCallback(fn (): McpPrincipal => $this->caller);

        return $tokens;
    }

    private function live(): \Closure
    {
        return fn (string $plugin): bool => $this->grants->isGranted($plugin);
    }

    /** @return list<string> */
    private function listPrompts(): array
    {
        $result = (new PromptsListHandler($this->prompts, $this->roles(), $this->tokens(), $this->live()))(null, 'bearer');
        self::assertIsArray($result);

        return array_column($result['prompts'], 'name');
    }

    /**
     * @param array<string, string> $arguments
     * @return array<string, mixed>
     */
    private function getPrompt(string $name, array $arguments): array
    {
        $result = (new PromptsGetHandler($this->prompts, $this->roles(), $this->tokens(), $this->live()))(['name' => $name, 'arguments' => $arguments], 'bearer');
        self::assertIsArray($result);

        return $result;
    }

    /** @return list<string> */
    private function listResources(): array
    {
        $deriver = new ResourceDeriver([], new Router(''));
        $result  = (new ResourcesListHandler($deriver, $this->roles(), $this->tokens(), $this->resources, $this->live()))(null, 'bearer');
        self::assertIsArray($result);

        return array_column($result['resources'], 'uri');
    }

    /** @return array<string, mixed> */
    private function read(string $uri): array
    {
        $result = (new ResourcesReadHandler(new Router(''), $this->roles(), $this->tokens(), pluginResources: $this->resources, pluginGranted: $this->live()))(['uri' => $uri], 'bearer');
        self::assertIsArray($result);

        return $result;
    }

    private function assertRefused(\Closure $call): void
    {
        try {
            $call();
        } catch (McpException) {
            $this->addToAssertionCount(1);
            return;
        }
        self::fail('expected the call to be refused');
    }
}
