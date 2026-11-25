<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\CustomerCall;

/**
 * Plugin routes that serve a workspace's customers (SDK 1.173, WHIT-1424;
 * design section 41 §9, CA.18): the ones declared `'customer' => true`.
 *
 * DENY BY DEFAULT. The customer area calls plugins only with a customer frame
 * token ({@see PluginFrameTokens::issueForCustomer()}), and that token is
 * admitted only on a route listed here, of the plugin it names. A plugin
 * route not declared for customers answers such a token 404 even if the
 * middleware let it through.
 *
 * WHO IS CALLING. On a customer route, {@see self::guard()} attaches a
 * {@see CustomerCall} for a customer caller: the area's token, or a session
 * whose only memberships in the workspace are customer ones. The contact ids
 * come from the host's resolver, never the request.
 *
 * Ordinary sessions keep today's rules on every other route (SDK 1.104
 * plugins that serve customers through their sessions are unchanged).
 */
final class PluginCustomerRoutes
{
    /** @var array<string, array{owner: string, permission: string}> "METHOD pattern" => route */
    private static array $routes = [];

    /** @var array<string, array<string, true>> owner => declared paths (as the plugin wrote them, unversioned) */
    private static array $paths = [];

    /** @var (\Closure(int, int): bool)|null whether a profile is a customer only in a workspace */
    private static ?\Closure $customerOnly = null;

    /** @var (\Closure(int, int): list<int>)|null the profile's contact ids in a workspace */
    private static ?\Closure $contacts = null;

    /**
     * @param \Closure(int, int): bool $customerOnly (profileId, workspaceId)
     * @param \Closure(int, int): list<int> $contacts (workspaceId, profileId)
     */
    public static function useResolver(\Closure $customerOnly, \Closure $contacts): void
    {
        self::$customerOnly = $customerOnly;
        self::$contacts = $contacts;
    }

    /** Why a `customer` declaration is refused, or null when it is fine. */
    public static function refusal(mixed $customer, mixed $requiredPermission, mixed $public, bool $service): ?string
    {
        if ($customer !== true) {
            return "'customer' must be the boolean true";
        }
        if (!is_string($requiredPermission) || $requiredPermission === '') {
            return 'a customer route needs a requiredPermission (one the plugin lists as customer-grade)';
        }
        if ($public !== null && $public !== false) {
            return 'a route is either public or a customer route, not both';
        }
        if ($service) {
            return 'a service route has no person, so it cannot be a customer route';
        }

        return null;
    }

    public static function add(string $owner, string $method, string $pattern, string $declaredPath, string $permission): void
    {
        self::$routes[strtoupper($method) . ' ' . $pattern] = ['owner' => $owner, 'permission' => $permission];
        self::$paths[$owner][self::unversioned($declaredPath)] = true;
    }

    public static function removeOwner(string $owner): void
    {
        self::$routes = array_filter(self::$routes, static fn (array $r): bool => $r['owner'] !== $owner);
        unset(self::$paths[$owner]);
    }

    /** Whether the route a request matches is a customer route of the plugin `$owner`. */
    public static function admits(Request $request, ?Router $router, string $owner): bool
    {
        if ($router === null) {
            return false;
        }
        $route = $router->match($request);
        if ($route === null || ($route['namespacePrefix'] ?? null) !== $owner) {
            return false;
        }
        $entry = self::$routes[strtoupper($request->getMethod()) . ' ' . $route['path']] ?? null;

        return $entry !== null && $entry['owner'] === $owner;
    }

    /** Whether `$path` (any version prefix) is one of `$owner`'s customer routes. */
    public static function ownsPath(string $owner, string $path): bool
    {
        return isset(self::$paths[$owner][self::unversioned($path)]);
    }

    /**
     * The gate every plugin route runs through (from the loader's wrapper).
     * Returns a refusal, or null to go on; attaches the CustomerCall.
     */
    public static function guard(Request $request, bool $isCustomerRoute): ?Response
    {
        $claims = $request->getAttribute(Request::ATTR_JWT_CLAIMS);
        $claims = is_array($claims) ? $claims : [];
        $customerToken = ($claims['type'] ?? null) === PluginFrameTokens::TYPE
            && ($claims[PluginFrameTokens::AUDIENCE_CLAIM] ?? null) === PluginFrameTokens::AUDIENCE_CUSTOMER;

        if ($customerToken && !$isCustomerRoute) {
            return Response::error('Not Found', 404);
        }
        if (!$isCustomerRoute) {
            return null;
        }

        $profileId = $claims['profile_id'] ?? null;
        $workspaceId = TenantContext::getTenantId();
        if (!is_int($profileId) || $profileId <= 0 || $workspaceId === null || $workspaceId <= 0) {
            return null;
        }
        $asCustomer = $customerToken
            || (self::$customerOnly !== null && (self::$customerOnly)($profileId, $workspaceId));
        if ($asCustomer) {
            $contacts = self::$contacts !== null ? (self::$contacts)($workspaceId, $profileId) : [];
            $request->setAttribute(CustomerCall::ATTRIBUTE, new CustomerCall($profileId, $workspaceId, $contacts));
        }

        return null;
    }

    /**
     * The plugin's customer route paths, versioned as clients call them
     * (`/api/v1/courses/mine/{id}`), for the area to send its token to.
     *
     * @return list<string>
     */
    public static function pathsOf(string $owner): array
    {
        return array_map(
            static fn (string $p): string => (string) preg_replace('#^/api/#', '/api/v1/', $p),
            array_keys(self::$paths[$owner] ?? [])
        );
    }

    public static function reset(): void
    {
        self::$routes = [];
        self::$paths = [];
        self::$customerOnly = null;
        self::$contacts = null;
    }

    private static function unversioned(string $path): string
    {
        $path = (string) preg_replace('#^/api/v[0-9]+/#', '/api/', $path);

        return rtrim($path, '/');
    }
}
