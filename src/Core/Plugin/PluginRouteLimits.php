<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Audit\AuditContext;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\Router;
use Whity\Core\RateLimit\ClientIp;
use Whity\Sdk\Http\Request;

/**
 * Rate limits a plugin declares on its own routes (SDK 1.152, #1686):
 * `'rateLimit' => 30` on a route is 30 calls a minute per person, or per address
 * when nobody is signed in.
 *
 * The loader records each accepted route's limit under the route's served method
 * and path pattern; {@see self::rule()} is a dimension of the post-auth rate
 * limiter that applies it to the route the router resolves for the request. A
 * limit belongs to the plugin's own handler: a core route on the same path, or a
 * route registered by someone else, never picks it up.
 *
 * PER PROCESS, like the other route registries; removed with the plugin's routes.
 */
final class PluginRouteLimits
{
    public const MIN = 1;
    public const MAX = 10000;
    public const WINDOW = 60;

    /** @var array<string, array{owner: string, limit: int}> "METHOD /served/{pattern}" => limit */
    private static array $limits = [];

    private function __construct()
    {
    }

    /** Why a declared value is refused, or null when it is a usable limit. */
    public static function refusal(mixed $value): ?string
    {
        if (!is_int($value)) {
            return 'rateLimit must be a whole number of calls a minute';
        }
        if ($value < self::MIN || $value > self::MAX) {
            return 'rateLimit must be from ' . self::MIN . ' to ' . self::MAX;
        }

        return null;
    }

    public static function add(string $owner, string $method, string $servedPath, int $limit): void
    {
        self::$limits[strtoupper($method) . ' ' . $servedPath] = ['owner' => $owner, 'limit' => $limit];
    }

    /** The limit on the route that answers this request, when its plugin declared one. */
    public static function limitFor(Request $request, ?Router $router): ?int
    {
        if ($router === null || self::$limits === [] || !$request instanceof \Whity\Core\Request) {
            return null;
        }
        $route = $router->match($request);
        if ($route === null) {
            return null;
        }
        $entry = self::$limits[strtoupper($request->getMethod()) . ' ' . $route['path']] ?? null;
        if ($entry === null || $entry['owner'] !== ($route['namespacePrefix'] ?? null)) {
            return null;
        }

        return $entry['limit'];
    }

    /**
     * The limiter dimension: counted per route and per person (or address).
     * Skipped for any request whose route declared no limit.
     */
    public static function rule(Router $router): RateLimitRule
    {
        return new RateLimitRule(
            'plugin_route',
            static function (Request $request) use ($router): ?string {
                if (self::limitFor($request, $router) === null) {
                    return null;
                }
                $route = $router->match($request);
                $who = AuditContext::getActorUserId();
                $caller = $who !== null ? 'p' . $who : 'ip' . (ClientIp::fromRequest($request) ?? 'unknown');

                return hash('sha256', strtoupper($request->getMethod()) . ' ' . ($route['path'] ?? '')) . ':' . $caller;
            },
            self::MAX,
            self::WINDOW,
            static fn (Request $request): ?int => self::limitFor($request, $router),
        );
    }

    public static function removeOwner(string $owner): void
    {
        self::$limits = array_filter(self::$limits, static fn (array $e): bool => $e['owner'] !== $owner);
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$limits = [];
    }
}
