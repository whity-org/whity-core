<?php

declare(strict_types=1);

namespace Whity\Auth;

/**
 * Sessions confined to one workspace (#2249).
 *
 * A workspace's own sign-in provider (a TENANT-TRUST, bring-your-own OIDC
 * provider) speaks only for that workspace. The workspace's admins run it, so
 * it can assert any of the workspace's members. The session it yields must
 * therefore stay inside that workspace: it may not switch into another of the
 * person's workspaces (System Tenant 0 included), and it may not change what
 * every other workspace relies on: the person's email addresses, linked
 * sign-ins, phone, two-step setup, devices, MCP tokens, sessions elsewhere, or
 * workspace ownership offers.
 *
 * The confinement is a signed claim on the access and refresh tokens, so it
 * survives a refresh and every re-mint without a lookup. A claim that is
 * present but not a non-negative int is treated as confined to no workspace
 * at all (fails closed).
 */
final class TenantConfinement
{
    /** The JWT claim carrying the one workspace a session is confined to. */
    public const CLAIM = 'confined_tenant_id';

    /** Answer a confined session gets on a route it may not use. */
    public const REFUSAL = 'This sign-in only works inside the workspace that provided it';

    /**
     * Routes that act on the person's global account or on other workspaces.
     * Matched against the path without its query, with or without `/v1`.
     *
     * @var list<string>
     */
    private const GLOBAL_ROUTE_PATTERNS = [
        '#^/api(?:/v1)?/auth/switch-tenant$#',
        '#^/api(?:/v1)?/auth/2fa/(?:setup|confirm|disable|regenerate-codes)$#',
        '#^/api(?:/v1)?/me/emails(?:/|$)#',
        '#^/api(?:/v1)?/me/identities(?:/|$)#',
        '#^/api(?:/v1)?/me/ownership-offers(?:/|$)#',
        '#^/api(?:/v1)?/me/sessions(?:/|$)#',
        '#^/api(?:/v1)?/me/logout-others$#',
        '#^/api(?:/v1)?/devices(?:/|$)#',
        '#^/api(?:/v1)?/mcp/(?:tokens|connect)(?:/|$)#',
    ];

    /**
     * Routes a confined session may read but not change.
     *
     * @var list<string>
     */
    private const GLOBAL_WRITE_PATTERNS = [
        '#^/api(?:/v1)?/me$#',
        '#^/api(?:/v1)?/me/phone$#',
        '#^/api(?:/v1)?/me/signature$#',
    ];

    /**
     * The workspace the claims are confined to; null when they are not confined.
     * A malformed claim confines to -1, a workspace that never exists.
     *
     * @param array<string, mixed> $claims
     */
    public static function of(array $claims): ?int
    {
        if (!array_key_exists(self::CLAIM, $claims) || $claims[self::CLAIM] === null) {
            return null;
        }
        $value = $claims[self::CLAIM];

        return is_int($value) && $value >= 0 ? $value : -1;
    }

    /**
     * Whether a confined session may use this route. Tenant-scoped routes stay
     * open: the active workspace of a confined session is the confined one.
     */
    public static function allows(string $method, string $path): bool
    {
        foreach (self::GLOBAL_ROUTE_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        }
        $method = strtoupper($method);
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return true;
        }
        foreach (self::GLOBAL_WRITE_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        }

        return true;
    }
}
