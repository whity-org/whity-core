<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TaxonomyTestSeed;
use Whity\Api\AgentActionsApiHandler;
use Whity\Api\TagGroupsApiHandler;
use Whity\Api\TagsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentActionRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * The undo the tag and tag group routes declare (WHIT-812), against their
 * real handlers, repositories and RBAC: the pointers in the declarations must
 * match what these routes really answer.
 */
final class TaxonomyUndoRealEngineTest extends TestCase
{
    private const TENANT  = 1;
    private const MANAGER = 10; // tags:read + tags:manage in tenant 1

    private PDO $pdo;
    private ToolsCallHandler $toolsCall;
    private AgentActionsApiHandler $undoApi;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        ToolDeriver::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->pdo = TaxonomyTestSeed::make();
        $roles     = new RoleChecker(TaxonomyTestSeed::wrap($this->pdo), new PermissionRegistry());
        $groups    = new TagGroupRepository($this->pdo);
        $groupApi  = new TagGroupsApiHandler($groups, $roles);
        $tagApi    = new TagsApiHandler(new TagRepository($this->pdo), $groups, $roles);

        $router = new Router('');
        foreach ([
            ['POST', '/api/tag-groups', [$groupApi, 'create']], ['GET', '/api/tag-groups/{id:\d+}', [$groupApi, 'show']],
            ['PATCH', '/api/tag-groups/{id:\d+}', [$groupApi, 'update']], ['DELETE', '/api/tag-groups/{id:\d+}', [$groupApi, 'delete']],
            ['POST', '/api/tags', [$tagApi, 'create']], ['GET', '/api/tags/{id:\d+}', [$tagApi, 'show']],
            ['PATCH', '/api/tags/{id:\d+}', [$tagApi, 'update']], ['DELETE', '/api/tags/{id:\d+}', [$tagApi, 'delete']],
        ] as [$method, $path, $handler]) {
            $router->registerUnversioned($method, $path, $handler);
        }
        // The real declarations, undo included.
        $declarations = array_values(array_filter(
            CoreApiSchemas::routes(),
            static fn (array $route): bool => str_starts_with((string) $route['path'], '/api/tag'),
        ));
        $deriver = new ToolDeriver($declarations, [], $router, static fn (string $m): null => null);

        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(new McpPrincipal(
            self::MANAGER, self::MANAGER, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call', 'grant:tags:manage=allow'], 'jti-tidy-agent',
        ));
        $actions         = new AgentActionRepository($this->pdo);
        $this->toolsCall = new ToolsCallHandler($deriver, $router, $roles, $tokens, proposals: new AgentProposalRepository($this->pdo), actions: $actions);
        $this->undoApi   = new AgentActionsApiHandler($actions, $this->toolsCall);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        ToolDeriver::clearCache();
    }

    public function testCreatingATagGroupAndATagIsUndoneByDeletingThem(): void
    {
        $group = $this->call('post_api_tag_groups', ['key' => 'priority', 'display_name' => ['en' => 'Priority']]);
        $tag   = $this->call('post_api_tags', ['group_id' => $group['data']['id'], 'name' => 'Urgent']);

        $this->undo($tag['undo']);
        self::assertSame(0, $this->rows('tags'));
        $this->undo($group['undo']);
        self::assertSame(0, $this->rows('tag_groups'));
    }

    public function testRenamingATagIsUndoneByRestoringItsName(): void
    {
        $group = $this->call('post_api_tag_groups', ['key' => 'priority']);
        $tag   = $this->call('post_api_tags', ['group_id' => $group['data']['id'], 'name' => 'Urgent']);

        $renamed = $this->call('patch_api_tags_id', ['id' => $tag['data']['id'], 'name' => 'Critical']);
        self::assertSame('Critical', $renamed['data']['name']);

        $this->undo($renamed['undo']);
        self::assertSame('Urgent', $this->value('SELECT name FROM tags WHERE id = ?', $tag['data']['id']));
    }

    public function testChangingATagGroupIsUndoneByRestoringItsKeyAndName(): void
    {
        $group   = $this->call('post_api_tag_groups', ['key' => 'priority', 'display_name' => ['en' => 'Priority', 'ar' => 'الأولوية']]);
        $changed = $this->call('patch_api_tag_groups_id', ['id' => $group['data']['id'], 'key' => 'urgency', 'display_name' => ['en' => 'Urgency']]);

        $this->undo($changed['undo']);
        self::assertSame('priority', $this->value('SELECT group_key FROM tag_groups WHERE id = ?', $group['data']['id']));
        self::assertEquals(['en' => 'Priority', 'ar' => 'الأولوية'], json_decode((string) $this->value('SELECT display_name FROM tag_groups WHERE id = ?', $group['data']['id']), true));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{data: array<string, mixed>, undo: int}
     */
    private function call(string $tool, array $arguments): array
    {
        $result = ($this->toolsCall)(['name' => $tool, 'arguments' => $arguments], 'bearer');
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? false, (string) ($result['content'][0]['text'] ?? ''));
        $undo = $result['_meta'][ToolsCallHandler::META_UNDO]['actionId'] ?? null;
        self::assertIsInt($undo, "{$tool} offered no undo");
        return ['data' => json_decode($result['content'][0]['text'], true)['data'], 'undo' => $undo];
    }

    private function undo(int $actionId): void
    {
        $request       = new Request('POST', '/api/me/agent-actions', [], '');
        $request->user = (object) ['profile_id' => self::MANAGER, 'active_tenant_id' => self::TENANT];
        $response      = $this->undoApi->undo($request, ['id' => (string) $actionId]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
    }

    private function rows(string $table): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$table}");
        self::assertNotFalse($stmt);
        return (int) $stmt->fetchColumn();
    }

    private function value(string $sql, int $id): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetchColumn();
    }
}
