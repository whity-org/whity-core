<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

/**
 * What an MCP credential lets a handler do with one permission, read off the
 * request's user (#2253).
 *
 * tools/call checks a route's own `requiredPermission` against the token's
 * scope and an agent's grant. Some MCP-callable routes have no route gate and
 * authorise per record type inside the handler instead (search, data types).
 * Those handlers check RBAC for the person, so they must ALSO ask this, or a
 * permission the person set to Off for their agent, or left out of a narrowed
 * token, is still reached through them.
 *
 * Only ever narrows: a request that did not come in over MCP is not affected,
 * and RBAC is still checked separately by the caller.
 */
final class McpCallerScope
{
    /**
     * @param mixed $user     `$request->user` (the claims tools/call stamps)
     * @param bool  $readOnly a read: an agent needs Allowed. A write: anything
     *                        but Off, because these routes are ungated writes,
     *                        which tools/call already holds for approval (Ask
     *                        first) before they reach the handler.
     */
    public static function permits(mixed $user, string $permission, bool $readOnly): bool
    {
        if (!is_object($user) || ($user->type ?? null) !== 'mcp') {
            return true;
        }
        $scope = is_array($user->scope ?? null) ? array_values(array_filter($user->scope, 'is_string')) : [];

        if (!(new McpScope($scope))->allowsPermission($permission)) {
            return false;
        }
        if (($user->principal_kind ?? null) !== AgentScopeGrant::AGENT_KIND) {
            return true;
        }

        $mode = AgentScopeGrant::fromScope($scope)->modeFor($permission, $readOnly);

        return $readOnly ? $mode === AgentScopeGrant::ALLOW : $mode !== AgentScopeGrant::OFF;
    }
}
