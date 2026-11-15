<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

/**
 * What a browser may hand us as its push subscription (WHIT-1073).
 *
 * The endpoint is a URL the server will later POST to, so it is checked as
 * strictly as anything else the server fetches: https only, no address
 * literals, no credentials or odd ports, and its host must belong to a known
 * browser push service. Browsers only ever give endpoints on those services,
 * so the list costs a real person nothing and stops the subscribe call from
 * becoming a way to make the server post to an address of someone's choosing.
 * An instance whose people use another push service (a self-hosted autopush,
 * say) adds its host with `PUSH_SERVICE_HOSTS` (comma-separated).
 *
 * The keys are checked for shape: `p256dh` is a 65-byte uncompressed P-256
 * point, `auth` is 16 bytes, both base64url.
 */
final class PushEndpoint
{
    /** Hosts (and their subdomains) of the browsers' push services. */
    public const KNOWN_SERVICES = [
        'fcm.googleapis.com',          // Chrome, Edge on Android, Samsung Internet, Opera, Brave
        'android.googleapis.com',      // older Chrome subscriptions
        'push.services.mozilla.com',   // Firefox
        'push.apple.com',              // Safari on macOS and iOS 16.4+
        'notify.windows.com',          // Edge on Windows
    ];

    public const MAX_LENGTH = 2048;

    /**
     * Why this subscription can't be stored, or null when it can.
     *
     * @param list<string> $extraHosts From PUSH_SERVICE_HOSTS.
     */
    public static function problem(string $endpoint, string $p256dh, string $auth, array $extraHosts = []): ?string
    {
        if ($endpoint === '' || strlen($endpoint) > self::MAX_LENGTH) {
            return 'endpoint must be a URL of at most ' . self::MAX_LENGTH . ' characters';
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return 'endpoint must be an https URL';
        }
        $host = strtolower((string) $parts['host']);
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || !self::isKnownHost($host, $extraHosts)) {
            return 'endpoint is not on a known push service';
        }

        $key = Base64Url::decode($p256dh);
        if ($key === null || strlen($key) !== 65 || $key[0] !== "\x04") {
            return 'keys.p256dh must be a P-256 public key';
        }
        $secret = Base64Url::decode($auth);
        if ($secret === null || strlen($secret) !== 16) {
            return 'keys.auth must be 16 bytes';
        }

        return null;
    }

    /** @param list<string> $extraHosts */
    public static function isKnownHost(string $host, array $extraHosts = []): bool
    {
        $host = strtolower(rtrim($host, '.'));
        foreach (array_merge(self::KNOWN_SERVICES, $extraHosts) as $known) {
            $known = strtolower(trim($known));
            if ($known !== '' && ($host === $known || str_ends_with($host, '.' . $known))) {
                return true;
            }
        }

        return false;
    }

    /**
     * PUSH_SERVICE_HOSTS as a list.
     *
     * @param array<string, mixed> $env
     * @return list<string>
     */
    public static function extraHostsFrom(array $env): array
    {
        $raw = (string) ($env['PUSH_SERVICE_HOSTS'] ?? '');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $h): bool => $h !== ''));
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
