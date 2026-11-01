<?php

declare(strict_types=1);

namespace Whity\Mcp\Connect;

use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Tools\McpSplits;
use Whity\Mcp\Tools\ToolSurface;
use Whity\Mcp\Tools\ToolSurfaceRequest;

/**
 * GET /api/mcp/splits (WHIT-765, MCP-TOOL-SURFACE.md §5.12): the splits the
 * caller can reach, with their URLs. A split with nothing the caller may use
 * isn't listed, so this never names an area they have no access to.
 */
final class McpSplitsHandler
{
    /**
     * @param \Closure(): list<array{toolset: string, permission: ?string}> $operations
     *        the exposed operations, with their toolset and permission gate
     */
    public function __construct(
        private readonly McpSplits $splits,
        private readonly RoleChecker $roles,
        private readonly \Closure $operations,
        private readonly ?string $endpoint,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        $profile  = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($tenantId === null || $profile === null) {
            return Response::error('Unauthenticated', 401);
        }

        $allowed = [];
        foreach (($this->operations)() as $operation) {
            $permission = $operation['permission'];
            $allowed[$permission ?? ''] ??= $permission === null || $this->roles->hasPermissionForProfile($profile, $permission, $tenantId);
            if ($allowed[$permission ?? '']) {
                $reach[] = $operation['toolset'];
            }
        }
        $reach = array_values(array_unique($reach ?? []));

        $data = [];
        foreach ($this->splits->all() as $name => $toolsets) {
            $surface = new ToolSurfaceRequest(toolsets: $toolsets, split: $name);
            $count   = count(array_filter($reach, static fn (string $toolset): bool => $surface->selects($toolset)));
            if ($count > 0) {
                $data[] = [
                    'name'     => $name,
                    'url'      => $this->endpoint !== null ? $this->endpoint . '/' . $name : null,
                    'toolsets' => array_values(array_filter($reach, static fn (string $toolset): bool => $surface->selects($toolset) && ToolSurface::isToolsetId($toolset))),
                ];
            }
        }

        return Response::json(['data' => $data], 200);
    }
}
