<?php

declare(strict_types=1);

namespace Whity\Storage\S3;

use Whity\Sdk\Http\Outbound\AddressPolicy;
use Whity\Sdk\Http\Outbound\HttpFailure;
use Whity\Storage\StorageException;

/**
 * Real {@see ObjectHttpTransport} using the codebase's established
 * `file_get_contents` + `stream_context` idiom (no curl/AWS-SDK dependency),
 * supporting arbitrary verbs, binary bodies and response-header capture
 * (WC-b8c5a271).
 *
 * NOTE: with no address policy, this transport does NOT apply the public-IP
 * SSRF guard: the operator's own S3 endpoint is global infrastructure and may
 * legitimately be a private-network MinIO in a sovereign deployment.
 *
 * A workspace's own endpoint is a person's input (WHIT-1284, #2240), so it is
 * built with an {@see AddressPolicy}: the host is resolved once, refused unless
 * every address is allowed, and the request goes to the address that was
 * checked (a second DNS answer can't swap it), while TLS still verifies the
 * host's own name.
 */
final class StreamObjectHttpTransport implements ObjectHttpTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxBytes = 104857600, // 100 MiB read cap
        private readonly ?AddressPolicy $addressPolicy = null,
    ) {
    }

    public function send(string $method, string $url, array $headers, ?string $body): ObjectHttpResponse
    {
        $ssl = [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ];
        if ($this->addressPolicy !== null) {
            [$url, $host] = $this->pinned($url, $headers);
            $ssl['peer_name'] = $host;
        }

        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'          => strtoupper($method),
                'header'          => $headerLines,
                'content'         => $body ?? '',
                'timeout'         => $this->timeoutSeconds,
                'ignore_errors'   => true, // read the body on 4xx/5xx instead of failing
                'max_redirects'   => 0,
                'follow_location' => 0,
            ],
            'ssl' => $ssl,
        ]);

        // Pre-declare so it is always defined; the stream wrapper overwrites it in
        // the local scope with the response header lines after the fetch.
        $http_response_header = [];
        $raw = @file_get_contents($url, false, $context, 0, max(0, $this->maxBytes));
        /** @var list<string> $lines */
        $lines = $http_response_header;

        if (!is_string($raw) && $lines === []) {
            throw new StorageException('object store request failed: ' . $method . ' ' . self::redactUrl($url));
        }

        [$status, $parsed] = self::parseHeaders($lines);
        return new ObjectHttpResponse($status, $parsed, is_string($raw) ? $raw : '');
    }

    /**
     * The URL with its host replaced by the checked address, and the host.
     * The caller's `Host` header (the S3 driver always sends it, and signs it)
     * keeps naming the original host.
     *
     * @param array<string, string> $headers
     * @return array{0: string, 1: string}
     */
    private function pinned(string $url, array &$headers): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if ($host === '' || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw new StorageException('object store request refused: not an https URL with a plain host');
        }
        $bare = trim($host, '[]');
        try {
            $address = $this->addressPolicy?->resolve($bare) ?? $bare;
        } catch (HttpFailure) {
            throw new StorageException('object store request refused: ' . $bare . ' is not on the public internet');
        }
        $hasHost = false;
        foreach (array_keys($headers) as $name) {
            $hasHost = $hasHost || strtolower((string) $name) === 'host';
        }
        if (!$hasHost) {
            $headers['Host'] = $bare . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
        $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $at = strpos($url, $host, strlen('https://'));
        $url = substr($url, 0, (int) $at) . $target . substr($url, (int) $at + strlen($host));

        return [$url, $bare];
    }

    /**
     * @param list<string> $lines Raw response header lines ($http_response_header).
     * @return array{0: int, 1: array<string, string>}
     */
    private static function parseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // A redirect chain would reset this; max_redirects=0 keeps one status.
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $name = strtolower(trim(substr($line, 0, $colon)));
                $headers[$name] = trim(substr($line, $colon + 1));
            }
        }
        return [$status, $headers];
    }

    /**
     * Strip the query string (a presigned URL would carry a signature) before
     * putting a URL in an exception message.
     */
    private static function redactUrl(string $url): string
    {
        $q = strpos($url, '?');
        return $q === false ? $url : substr($url, 0, $q);
    }
}
