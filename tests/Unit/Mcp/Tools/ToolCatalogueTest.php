<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Auth\AgentGrantView;
use Whity\Mcp\Tools\ToolCatalogue;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolSurface;
use Whity\OpenAPI\CoreApiSchemas;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every action AI can take, in plain words (WHIT-867). The page must never say an
 * agent can do what the server would stop it doing.
 */
final class ToolCatalogueTest extends TestCase
{
    /**
     * How many of core's tools may still lack an Arabic name. Tools other pull
     * requests add fall back to English until someone names them, so a new route
     * doesn't fail an unrelated build, but this keeps the list from drifting.
     * Add yours to database/mcp-tool-names.json and lower this.
     */
    private const MAX_WITHOUT_ARABIC = 25;

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    /** @return iterable<string, array{bool, string, bool, string}> */
    public static function accessCases(): iterable
    {
        yield 'a read runs'                         => [true, ToolSurface::AGENT_ALLOW, false, ToolCatalogue::CAN];
        yield 'a write with no undo proposes'       => [false, ToolSurface::AGENT_ALLOW, false, ToolCatalogue::PROPOSES];
        yield 'a write with an undo can run'        => [false, ToolSurface::AGENT_ALLOW, true, ToolCatalogue::CAN];
        yield 'a propose ceiling beats an undo'     => [false, ToolSurface::AGENT_PROPOSE, true, ToolCatalogue::PROPOSES];
        yield 'a suggest-only write suggests'       => [false, ToolSurface::AGENT_SUGGEST_ONLY, false, ToolCatalogue::SUGGESTS];
        yield 'suggest-only beats an undo'          => [false, ToolSurface::AGENT_SUGGEST_ONLY, true, ToolCatalogue::SUGGESTS];
        yield 'a refused write is never'            => [false, ToolSurface::AGENT_REFUSE, true, ToolCatalogue::NEVER];
        yield 'a refused read is never'             => [true, ToolSurface::AGENT_REFUSE, false, ToolCatalogue::NEVER];
    }

    #[DataProvider('accessCases')]
    public function testAccessFollowsTheCeilings(bool $readOnly, string $policy, bool $undoable, string $expected): void
    {
        self::assertSame($expected, ToolCatalogue::agentAccess($readOnly, $policy, $undoable, 'documents:write'));
    }

    public function testDeletingAUserIsAProposalNotSomethingAnAgentDoes(): void
    {
        $tools = $this->tools();

        self::assertArrayHasKey('delete_api_users_id', $tools);
        $tool = ToolCatalogue::describe($tools['delete_api_users_id'], 'users:delete');

        self::assertSame(ToolCatalogue::PROPOSES, $tool['agentAccess']);
        self::assertSame('Delete a user', $tool['plainName']);
        self::assertSame('People and roles', $tool['area']['title']);
        self::assertNotNull($tool['area']['titleAr']);
        self::assertNull($tool['undoWindowSeconds']);
    }

    public function testEveryWriteThatCanRunDeclaresAnUndo(): void
    {
        foreach ($this->tools() as $name => $tool) {
            $described = ToolCatalogue::describe($tool);
            if ($described['agentAccess'] === ToolCatalogue::CAN && !$described['readOnly']) {
                self::assertNotNull($described['undoWindowSeconds'], "{$name} reads as Can do it with nothing to undo it");
            }
        }
    }

    public function testToolsAgentsMayNotUseSayNever(): void
    {
        $tools = $this->tools();

        foreach (['post_api_agents', 'post_api_agents_id_pause', 'post_api_agents_id_resume'] as $name) {
            self::assertArrayHasKey($name, $tools);
            self::assertSame(ToolCatalogue::NEVER, ToolCatalogue::describe($tools[$name])['agentAccess'], $name);
        }
    }

    public function testAnAgentCannotReadWhatOtherAgentsDid(): void
    {
        self::assertArrayNotHasKey('get_api_agent_activity', $this->tools());
    }

    public function testThePagesRoutesAreGatedOnManagingAgents(): void
    {
        $found = [];
        foreach (CoreApiSchemas::routes() as $route) {
            if (in_array($route['path'], ['/api/agents/{id:\d+}/pause', '/api/agents/{id:\d+}/resume', '/api/agent-activity'], true)) {
                $found[$route['path']] = $route['requiredPermission'];
            }
        }

        self::assertSame([
            '/api/agents/{id:\d+}/pause'  => 'agents:manage',
            '/api/agents/{id:\d+}/resume' => 'agents:manage',
            '/api/agent-activity'         => 'agents:manage',
        ], $found);
    }

    public function testCoresToolsAreNamedInArabic(): void
    {
        $missing = [];
        foreach ($this->tools() as $name => $tool) {
            if (ToolCatalogue::describe($tool)['plainNameAr'] === null) {
                $missing[] = $name;
            }
        }

        self::assertLessThanOrEqual(
            self::MAX_WITHOUT_ARABIC,
            count($missing),
            "These tools have no Arabic name in database/mcp-tool-names.json:\n" . implode("\n", $missing),
        );
    }

    public function testAPluginsAreaIsReadableWithoutInventingArabic(): void
    {
        self::assertSame(['id' => 'hello-world', 'title' => 'Hello world', 'titleAr' => null], ToolCatalogue::area('hello-world'));
        self::assertSame('Period close', ToolCatalogue::area('period-close.reports')['title']);
    }

    public function testAToolWithNoSummaryOfItsOwnStillHasAName(): void
    {
        $described = ToolCatalogue::describe(['name' => 'plugin_tool', 'description' => 'Close the month. It seals every open period.']);

        self::assertSame('Close the month', $described['plainName']);
        self::assertNull($described['plainNameAr']);
    }

    public function testOnItsOwnCountsActionsNotPermissions(): void
    {
        $view = new AgentGrantView([
            ['permission' => 'documents:read',  'readOnly' => true,  'agentPolicy' => 'allow', 'toolset' => 'core.documents'],
            ['permission' => 'documents:read',  'readOnly' => true,  'agentPolicy' => 'allow', 'toolset' => 'core.documents'],
            ['permission' => 'documents:write', 'readOnly' => false, 'agentPolicy' => 'allow', 'toolset' => 'core.documents', 'undoWindowSeconds' => 300],
            ['permission' => 'users:delete',    'readOnly' => false, 'agentPolicy' => 'allow', 'toolset' => 'core.people'],
        ]);

        self::assertSame(['allowed' => 2, 'askFirst' => 2, 'off' => 0], $view->onItsOwn([]), 'the defaults: reads run, writes ask');
        self::assertSame(
            ['allowed' => 3, 'askFirst' => 0, 'off' => 0],
            $view->onItsOwn(['toolset:core.documents', 'grant:documents:write=allow']),
            'toolsets narrow it, and a write with an undo may run',
        );
        self::assertSame(
            ['allowed' => 0, 'askFirst' => 1, 'off' => 0],
            $view->onItsOwn([], ['users:delete']),
            'never above what the person holds',
        );
        self::assertSame(
            ['allowed' => 0, 'askFirst' => 1, 'off' => 0],
            $view->onItsOwn(['grant:users:delete=allow'], ['users:delete']),
            'a grant cannot lift a write with no undo',
        );
    }

    /** @return array<string, array<string, mixed>> the tools core offers an MCP client, by name */
    private function tools(): array
    {
        $deriver = new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), null, static fn (string $m): null => null);
        $tools   = [];
        foreach ($deriver->deriveTools() as $tool) {
            if ($deriver->isExposed((string) $tool['name'])) {
                $tools[(string) $tool['name']] = $tool;
            }
        }

        return $tools;
    }
}
