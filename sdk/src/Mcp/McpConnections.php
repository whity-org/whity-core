<?php

declare(strict_types=1);

namespace Whity\Sdk\Mcp;

/**
 * The workspace's AI connections (SDK 1.79, WHIT-992): the apps and agents
 * people connected to Whity over MCP. Resolve it with
 * `\Whity\app(\Whity\Sdk\Mcp\McpConnections::class)` in a route handler or an
 * MCP tool handler.
 *
 * The host implements it; a plugin never does. It reads as the person whose
 * request it is, in their workspace, neither of which is an argument. A person
 * who manages AI connections (`mcp:tokens:manage`) sees every active connection
 * in the workspace; anybody else sees only their own. With no person, in a job
 * for example, it returns nothing.
 *
 * Never a secret: no token, no token id (jti) and no hash. A connection is named
 * by its row id, which can't be used to call as it. Read only: connecting,
 * changing a grant and disconnecting stay with people, on the host's screens.
 *
 * Events: {@see \Whity\Sdk\Hooks\Events::MCP_CONNECTION_CREATED},
 * `MCP_CONNECTION_GRANT_CHANGED` and `MCP_CONNECTION_REVOKED`.
 */
interface McpConnections
{
    /**
     * Active connections (not expired, not disconnected), newest first. `kind` is
     * how it signs in: `user` (an app a person connected) or `ai-agent` (a
     * workspace agent). `scope` is what it was granted, as permission and grant
     * entries. Times are UTC, ISO 8601.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     kind: string,
     *     personId: int,
     *     agentAssignmentId: int|null,
     *     agentId: int|null,
     *     scope: list<string>,
     *     createdAt: string,
     *     expiresAt: string,
     *     lastUsedAt: string|null
     * }>
     */
    public function list(): array;
}
