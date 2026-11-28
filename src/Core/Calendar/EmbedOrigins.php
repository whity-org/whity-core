<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * The sites that may show the booking page in their own pages (WHIT-1445,
 * design section 39 §9.1, AP.24): Settings › Calendar › Your website.
 *
 * Each entry is an https origin, `https://example-bakery.com` or
 * `https://shop.example-bakery.com:8443`: lower case, no wildcard, no path,
 * no query, no user. At most {@see self::MAX}. The list becomes the
 * `frame-ancestors` of `/book/{workspace}/embed` and of nothing else; an empty
 * list is `'none'`, so nothing frames the page until the workspace adds a site.
 */
final class EmbedOrigins
{
    public const MAX = 10;

    private const ORIGIN = '#^https://([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+(?::([0-9]{1,5}))?$#';

    /** @return list<string> the stored list, as origins */
    public static function parse(string $stored): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $stored) ?: [] as $entry) {
            if ($entry !== '' && self::isOrigin($entry)) {
                $out[$entry] = $entry;
            }
        }

        return array_values($out);
    }

    /** Null when every entry is a valid origin, otherwise why not. */
    public static function validate(string $stored): ?string
    {
        $entries = array_values(array_filter(preg_split('/[\s,]+/', $stored) ?: [], static fn (string $e): bool => $e !== ''));
        if (count($entries) > self::MAX) {
            return 'Add up to ' . self::MAX . ' websites.';
        }
        foreach ($entries as $entry) {
            if (!self::isOrigin($entry)) {
                return 'Each website is its address starting with https://, such as https://example-bakery.com, with nothing after the name. "' . mb_substr($entry, 0, 80) . '" is not.';
            }
        }

        return null;
    }

    /** The `frame-ancestors` source list: the sites, or `'none'`. */
    public static function frameAncestors(string $stored): string
    {
        $origins = self::parse($stored);

        return $origins === [] ? "'none'" : implode(' ', $origins);
    }

    private static function isOrigin(string $entry): bool
    {
        if (strlen($entry) > 253 || preg_match(self::ORIGIN, $entry, $m) !== 1) {
            return false;
        }
        $port = isset($m[3]) ? (int) $m[3] : null;

        return $port === null || ($port >= 1 && $port <= 65535);
    }
}
