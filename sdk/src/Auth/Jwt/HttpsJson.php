<?php

declare(strict_types=1);

namespace Whity\Sdk\Auth\Jwt;

/**
 * A small outbound HTTPS GET for issuer metadata and key sets (SDK 1.106).
 *
 * Only `https://` URLs, a short timeout, no redirects, a size cap, and the body
 * must decode to a JSON object or array. Returns null on any failure. A host
 * with its own SSRF-guarded client should pass that to {@see JwksCache} and
 * {@see IssuerTokenVerifier} instead; this is for a plugin or service with
 * nothing better.
 */
final class HttpsJson
{
    public const MAX_BYTES = 1048576;

    private function __construct()
    {
    }

    /** @return array<mixed>|null */
    public static function get(string $url, int $timeoutSeconds = 5): ?array
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'follow_location' => 0,
                'ignore_errors' => false,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context, 0, self::MAX_BYTES + 1);
        if (!is_string($body) || strlen($body) > self::MAX_BYTES) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
