<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\PublicForm;

/**
 * The origins a public form key may be posted from.
 *
 * An origin is a scheme, a host and an optional port, written the way a browser
 * sends it. A wildcard, a path or `null` isn't one. `http` is accepted for a
 * loopback host only, so a form on a real site can't be opened to plain HTTP.
 */
final class PublicFormOrigins
{
    public const MAX_ORIGINS = 20;

    /** The origin in the form a browser sends it, or null if it isn't one. */
    public static function normalize(string $origin): ?string
    {
        $origin = strtolower(trim($origin));
        if (preg_match('#^(https?)://([a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\[[0-9a-f:.]+\])(:[0-9]{1,5})?$#D', $origin, $m) !== 1) {
            return null;
        }
        $isLoopback = in_array($m[2], ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($m[2], '.localhost');
        if ($m[1] === 'http' && !$isLoopback) {
            return null;
        }

        return $origin;
    }

    /**
     * @param list<string> $keyOrigins      The key's own list.
     * @param list<string> $instanceOrigins The instance's allowed origins.
     */
    public static function allows(?string $origin, array $keyOrigins, array $instanceOrigins): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }
        $origin = strtolower(trim($origin));
        if ($origin === 'null') {
            return false;
        }

        return in_array($origin, $keyOrigins, true)
            || in_array($origin, array_map(static fn (string $o): string => strtolower($o), $instanceOrigins), true);
    }
}
