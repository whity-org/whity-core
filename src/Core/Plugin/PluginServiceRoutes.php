<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Auth\Service\ServiceTokenVerifier;
use Whity\Core\Router;
use Whity\Sdk\Http\ServiceCall;

/**
 * Plugin routes called server to server with a trusted issuer's token
 * (SDK 1.84, WHIT-1011).
 *
 * A plugin declares `'service' => '<scope>'` on a route. The loader records
 * the served route here with its scope and owner, and
 * {@see \Whity\Http\Middleware\EnforceTenantIsolation} lets such a request
 * through without a session ONLY when {@see self::admit()} verifies its
 * token for that scope. Then the request carries a {@see ServiceCall} and no
 * tenant.
 *
 * The same shape as {@see PluginPublicRoutes}: keyed by method and the
 * router's own path pattern, and admitted only when the router resolves the
 * request to that plugin's route, so a declaration cannot open another
 * plugin's or core's path.
 */
final class PluginServiceRoutes
{
    /** A scope is `area:verb`, the same shape as a permission. */
    public const SCOPE_PATTERN = '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/';

    /** @var array<string, array{owner: string, scope: string}> "METHOD pattern" => owner and scope */
    private static array $routes = [];

    private static ?ServiceTokenVerifier $verifier = null;

    public static function useVerifier(?ServiceTokenVerifier $verifier): void
    {
        self::$verifier = $verifier;
    }

    /** Why a declaration is refused, or null when it is acceptable. */
    public static function refusal(mixed $scope, mixed $requiredPermission, mixed $requiredRole, mixed $public): ?string
    {
        if (!is_string($scope) || preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
            return "a service route's scope must look like area:verb";
        }
        if ($requiredPermission !== null || $requiredRole !== null) {
            return 'a service route has no signed-in person, so it cannot also require a permission or a role';
        }
        if ($public !== null && $public !== false) {
            return 'a route is either public or a service route, not both';
        }

        return null;
    }

    public static function add(string $owner, string $method, string $pattern, string $scope): void
    {
        self::$routes[strtoupper($method) . ' ' . $pattern] = ['owner' => $owner, 'scope' => $scope];
    }

    public static function removeOwner(string $owner): void
    {
        self::$routes = array_filter(self::$routes, static fn (array $r): bool => $r['owner'] !== $owner);
    }

    /** Whether this method and pattern is a service route; for keeping it off MCP. */
    public static function isService(string $method, string $pattern): bool
    {
        return isset(self::$routes[strtoupper($method) . ' ' . $pattern]);
    }

    /**
     * The route's scope when this request is for a plugin service route, or
     * null when it is not one.
     */
    public static function scopeFor(\Whity\Sdk\Http\Request $request, ?Router $router): ?string
    {
        if (!$request instanceof \Whity\Core\Request || $router === null || self::$routes === []) {
            return null;
        }
        $route = $router->match($request);
        if ($route === null) {
            return null;
        }
        $entry = self::$routes[strtoupper($request->getMethod()) . ' ' . $route['path']] ?? null;
        if ($entry === null || ($route['namespacePrefix'] ?? null) !== $entry['owner']) {
            return null;
        }

        return $entry['scope'];
    }

    /**
     * Verify the token for the route's scope and attach the call. False when
     * the token does not grant it, or when service calls are not configured.
     */
    public static function admit(\Whity\Sdk\Http\Request $request, string $scope): bool
    {
        $call = self::$verifier?->verify((string) ($request->getHeader('Authorization') ?? ''), $scope);
        if ($call === null) {
            return false;
        }
        $request->setAttribute(ServiceCall::ATTRIBUTE, $call);

        return true;
    }

    public static function reset(): void
    {
        self::$routes = [];
        self::$verifier = null;
    }
}
