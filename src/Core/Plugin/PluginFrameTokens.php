<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Auth\JwtParser;
use Whity\Core\Router;
use Whity\Sdk\Http\Request;

/**
 * The short-lived, scoped token an embedded plugin screen's calls run with (#1688).
 *
 * WHY THIS EXISTS
 * ---------------
 * An `embed` screen runs in a sandboxed frame with an opaque origin (WHIT-1217),
 * so it has no session: the app's cookies never reach its calls, and that is the
 * point. To let it call its own API after loading, the host page (which is
 * trusted, and holds the session) answers the frame's requests over the
 * postMessage bridge (`web/components/plugin/embed-screen.tsx`). It does NOT send
 * those calls with the person's cookies. It sends them with this token and with
 * cookies left out, so even a bug in the bridge cannot turn a frame's request into
 * one with the person's whole session.
 *
 * WHAT THE TOKEN CAN DO
 * ---------------------
 *  - It names one plugin (`plugin_ns`, the route namespace the plugin's routes
 *    are registered under). {@see self::admits()} lets it through only when the
 *    route the router resolves for the request belongs to that plugin. A core
 *    route, or another plugin's route, refuses it as an invalid token.
 *  - It acts as the person who asked for it, in the workspace they were in. The
 *    route's own permission is still checked against that person's live roles by
 *    RbacMiddleware, so the token never reaches more than the person could.
 *  - It lasts {@see self::TTL} seconds and is only accepted as an
 *    `Authorization: Bearer` header, never from a cookie.
 *  - It falls with the session it came from: the session's access token `jti` is
 *    carried as `session_jti` and checked for revocation alongside the token's
 *    own, and the profile's token epoch applies as for any access token.
 *
 * Issuing is {@see \Whity\Api\PluginFrameTokenApiHandler}: only for an `embed`
 * screen the person may see, and only from a session's own access token.
 */
final class PluginFrameTokens
{
    /** The JWT `type` of a frame token. */
    public const TYPE = 'plugin_frame';

    /** Lifetime in seconds. The bridge asks for a new one shortly before this runs out. */
    public const TTL = 300;

    /** Claims of the session that are never copied onto a frame token. */
    /**
     * The customer area's tokens (SDK 1.174, WHIT-1424) carry this claim set
     * to {@see self::AUDIENCE_CUSTOMER}: they open only the plugin's routes
     * declared for customers ({@see PluginCustomerRoutes}), in the workspace
     * of the area's address.
     */
    public const AUDIENCE_CLAIM = 'audience';

    public const AUDIENCE_CUSTOMER = 'customer';

    private const NOT_COPIED = ['jti', 'type', 'iat', 'exp', 'nbf', 'plugin_ns', 'feature', 'session_jti', self::AUDIENCE_CLAIM];

    private function __construct()
    {
    }

    /**
     * Mint a frame token from a session's access-token claims.
     *
     * Every identity claim of the session is kept (profile, active workspace,
     * token epoch, a workspace sign-in's confinement), so the frame token can
     * never be wider than the session that asked for it.
     *
     * @param array<string, mixed> $sessionClaims Claims of a live `access` token.
     * @return array{token: string, expiresIn: int}
     */
    public static function issue(
        JwtParser $jwt,
        array $sessionClaims,
        string $featureId,
        string $namespacePrefix
    ): array {
        if (($sessionClaims['type'] ?? null) !== 'access') {
            throw new \InvalidArgumentException('A frame token is issued only from a session access token.');
        }
        $sessionJti = $sessionClaims['jti'] ?? null;
        if (!is_string($sessionJti) || $sessionJti === '' || $namespacePrefix === '') {
            throw new \InvalidArgumentException('A frame token needs a session id and a plugin namespace.');
        }

        $claims = array_diff_key($sessionClaims, array_flip(self::NOT_COPIED));
        $claims['plugin_ns'] = $namespacePrefix;
        $claims['feature'] = $featureId;
        $claims['session_jti'] = $sessionJti;

        return [
            'token' => $jwt->create($claims, self::TTL, self::TYPE),
            'expiresIn' => self::TTL,
        ];
    }

    /**
     * A token for one plugin screen in a workspace's customer area (SDK 1.174,
     * WHIT-1424; design section 41 §9). Like {@see self::issue()}, but bound to
     * the workspace of the area's address instead of the session's active one,
     * and marked for customers, so it opens only that plugin's customer routes.
     * The caller has already checked the person's active membership there.
     *
     * @param array<string, mixed> $sessionClaims Claims of a live `access` token.
     * @return array{token: string, expiresIn: int}
     */
    public static function issueForCustomer(
        JwtParser $jwt,
        array $sessionClaims,
        string $screenId,
        string $namespacePrefix,
        int $workspaceId
    ): array {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('A customer frame token needs a workspace.');
        }
        if (($sessionClaims['type'] ?? null) !== 'access') {
            throw new \InvalidArgumentException('A frame token is issued only from a session access token.');
        }
        $sessionJti = $sessionClaims['jti'] ?? null;
        if (!is_string($sessionJti) || $sessionJti === '' || $namespacePrefix === '') {
            throw new \InvalidArgumentException('A frame token needs a session id and a plugin namespace.');
        }

        $claims = array_diff_key($sessionClaims, array_flip(self::NOT_COPIED));
        $claims['plugin_ns'] = $namespacePrefix;
        $claims['feature'] = $screenId;
        $claims['session_jti'] = $sessionJti;
        $claims['active_tenant_id'] = $workspaceId;
        $claims[self::AUDIENCE_CLAIM] = self::AUDIENCE_CUSTOMER;

        return ['token' => $jwt->create($claims, self::TTL, self::TYPE), 'expiresIn' => self::TTL];
    }

    /**
     * Whether a frame token's claims may act on this request.
     *
     * @param array<string, mixed> $claims Parsed claims with `type` = {@see self::TYPE}.
     * @param bool $fromHeader Whether the token came from the Authorization header.
     * @param (\Closure(array<string, mixed>): bool)|null $live Whether an access token's
     *        `jti` and epoch still stand; null only in unit wiring without a database.
     */
    public static function admits(
        array $claims,
        Request $request,
        ?Router $router,
        bool $fromHeader,
        ?\Closure $live
    ): bool {
        if (!$fromHeader || $router === null || !$request instanceof \Whity\Core\Request) {
            return false;
        }
        $namespace = $claims['plugin_ns'] ?? null;
        $sessionJti = $claims['session_jti'] ?? null;
        if (!is_string($namespace) || $namespace === '' || !is_string($sessionJti) || $sessionJti === '') {
            return false;
        }

        $route = $router->match($request);
        if ($route === null || ($route['namespacePrefix'] ?? null) !== $namespace) {
            return false;
        }
        // A customer area token opens only the plugin's customer routes (CA.18).
        if (($claims[self::AUDIENCE_CLAIM] ?? null) === self::AUDIENCE_CUSTOMER
            && !PluginCustomerRoutes::admits($request, $router, $namespace)) {
            return false;
        }

        if ($live === null) {
            return true;
        }

        return $live($claims) && $live(['jti' => $sessionJti] + $claims);
    }

    /**
     * The namespace of the plugin that registered `GET $path`, or null when no
     * plugin route serves it exactly (a core route, or nothing).
     */
    public static function ownerOfGetRoute(Router $router, string $path): ?string
    {
        foreach ($router->getRoutes() as $route) {
            if ($route['method'] === 'GET' && $route['path'] === $path) {
                $namespace = $route['namespacePrefix'];

                return $namespace !== null && $namespace !== '' ? $namespace : null;
            }
        }

        return null;
    }
}
