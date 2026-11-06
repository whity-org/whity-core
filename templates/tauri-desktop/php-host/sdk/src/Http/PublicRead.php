<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * What the host tells a plugin's public read handler (SDK 1.69, WHIT-704).
 *
 * A route declared `'public' => 'read'` on GET `/api/<slug>/public/<name>/{key}`
 * runs only after the host has resolved the tenant from the key in the path and
 * passed its limits and Origin check, and only on an instance whose operator has
 * switched public reads on. {@see self::from()} reads the result. It never comes
 * from anything the caller sent, so use {@see self::$tenantId} and don't read a
 * tenant from a header, the query string or the Host.
 *
 * The handler answers strangers: return only what anybody holding the link may
 * see, and a 4xx on bad input rather than throwing.
 */
final class PublicRead
{
    /** The request attribute the host sets. Read it through {@see self::from()}. */
    public const ATTRIBUTE = 'whity.public_read';

    /**
     * @param string $origin The page's origin, or '' when the caller sent none
     *                       (a link opened directly, or a server).
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $plugin,
        public readonly string $name,
        public readonly string $origin,
    ) {
    }

    /** The gate's result for this request, or null if the host set none. */
    public static function from(Request $request): ?self
    {
        $value = $request->getAttribute(self::ATTRIBUTE);

        return $value instanceof self ? $value : null;
    }
}
