<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * An agent must never be able to grant itself, widen its own grant, mint its
 * own credentials or edit an agent (WHIT-816; owner direction: agents propose,
 * people approve). So no route that changes an assignment, a grant or an agent
 * definition is an MCP operation an agent can call, and the agent-definition
 * writes that are (for admins' own clients) refuse agents outright.
 */
final class MyAgentsRoutesStayOffMcpTest extends TestCase
{
    public function testNoAssignmentOrGrantRouteIsAnMcpOperation(): void
    {
        $deriver = new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), null, static fn (string $m): null => null);

        $seen = 0;
        foreach ($deriver->deriveTools() as $tool) {
            $name = (string) $tool['name'];
            if (str_contains($name, 'me_agents') || str_contains($name, 'mcp_tokens')) {
                self::assertFalse($deriver->isExposed($name), "{$name} must not be callable by an agent");
            }
            if (str_contains($name, 'me_agents')) {
                $seen++;
            }
        }
        self::assertSame(6, $seen, 'every /api/me/agents route was derived and checked');
    }

    public function testEveryAgentDefinitionWriteRefusesAgents(): void
    {
        foreach (CoreApiSchemas::routes() as $route) {
            if (str_starts_with($route['path'], '/api/me/agents')) {
                self::assertNull($route['requiredPermission'], 'session-only: no gate that would expose it');
                self::assertArrayNotHasKey('mcp', $route['schema']);
            }
            if (str_starts_with($route['path'], '/api/agents') && $route['method'] !== 'GET') {
                self::assertSame('refuse', $route['schema']['mcp']['agentPolicy'] ?? null, "{$route['method']} {$route['path']}");
            }
        }
    }
}
