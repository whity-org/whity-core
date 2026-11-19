<?php

declare(strict_types=1);

namespace Whity\Mcp\Resources;

use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\OAuth\OAuthTokenRefusedException;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Auth\McpScope;

/**
 * MCP resources/list handler (WC-30513809, filtered WC-e8c4d228).
 *
 * Returns only the resources and resourceTemplates the caller is permitted to
 * read. Open resources (no requiredRole / requiredPermission) are visible to
 * all callers including unauthenticated ones. Protected resources are hidden
 * when the bearer token is absent or invalid, or when RoleChecker denies the
 * required grant.
 *
 * Filtering is soft-auth: a bad or missing token never throws — it simply
 * limits the visible set to open resources. RBAC is still hard-enforced in
 * ResourcesReadHandler when the caller tries to read a protected resource.
 */
final class ResourcesListHandler implements MethodHandler
{
    public function __construct(
        private readonly ResourceDeriver $resourceDeriver,
        private readonly RoleChecker     $roleChecker,
        private readonly TokenValidator  $tokenValidator,
        // SDK 1.80 (WHIT-993): resources from plugins the operator granted agent context.
        private readonly ?PluginResourceRegistry $pluginResources = null,
        // Whether a plugin is still granted agent context, asked on every call so
        // a revoke applies at once. Null: trust the registry.
        private readonly ?\Closure $pluginGranted = null,
    ) {}

    /** @param array<string, mixed>|null $params */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        $principal = $this->principalOf($bearerToken);
        $tenantId = TenantContext::getTenantId();

        // Only the agent surface (ADR-0006 §4, WHIT-695): gated routes and
        // ungated ones that opted in.
        $accessMap = $this->resourceDeriver->buildAccessMap(exposedOnly: true);
        $all       = $this->resourceDeriver->deriveResources(exposedOnly: true);

        $resources = array_values(array_filter(
            $all['resources'],
            fn (array $r): bool => $this->callerCanUse(
                $accessMap[(string) ($r['uri'] ?? '')] ?? [],
                $principal,
                $tenantId,
            ),
        ));

        $resourceTemplates = array_values(array_filter(
            $all['resourceTemplates'],
            fn (array $r): bool => $this->callerCanUse(
                $accessMap[(string) ($r['uriTemplate'] ?? '')] ?? [],
                $principal,
                $tenantId,
            ),
        ));

        // Every plugin resource carries a permission, so none is listed to a
        // caller without a person behind it.
        foreach ($this->pluginResources?->all() ?? [] as $resource) {
            if ($this->pluginGranted !== null && !($this->pluginGranted)($resource['plugin'])) {
                continue;
            }
            if ($principal !== null && $this->callerCanUse(['requiredPermission' => $resource['requiredPermission']], $principal, $tenantId)) {
                $resources[] = [
                    'uri'         => $resource['uri'],
                    'name'        => $resource['name'],
                    'description' => $resource['description'],
                    'mimeType'    => $resource['mimeType'],
                ];
            }
        }

        return ['resources' => $resources, 'resourceTemplates' => $resourceTemplates];
    }

    /**
     * The caller, validated the way the Dispatcher validated it (an MCP token,
     * a session bearer or an OAuth access token, #2202), or null. Soft: a bad
     * token only narrows what is listed.
     */
    private function principalOf(?string $bearerToken): ?McpPrincipal
    {
        if ($bearerToken === null) {
            return null;
        }
        try {
            return $this->tokenValidator->validateBearerForMcp($bearerToken);
        } catch (OAuthTokenRefusedException) {
            return null;
        }
    }

    /** @param array<string, mixed> $access */
    private function callerCanUse(array $access, ?McpPrincipal $principal, ?int $tenantId): bool
    {
        $requiredPermission = is_string($access['requiredPermission'] ?? null) ? $access['requiredPermission'] : null;
        $requiredRole       = is_string($access['requiredRole'] ?? null) ? $access['requiredRole'] : null;

        // WHIT-695: a token that names permissions reaches only items gated by
        // one of them. Applied before the open-item shortcut, which is exactly
        // what narrowing removes.
        if ($principal !== null && !McpScope::of($principal)->allowsPermission($requiredPermission)) {
            return false;
        }
        // #2266: what resources/read would refuse is not listed either: a
        // token's toolset narrowing, and an agent's grant and agentPolicy.
        if ($principal !== null && array_key_exists('toolset', $access)
            && !McpScope::of($principal)->allowsToolset(is_string($access['toolset']) ? $access['toolset'] : null)) {
            return false;
        }
        if ($principal !== null && !AgentScopeGrant::permitsRead(
            $principal,
            $requiredPermission,
            is_string($access['agentPolicy'] ?? null) ? $access['agentPolicy'] : null,
        )) {
            return false;
        }
        if ($requiredPermission === null && $requiredRole === null) {
            return true;
        }
        if ($principal === null || $tenantId === null) {
            return false;
        }
        if ($requiredPermission !== null) {
            return $this->roleChecker->hasPermissionForProfile($principal->userId, $requiredPermission, $tenantId);
        }
        return $this->roleChecker->hasRoleForProfile($principal->userId, $requiredRole, $tenantId);
    }
}
