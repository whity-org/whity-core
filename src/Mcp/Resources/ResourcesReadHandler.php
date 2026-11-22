<?php

declare(strict_types=1);

namespace Whity\Mcp\Resources;

use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\i18n\RequestLanguageResolver;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\RequestContext;
use Whity\Mcp\Auth\OAuth\OAuthTokenRefusedException;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolSurface;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Sdk\Http\Request;
use Whity\Mcp\Auth\McpScope;
use Whity\Mcp\McpExposure;
use Whity\Sdk\Mcp\McpToolContext;

/**
 * MCP resources/read handler (WC-30513809).
 *
 * Accepts a whity-api:// URI, resolves it to a live GET route via Router::match(),
 * enforces RBAC through the same RoleChecker used by HTTP, and returns the
 * handler response body wrapped in the MCP contents format.
 *
 * Protocol errors (invalid URI, resource not found, forbidden) are thrown as
 * McpException so the Dispatcher returns the correct JSON-RPC error codes.
 * Application errors (4xx/5xx from the handler) are forwarded in contents.text
 * so the AI client can inspect the body, matching the MCP spec's intent of
 * always returning 200 JSON-RPC responses even for downstream failures.
 */
final class ResourcesReadHandler implements MethodHandler
{
    public function __construct(
        private readonly Router                $router,
        private readonly RoleChecker           $roleChecker,
        private readonly TokenValidator        $tokenValidator,
        private readonly ?AuditLoggerInterface $auditLogger = null,
        // Knows which UNGATED routes opted onto the resource surface (their
        // opt-in lives in the static schemas, not on the Router). Without it
        // only an opt-in carried on the matched route itself counts.
        private readonly ?ResourceDeriver      $resourceDeriver = null,
        // SDK 1.80 (WHIT-993): resources from plugins the operator granted agent context.
        private readonly ?PluginResourceRegistry $pluginResources = null,
        // Whether a plugin is still granted agent context, asked on every call so
        // a revoke applies at once. Null: trust the registry.
        private readonly ?\Closure $pluginGranted = null,
        // WHIT-1015: the language a plugin's route handler reads on its Request.
        private readonly ?RequestLanguageResolver $languages = null,
    ) {}

    /**
     * @param array<string, mixed>|null $params
     * @throws McpException On protocol-level failures (invalid URI, not found, forbidden).
     */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        // 1. Validate the uri parameter.
        $uri = $params['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            throw new McpException(ErrorCode::INVALID_PARAMS, 'Missing required parameter: uri');
        }
        if (str_starts_with($uri, PluginResourceRegistry::URI_SCHEME)) {
            return $this->readPluginResource($uri, $bearerToken);
        }
        if (!str_starts_with($uri, ResourceDeriver::URI_SCHEME)) {
            throw new McpException(ErrorCode::INVALID_PARAMS, 'Invalid resource URI scheme');
        }

        // 2. Re-validate bearer token to obtain the principal.
        $principal = $this->principalOf($bearerToken);

        // Route the AI principal into AuditContext so hook-fired audit entries
        // (e.g. a read that triggers a hook) also capture the MCP actor.
        AuditContext::setActor($principal->actor());

        // 3. Extract path (and query string) from URI by stripping the scheme.
        //    whity-api:///api/v1/things/42  →  /api/v1/things/42
        $fullPath = substr($uri, strlen(ResourceDeriver::URI_SCHEME));

        // Separate the path from the query string for Router::match().
        $questionMark = strpos($fullPath, '?');
        $pathForMatch = $questionMark !== false ? substr($fullPath, 0, $questionMark) : $fullPath;

        // 4. Resolve the route. Resources are always GET.
        $matched = $this->router->match(new Request('GET', $pathForMatch));
        if ($matched === null) {
            throw new McpException(ErrorCode::RESOURCE_NOT_FOUND, "Resource not found: {$uri}");
        }

        // From here the resource is known — audit the read regardless of outcome.
        try {
            return $this->executeResolved($uri, $fullPath, $matched, $principal);
        } finally {
            $this->auditLogger?->record('mcp.resources.read', [
                'tenant_id'    => $principal->tenantId,
                'actor_user_id' => $principal->userId,
                'target_type'  => 'resource',
                'metadata'     => ['uri' => $uri],
            ]);
        }
    }

    /**
     * Execute a resolved resource read (steps 5–8). Separated so the audit
     * finally block in {@see self::__invoke()} wraps the entire execution.
     *
     * @param array<string, mixed> $matched
     * @throws McpException On RBAC failure or missing tenant context.
     * @return array<string, mixed>
     */
    private function executeResolved(
        string $uri,
        string $fullPath,
        array $matched,
        McpPrincipal $principal,
    ): array {
        // 5. Enforce RBAC — same RoleChecker as the HTTP path.
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'No tenant context');
        }

        $requiredPermission = $matched['requiredPermission'] ?? null;
        $requiredRole       = $matched['requiredRole'] ?? null;

        // ADR-0006 §4 (WHIT-695): a route is readable only when it is on the
        // resource surface. Without this, any GET route the Router knows —
        // /me/emails, /health — answered here, listed or not. The deriver's
        // answer wins, so a gated route that opts out (`'mcp' => ['expose' =>
        // false]`, declared in the static schemas) is not readable (#2202).
        $access = $this->surfaceAccess($matched);
        if ($access === null) {
            throw new McpException(ErrorCode::RESOURCE_NOT_FOUND, "Resource not found: {$uri}");
        }

        // WHIT-695: the token's permission narrowing, on top of RBAC below.
        $gate = is_string($requiredPermission) && $requiredPermission !== '' ? $requiredPermission : null;
        if (!McpScope::of($principal)->allowsPermission($gate)) {
            throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
        }
        // #2266: a token narrowed to toolsets reaches only resources in them,
        // as with tools/call; and an agent reads only what its grant and the
        // route's agentPolicy leave Allowed. There is no approval here, so Ask
        // first is refused as well as Off.
        if (!McpScope::of($principal)->allowsToolset($access['toolset'])) {
            throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
        }
        if (!AgentScopeGrant::permitsRead($principal, $gate, $access['agentPolicy'])) {
            throw new McpException(ErrorCode::FORBIDDEN, 'This agent is not granted this operation.');
        }

        if (is_string($requiredPermission) && $requiredPermission !== '') {
            if (!$this->roleChecker->hasPermissionForProfile($principal->userId, $requiredPermission, $tenantId)) {
                throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
            }
        } elseif (is_string($requiredRole) && $requiredRole !== '') {
            if (!$this->roleChecker->hasRoleForProfile($principal->userId, $requiredRole, $tenantId)) {
                throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
            }
        }

        // 6. Synthesize the HTTP Request. The full path (including any query
        //    string) is forwarded so query filters reach the handler intact.
        $httpRequest = $this->buildRequest($fullPath, $principal);

        // 7. Invoke the route handler.
        try {
            $response = ($matched['handler'])($httpRequest, $matched['params']);
        } catch (\Throwable) {
            return [
                'contents' => [[
                    'uri'      => $uri,
                    'mimeType' => 'application/json',
                    'text'     => 'Internal error',
                ]],
            ];
        }

        // 8. Wrap in MCP contents format.
        return [
            'contents' => [[
                'uri'      => $uri,
                'mimeType' => 'application/json',
                'text'     => $response->getBody(),
            ]],
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * The caller, validated the way the Dispatcher validated it: an MCP token,
     * a session bearer or an OAuth access token (#2202).
     *
     * @throws McpException
     */
    private function principalOf(?string $bearerToken): McpPrincipal
    {
        try {
            $principal = $bearerToken !== null ? $this->tokenValidator->validateBearerForMcp($bearerToken) : null;
        } catch (OAuthTokenRefusedException) {
            $principal = null;
        }
        if ($principal === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'Unauthenticated');
        }
        return $principal;
    }

    /**
     * The route's place on the resource surface, or null when it is not on it.
     * With a deriver, its answer is the only one: it reads the opt-outs and
     * opt-ins declared in the static schemas, which the Router's route does not
     * carry. Without one, only what the matched route itself declares counts.
     *
     * @param array<string, mixed> $matched
     * @return array{agentPolicy: string, toolset: string}|null
     */
    private function surfaceAccess(array $matched): ?array
    {
        $path = $matched['path'] ?? null;
        if ($this->resourceDeriver !== null) {
            $access = is_string($path) ? $this->resourceDeriver->accessForRoute($path) : null;
            return $access === null ? null : ['agentPolicy' => $access['agentPolicy'], 'toolset' => $access['toolset']];
        }
        if (!McpExposure::exposes($matched)) {
            return null;
        }
        $schema = is_array($matched['schema'] ?? null) ? $matched['schema'] : [];
        return [
            'agentPolicy' => ToolSurface::routeOptions($schema)['agentPolicy'] ?? ToolSurface::AGENT_ALLOW,
            'toolset'     => ToolDeriver::toolsetForRoute($matched),
        ];
    }

    private function buildRequest(string $path, McpPrincipal $principal): Request
    {
        // The same claims tools/call stamps (#2202): handlers read the caller
        // off the canonical {profile_id, active_tenant_id}; the legacy aliases
        // stay for any consumer still on them.
        $claims = [
            'profile_id'         => $principal->userId,
            'active_tenant_id'   => $principal->tenantId,
            'user_id'            => $principal->userId,
            'tenant_id'          => $principal->tenantId,
            'type'               => 'mcp',
            'principal_kind'     => $principal->principalKind,
            'scope'              => $principal->scope,
            'agent_principal_id' => $principal->isAgent() ? $principal->agentPrincipalId() : null,
        ];

        $request = new Request('GET', $path, ['content-type' => 'application/json'], '');
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, $claims);
        $request->user = (object) $claims;
        RequestContext::stamp(
            $request,
            $principal->tenantId > 0 ? $principal->tenantId : null,
            RequestContext::languageOf($this->languages, $principal->userId),
            $principal->actor()->kind,
        );

        return $request;
    }

    /**
     * A plugin's resource (SDK 1.80, WHIT-993): only from a plugin the operator
     * granted agent context (the registry holds nothing else), only to a caller
     * holding its permission in this workspace and whose token allows it, and
     * read with the caller's workspace and identity.
     *
     * @return array<string, mixed>
     */
    private function readPluginResource(string $uri, ?string $bearerToken): array
    {
        $resource = $this->pluginResources?->find($uri);
        if ($resource === null || ($this->pluginGranted !== null && !($this->pluginGranted)($resource['plugin']))) {
            throw new McpException(ErrorCode::RESOURCE_NOT_FOUND, "Resource not found: {$uri}");
        }
        $principal = $this->principalOf($bearerToken);
        AuditContext::setActor($principal->actor());
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'No tenant context');
        }
        if (!McpScope::of($principal)->allowsPermission($resource['requiredPermission'])
            || !$this->roleChecker->hasPermissionForProfile($principal->userId, $resource['requiredPermission'], $tenantId)) {
            throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
        }
        // #2266: an agent's grant for the resource's permission holds here too.
        if (!AgentScopeGrant::permitsRead($principal, $resource['requiredPermission'])) {
            throw new McpException(ErrorCode::FORBIDDEN, 'This agent is not granted this operation.');
        }

        $context = new McpToolContext(
            $tenantId,
            $principal->profileId,
            $principal->isAgent() ? McpToolContext::ACTOR_AGENT : McpToolContext::ACTOR_PERSON,
            $principal->principalKind,
            $principal->isAgent() ? $principal->agentPrincipalId() : null,
            $principal->isAgent() ? $principal->agentName : null,
            $principal->subject,
        );
        try {
            $text = ($resource['read'])($context);
            if (!is_string($text)) {
                throw new \UnexpectedValueException('read() must return a string');
            }
            if (strlen($text) > PluginResourceRegistry::MAX_BYTES) {
                throw new \LengthException('read() returned more than ' . PluginResourceRegistry::MAX_BYTES . ' bytes');
            }
        } catch (\Throwable $e) {
            error_log("[mcp] resource {$uri} from plugin '{$resource['plugin']}' failed: " . $e->getMessage());
            throw new McpException(ErrorCode::INTERNAL_ERROR, "Resource '{$uri}' failed");
        } finally {
            $this->auditLogger?->record('mcp.resources.read', [
                'tenant_id'     => $principal->tenantId,
                'actor_user_id' => $principal->userId,
                'target_type'   => 'resource',
                'metadata'      => ['uri' => $uri, 'plugin' => $resource['plugin']],
            ]);
        }

        return ['contents' => [['uri' => $uri, 'mimeType' => $resource['mimeType'], 'text' => $text]]];
    }
}
