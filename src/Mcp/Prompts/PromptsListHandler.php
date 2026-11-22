<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\OAuth\OAuthTokenRefusedException;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Auth\McpScope;

/**
 * MCP prompts/list handler (WC-7755fc38).
 *
 * Returns the prompts the caller is allowed to see. Open prompts (no required
 * role/permission) are visible to everyone including unauthenticated callers.
 * Protected prompts are filtered out when the caller's token is absent or
 * invalid, or when RoleChecker denies the required grant.
 *
 * Filtering is soft-auth: a bad or missing token never throws — it simply
 * limits the visible set to open prompts. RBAC is still hard-enforced in
 * PromptsGetHandler when the caller tries to retrieve a protected prompt.
 */
final class PromptsListHandler implements MethodHandler
{
    public function __construct(
        private readonly PromptRegistry $registry,
        private readonly RoleChecker    $roleChecker,
        private readonly TokenValidator $tokenValidator,
        // SDK 1.80 (WHIT-993): whether a plugin is still granted agent context,
        // asked on every call so a revoke applies at once. Null: trust the registry.
        private readonly ?\Closure $pluginGranted = null,
    ) {}

    /** @param array<string, mixed>|null $params */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        $principal = $this->principalOf($bearerToken);
        $tenantId = TenantContext::getTenantId();

        $result = [];
        foreach ($this->registry->all() as $prompt) {
            if (!$this->callerCanSee($prompt, $principal, $tenantId)) {
                continue;
            }
            if ($prompt->plugin !== null && $this->pluginGranted !== null && !($this->pluginGranted)($prompt->plugin)) {
                continue;
            }
            $result[] = [
                'name'        => $prompt->name,
                'description' => $prompt->description,
                'arguments'   => array_map(
                    static fn (PromptArgument $a): array => [
                        'name'        => $a->name,
                        'description' => $a->description,
                        'required'    => $a->required,
                    ],
                    $prompt->arguments,
                ),
            ];
        }

        return ['prompts' => $result];
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

    private function callerCanSee(Prompt $prompt, ?McpPrincipal $principal, ?int $tenantId): bool
    {
        if ($prompt->isOpen()) {
            return true;
        }
        if ($principal === null || $tenantId === null) {
            return false;
        }
        // WHIT-695: narrowing covers protected prompts. Open ones are fixed
        // text and return no data, so they stay listed.
        if (!McpScope::of($principal)->allowsPermission($prompt->requiredPermission)) {
            return false;
        }
        // #2266: an agent's grant holds here as on tools/call.
        if (!AgentScopeGrant::permitsRead($principal, $prompt->requiredPermission)) {
            return false;
        }
        if ($prompt->requiredPermission !== null) {
            return $this->roleChecker->hasPermissionForProfile($principal->userId, $prompt->requiredPermission, $tenantId);
        }
        if ($prompt->requiredRole !== null) {
            return $this->roleChecker->hasRoleForProfile($principal->userId, $prompt->requiredRole, $tenantId);
        }
        return true;
    }
}
