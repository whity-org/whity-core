<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

use Whity\Sdk\Http\Request;

/**
 * Trusted client-IP extraction (WC-b19ff21a; authenticated since #2386, CE-4).
 *
 * The client IP comes from {@see self::HEADER}, an INTERNAL header set by a
 * trusted front proxy: the Next.js API proxy, or a reverse proxy in front of
 * the whole origin that sends `/api` straight here. That proxy derives the real
 * client IP from its own ingress, sets this header, and strips any
 * client-supplied copy (with raw `X-Forwarded-For` / `X-Real-IP`). Raw
 * forwarding headers are never trusted here.
 *
 * WHO MAY SET THE HEADER (READINESS CE-4, TM-19)
 * ----------------------------------------------
 * Until #2386 the header was believed from ANY caller, so the only protection
 * was that nobody but the proxy could reach this port. A shared-origin setup
 * that routes `/api` directly to the backend makes that easy to get wrong, and
 * then a caller who rotates the header bypasses every per-IP limit. Two ways
 * to say who may set it, either or both:
 *
 *  - {@see self::SECRET_ENV} (`WHITY_CLIENT_IP_SECRET`, 32+ characters): the
 *    header must come with {@see self::SIGNATURE_HEADER}, an HMAC-SHA256 of the
 *    IP and a timestamp under that secret, no older than
 *    {@see self::MAX_SKEW_SECONDS}. The Next.js proxy signs when the web
 *    container has the same secret.
 *  - {@see self::PROXIES_ENV} (`WHITY_TRUSTED_PROXIES`): addresses and CIDR
 *    ranges whose header is believed as it is, for a proxy that cannot sign
 *    (Caddy, nginx, a load balancer).
 *
 * When either is configured and the request passes neither, the header is
 * ignored and the client IP is the peer address (`REMOTE_ADDR`): the caller is
 * then counted as itself and can rotate nothing. When NEITHER is configured,
 * the header is believed from any caller, as before, so an existing deployment
 * behind the bundled proxy keeps its real client IPs. The shared-origin guide
 * (docs/wiki/Same-Origin-Setup.md) requires one of them.
 *
 * The decision is made ONCE per request, in {@see self::authenticate()} at the
 * entry point, and stored on the request; every caller of
 * {@see self::fromRequest()} reads that answer. The result is capped at the
 * IPv6 textual maximum (45 chars) so it is safe as a counter key and audit
 * column value.
 */
final class ClientIp
{
    /** Internal, proxy-set header carrying the client IP. */
    public const HEADER = 'X-Whity-Client-Ip';

    /** `v1=<unix time>.<hex HMAC-SHA256 of "v1\n<time>\n<ip>">`. */
    public const SIGNATURE_HEADER = 'X-Whity-Client-Ip-Signature';

    public const SECRET_ENV = 'WHITY_CLIENT_IP_SECRET';
    public const PROXIES_ENV = 'WHITY_TRUSTED_PROXIES';

    /** A signature older or newer than this is refused (clock skew and replay window). */
    public const MAX_SKEW_SECONDS = 300;

    /** A secret shorter than this is treated as unset: too easy to guess. */
    public const MIN_SECRET_LENGTH = 32;

    /** Where {@see self::authenticate()} leaves its answer on the request. */
    public const ATTRIBUTE = 'whity.client_ip';

    private const MAX_LENGTH = 45;

    /**
     * The client IP for this request. After {@see self::authenticate()} this is
     * its answer; before it (a unit test, a sub-request built by hand) the
     * header as it stands.
     */
    public static function fromRequest(Request $request): ?string
    {
        if ($request->hasAttribute(self::ATTRIBUTE)) {
            $resolved = $request->getAttribute(self::ATTRIBUTE);

            return is_string($resolved) && $resolved !== '' ? $resolved : null;
        }

        return self::clean($request->getHeader(self::HEADER));
    }

    /**
     * Decide whether this request's client-IP header is to be believed, and
     * store the answer on the request for {@see self::fromRequest()}.
     *
     * @param array<string, mixed>       $server $_SERVER (REMOTE_ADDR)
     * @param array<string, string>|null $env    Overrides for tests; null reads the environment
     */
    public static function authenticate(Request $request, array $server, ?array $env = null, ?int $now = null): void
    {
        $request->setAttribute(self::ATTRIBUTE, self::resolve($request, $server, $env, $now));
    }

    /**
     * @param array<string, mixed>       $server
     * @param array<string, string>|null $env
     */
    public static function resolve(Request $request, array $server, ?array $env = null, ?int $now = null): ?string
    {
        $secret = self::env(self::SECRET_ENV, $env);
        $secret = strlen($secret) >= self::MIN_SECRET_LENGTH ? $secret : '';
        $proxies = self::parseProxies(self::env(self::PROXIES_ENV, $env));
        $claimed = self::clean($request->getHeader(self::HEADER));
        $remote = self::clean(is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null);

        if ($secret === '' && $proxies === []) {
            // Not configured: the behaviour before #2386.
            return $claimed;
        }
        if ($claimed === null) {
            return $remote;
        }
        if ($remote !== null && self::inAny($remote, $proxies)) {
            return $claimed;
        }
        if ($secret !== '' && self::signatureValid($claimed, $request->getHeader(self::SIGNATURE_HEADER), $secret, $now ?? time())) {
            return $claimed;
        }

        return $remote;
    }

    /** The signature header value a proxy sends for this IP, at this time. */
    public static function sign(string $ip, string $secret, int $time): string
    {
        return 'v1=' . $time . '.' . hash_hmac('sha256', "v1\n{$time}\n{$ip}", $secret);
    }

    /** Whether `$value` is a valid, current signature of `$ip`. */
    public static function signatureValid(string $ip, ?string $value, string $secret, int $now): bool
    {
        if ($value === null || preg_match('/^v1=(\d{1,12})\.([0-9a-f]{64})$/', trim($value), $m) !== 1) {
            return false;
        }
        $time = (int) $m[1];
        if (abs($now - $time) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "v1\n{$time}\n{$ip}", $secret), $m[2]);
    }

    /**
     * Addresses and CIDR ranges, comma-separated. Anything that is neither is
     * dropped rather than widening trust by accident.
     *
     * @return list<array{string, int}> packed network address and prefix length
     */
    public static function parseProxies(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            [$address, $bits] = array_pad(explode('/', $part, 2), 2, null);
            $packed = @inet_pton((string) $address);
            if ($packed === false) {
                continue;
            }
            $max = strlen($packed) * 8;
            if ($bits === null) {
                $prefix = $max;
            } elseif (ctype_digit($bits) && (int) $bits <= $max) {
                $prefix = (int) $bits;
            } else {
                continue;
            }
            $out[] = [$packed, $prefix];
        }

        return $out;
    }

    /** @param list<array{string, int}> $ranges */
    public static function inAny(string $ip, array $ranges): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as [$network, $prefix]) {
            if (strlen($network) !== strlen($packed)) {
                continue;
            }
            $bytes = intdiv($prefix, 8);
            $bits = $prefix % 8;
            if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            if ($bits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    private static function clean(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        $ip = trim($ip);

        return $ip === '' ? null : substr($ip, 0, self::MAX_LENGTH);
    }

    /** @param array<string, string>|null $env */
    private static function env(string $name, ?array $env): string
    {
        if ($env !== null) {
            return trim($env[$name] ?? '');
        }
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
