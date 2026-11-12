<?php

declare(strict_types=1);

namespace Whity\Http;

use Whity\Core\Request;
use Whity\Core\Response;

/**
 * CORS for the two endpoints whity.dev calls (WS.5, section 29):
 * `GET /api/public/plans` and `POST /api/public/contact`.
 *
 * Neither reads a session, so unlike {@see Cors} these never allow
 * credentials, and they allow the website's own origin rather than the app's
 * allowlist. The origins are `WHITY_SITE_ORIGINS` (comma-separated), by
 * default `https://whity.dev`. A request from another origin still gets the
 * answer, just without a header that lets that page's scripts read it.
 */
final class WebsiteCors
{
    public const DEFAULT_ORIGINS = 'https://whity.dev';
    private const PATHS = '#^/api(/v1)?/public/(plans|contact)$#D';

    public static function owns(Request $request): bool
    {
        return preg_match(self::PATHS, $request->getPath()) === 1;
    }

    /** @return array<string, string> */
    public static function headers(?string $origin): array
    {
        $allowed = self::origins();
        $chosen = $origin !== null && in_array($origin, $allowed, true) ? $origin : $allowed[0];

        return [
            'Access-Control-Allow-Origin' => $chosen,
            'Access-Control-Expose-Headers' => 'Retry-After',
            'Vary' => 'Origin',
        ];
    }

    /** The answer to a browser's preflight on these paths, or null for any other request. */
    public static function preflight(Request $request): ?Response
    {
        if ($request->getMethod() !== 'OPTIONS' || !self::owns($request)) {
            return null;
        }

        return new Response(204, '', array_merge(self::headers($request->getHeader('Origin')), [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '86400',
        ]));
    }

    /** The website's first origin, where the scripts-off form is sent back to. */
    public static function siteOrigin(): string
    {
        return self::origins()[0];
    }

    /** @return non-empty-list<string> */
    public static function origins(): array
    {
        $raw = $_ENV['WHITY_SITE_ORIGINS'] ?? getenv('WHITY_SITE_ORIGINS');
        $out = [];
        foreach (explode(',', is_string($raw) && trim($raw) !== '' ? $raw : self::DEFAULT_ORIGINS) as $o) {
            $o = rtrim(trim($o), '/');
            if ($o !== '') {
                $out[] = $o;
            }
        }

        return $out !== [] ? $out : [self::DEFAULT_ORIGINS];
    }
}
