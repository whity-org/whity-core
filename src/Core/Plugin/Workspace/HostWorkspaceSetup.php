<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Workspace;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Setup\WorkspaceSetup;

/**
 * The host's {@see WorkspaceSetup} (SDK 1.108, WHIT-1129): a pack sets up a
 * workspace by calling core's own routes as the person acting there.
 *
 * Three gates, each refusing on its own:
 * 1. a workspace (never the system one) and a person must be in scope;
 * 2. the route must be in {@see WorkspaceSetup::ROUTES};
 * 3. that person must hold the route's permission, checked by the same
 *    RoleChecker the HTTP middleware uses.
 *
 * The request is built as the HTTP middleware would build it for that person,
 * so the handler validates and audits exactly as for a click in the app.
 */
final class HostWorkspaceSetup implements WorkspaceSetup
{
    private const SYSTEM_TENANT_ID = 0;

    /**
     * Core's own workspace templates (WHIT-1194) also make roles and documents
     * and remove their examples. Those routes are reachable only through
     * {@see self::forTemplates()}, never by a plugin's WorkspaceSetup.
     */
    public const TEMPLATE_ROUTES = [
        'GET /api/roles',
        'POST /api/roles',
        'POST /api/roles/{id}/permissions',
        'GET /api/document-sources',
        'POST /api/document-sources',
        'POST /api/contacts/{id}/archive',
        'POST /api/calendar/bookings/{id}/cancel',
        // SDK 1.170 (WHIT-1413): "Use the template's version" of a form adds
        // the template's missing questions and updates its labels; nothing
        // is deleted.
        'GET /api/forms/{id}/fields',
        'POST /api/forms/{id}/fields',
        'PATCH /api/forms/{id}/fields/{fieldId}',
    ];

    /** @param list<string> $extraRoutes */
    public function __construct(
        private readonly Router $router,
        private readonly RoleChecker $roles,
        private readonly array $extraRoutes = [],
    ) {
    }

    /** The same gates, plus {@see self::TEMPLATE_ROUTES}: for core's template applier only. */
    public function forTemplates(): self
    {
        return new self($this->router, $this->roles, self::TEMPLATE_ROUTES);
    }

    public function call(string $method, string $path, array $body = []): array
    {
        $method = strtoupper($method);
        $tenantId = TenantContext::getTenantId();
        $profileId = AuditContext::getActorUserId();
        if ($tenantId === null || $tenantId === self::SYSTEM_TENANT_ID || $profileId === null) {
            return self::refused(403, 'A workspace and a person are needed to set it up.');
        }

        $clean = '/' . ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        if (!self::allowed($method, $clean, $this->extraRoutes)) {
            return self::refused(403, 'That route is not one a setup may call.');
        }

        $versioned = $this->router->versionedPath($clean);
        $query = $method === 'GET' && $body !== [] ? '?' . http_build_query($body) : '';
        $matched = $this->router->match(new Request($method, $versioned));
        if ($matched === null) {
            return self::refused(404, 'Not found');
        }

        $permission = $matched['requiredPermission'] ?? null;
        $role = $matched['requiredRole'] ?? null;
        if (is_string($permission) && $permission !== '') {
            if (!$this->roles->hasPermissionForProfile($profileId, $permission, $tenantId)) {
                return self::refused(403, 'Forbidden');
            }
        } elseif (is_string($role) && $role !== '') {
            if (!$this->roles->hasRoleForProfile($profileId, $role, $tenantId)) {
                return self::refused(403, 'Forbidden');
            }
        }

        $request = new Request(
            $method,
            $versioned . $query,
            ['content-type' => 'application/json'],
            $method === 'GET' || $body === [] ? '' : (string) json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        $claims = [
            'profile_id' => $profileId,
            'active_tenant_id' => $tenantId,
            'user_id' => $profileId,
            'tenant_id' => $tenantId,
            'type' => 'setup',
        ];
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, $claims);
        $request->user = (object) $claims;

        $response = ($matched['handler'])($request, $matched['params']);
        $decoded = json_decode($response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }

    /**
     * Would {@see self::call()} let the person in scope through to this route,
     * without calling it: the same three gates. The preview of a template
     * (WHIT-1409) asks this for each write it only records.
     */
    public function permits(string $method, string $path): bool
    {
        $method = strtoupper($method);
        $tenantId = TenantContext::getTenantId();
        $profileId = AuditContext::getActorUserId();
        if ($tenantId === null || $tenantId === self::SYSTEM_TENANT_ID || $profileId === null) {
            return false;
        }
        $clean = '/' . ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        if (!self::allowed($method, $clean, $this->extraRoutes)) {
            return false;
        }
        $matched = $this->router->match(new Request($method, $this->router->versionedPath($clean)));
        if ($matched === null) {
            return false;
        }
        $permission = $matched['requiredPermission'] ?? null;
        $role = $matched['requiredRole'] ?? null;
        if (is_string($permission) && $permission !== '') {
            return $this->roles->hasPermissionForProfile($profileId, $permission, $tenantId);
        }
        if (is_string($role) && $role !== '') {
            return $this->roles->hasRoleForProfile($profileId, $role, $tenantId);
        }

        return true;
    }

    /**
     * Is METHOD + path one of {@see WorkspaceSetup::ROUTES} (or the extra
     * routes given), with `{name}` matching one id?
     *
     * @param list<string> $extraRoutes
     */
    public static function allowed(string $method, string $path, array $extraRoutes = []): bool
    {
        foreach ([...WorkspaceSetup::ROUTES, ...$extraRoutes] as $route) {
            [$routeMethod, $routePath] = explode(' ', $route, 2);
            if ($routeMethod !== $method) {
                continue;
            }
            $pattern = '#^' . preg_replace('#\\\\\{[a-zA-Z]+\\\\\}#', '[0-9]+', preg_quote($routePath, '#')) . '$#';
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array{status: int, body: mixed} */
    private static function refused(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $message]];
    }
}
