<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

use Whity\Auth\JwtParser;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Which tenant a PUBLIC presentation endpoint answers for: the authenticated
 * tenant, else the one the request host names, else the global layer (0).
 *
 * Used by `GET /api/v1/branding` and `GET /api/v1/ui/preferences`. Both sit on
 * {@see \Whity\Http\Middleware\EnforceTenantIsolation}'s public list, which
 * returns them to the pipeline BEFORE tenant resolution runs, so
 * `TenantContext` is empty there even for a caller holding a good session.
 * Stopping at the context and falling through to the host is what broke the
 * workspace main colour (#1716): a signed-in admin saved their colour, and
 * branding kept answering from the host's tenant (on a shared host, the global
 * layer), so the card snapped back and the theme never changed.
 *
 * The context check stays first because a non-public caller (a test, or a
 * handler mounted behind the middleware) has one and it is authoritative.
 *
 * It does NOT lock the context: the route is public, and a public request that
 * pinned a request-scoped tenant would be a side effect nothing downstream
 * expects. The claim is read and used, and that is all.
 *
 * Holds no request state — safe for a FrankenPHP worker.
 */
final class DisplayTenantResolver
{
    public function __construct(
        private readonly HostResolver $hostResolver,
        private readonly ?JwtParser $jwtParser = null,
    ) {
    }

    public function resolve(Request $request): int
    {
        $ctx = TenantContext::getTenantId();
        if ($ctx !== null) {
            return $ctx;
        }

        $fromToken = $this->tenantFromToken($request);
        if ($fromToken !== null) {
            return $fromToken;
        }

        $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';

        return $this->hostResolver->resolveTenantIdByHost($host) ?? 0;
    }

    /**
     * The active tenant a valid session token names, or null.
     *
     * Fails SILENTLY on anything it does not like (no parser, no token, a token
     * that will not parse, a claim that is not an integer): these endpoints make
     * no authentication decision, and the worst outcome of an unreadable token
     * is the host's answer, which anonymous readers get anyway.
     */
    private function tenantFromToken(Request $request): ?int
    {
        if ($this->jwtParser === null) {
            return null;
        }

        $token = $this->bearerOrCookieToken($request);
        if ($token === null) {
            return null;
        }

        $payload = $this->jwtParser->parse($token);
        if (!is_array($payload)) {
            return null;
        }

        $claim = $payload['active_tenant_id'] ?? $payload['tenant_id'] ?? null;

        return is_int($claim) || (is_string($claim) && ctype_digit($claim)) ? (int) $claim : null;
    }

    /**
     * The access token, from the Authorization header or the session cookie.
     *
     * Mirrors {@see TenantContext}'s own extraction. Duplicated rather than
     * exposed because that one is `private`, and making it public would invite
     * handlers to resolve tenants for themselves, which the middleware exists
     * to stop.
     */
    private function bearerOrCookieToken(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader === null) {
            return null;
        }

        foreach (explode(';', $cookieHeader) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if (count($parts) === 2 && $parts[0] === 'access_token') {
                return $parts[1];
            }
        }

        return null;
    }
}
