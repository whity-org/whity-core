<?php

declare(strict_types=1);

namespace Whity\Core\Http;

use Whity\Sdk\Http\Request;

/**
 * The host a request was made to: the one rule every host-based workspace
 * lookup uses (custom domains, branding, SSO, public booking, chat, sign-up).
 *
 * `X-Forwarded-Host` comes first. The web app's proxies drop any copy the
 * browser sent and set it from the host the browser asked for (WHIT-1287,
 * #2195), so it is trusted the same way as {@see \Whity\Core\RateLimit\ClientIp}:
 * the backend is reachable only through that proxy. It is used only when it is
 * one plain host name or address with an optional port, the same shape the
 * proxy's `trustedForwardedHost()` sends. Anything else (a list, a path, a
 * scheme) falls back to `Host`, so a malformed value can't resolve to "no
 * workspace" and skip a check that the domain belongs to the workspace named.
 *
 * The result is lowercased, or '' when neither header is usable.
 */
final class RequestHost
{
    public const FORWARDED_HEADER = 'X-Forwarded-Host';

    private const MAX_LENGTH = 260;

    public static function fromRequest(Request $request): string
    {
        return self::plain($request->getHeader(self::FORWARDED_HEADER))
            ?? self::plain($request->getHeader('Host'))
            ?? '';
    }

    private static function plain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $host = strtolower(trim($value));
        if ($host === '' || strlen($host) > self::MAX_LENGTH) {
            return null;
        }

        return preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(:\d{1,5})?$/', $host) === 1 ? $host : null;
    }
}
