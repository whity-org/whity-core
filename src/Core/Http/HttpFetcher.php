<?php

declare(strict_types=1);

namespace Whity\Core\Http;

use Whity\Sdk\Http\Outbound\AddressPolicy;

/**
 * SSRF-guarded outbound HTTP client (WC-ae16 / OIDC engine).
 *
 * Built on the codebase's established `file_get_contents` + `stream_context`
 * fetch idiom (no HTTP-client dependency), hardened for the OIDC use case where
 * the target URL (a tenant admin's `issuer` / `discovery_url` / token endpoint)
 * is CONFIGURED, not fully trusted — so a malicious or mistaken config must not
 * turn the server into an SSRF proxy to cloud-metadata or internal services.
 *
 * Guard ({@see isPubliclyRoutableUrl()}), applied before every request:
 *   - scheme MUST be https (blocks http/file/gopher/…);
 *   - the host must resolve ONLY to publicly-routable IPs — any private, loopback,
 *     link-local (e.g. 169.254.169.254) or reserved address blocks the request.
 * Redirects are disabled (max_redirects 0) so a 30x cannot bounce a vetted host
 * to an internal one, TLS peer verification is on, and responses are size- and
 * time-bounded.
 *
 * LIMITATION: DNS is resolved for the guard and again by the fetch, so a
 * determined attacker controlling DNS could in theory race the two (TOCTOU).
 * Full protection needs a pinned-IP connect, which the file_get_contents
 * transport cannot express; given the URL is admin-configured (not arbitrary
 * user input) the range-block is the pragmatic, documented mitigation.
 */
final class HttpFetcher implements HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly int $maxBytes = 1048576,
    ) {
    }

    /**
     * @param array<string, string> $headers Extra request headers (e.g. an `Authorization` bearer).
     */
    public function getJson(string $url, #[\SensitiveParameter] array $headers = []): ?array
    {
        return $this->decode($this->fetch('GET', $url, null, $headers));
    }

    public function postForm(string $url, #[\SensitiveParameter] array $params): ?array
    {
        return $this->decode($this->fetch('POST', $url, http_build_query($params)));
    }

    /**
     * POST a JSON object (e.g. an RFC 7591 client registration). Like postForm, the body of an
     * error response is decoded too, so the caller can read an OAuth `error`.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array<mixed>|null
     */
    public function postJson(string $url, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] array $headers = []): ?array
    {
        return $this->decode($this->fetch(
            'POST',
            $url,
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
            $headers,
            'application/json',
        ));
    }

    /**
     * GET a BINARY payload (e.g. a plugin package) under the SAME SSRF guard,
     * redirect-block, TLS verification and size/time bounds as the JSON path,
     * plus caller-supplied request headers (e.g. an `Authorization` bearer).
     *
     * Returns the raw bytes on a 2xx response, or null on any transport failure
     * or non-2xx status (so an error body — e.g. a 401 JSON — is never mistaken
     * for a package). Throws when the guard refuses the URL (a config/security
     * problem, distinct from a transient failure).
     *
     * @param array<string, string> $headers Extra request headers (name => value).
     * @throws \RuntimeException When the URL is non-public or non-https.
     */
    public function getBinary(string $url, array $headers = []): ?string
    {
        if (!self::isPubliclyRoutableUrl($url)) {
            throw new \RuntimeException('HttpFetcher: refused non-public or non-https URL');
        }

        $headerLines = "Accept: application/octet-stream, application/zip\r\n";
        foreach ($headers as $name => $value) {
            // Strip CR/LF so a crafted header name/value cannot inject additional
            // request headers (header-splitting).
            $name = str_replace(["\r", "\n"], '', $name);
            $value = str_replace(["\r", "\n"], '', $value);
            if ($name !== '') {
                $headerLines .= $name . ': ' . $value . "\r\n";
            }
        }

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => $headerLines,
                'timeout'         => $this->timeoutSeconds,
                'ignore_errors'   => true,   // read the body so we can check status
                'max_redirects'   => 0,      // never follow a redirect to a new host
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context, 0, max(0, $this->maxBytes));
        if ($raw === false) {
            return null;
        }
        // $http_response_header is populated by the stream wrapper on any completed
        // response (we only reach here after a non-false read).
        $status = self::statusFromHeaders($http_response_header);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        return $raw;
    }

    /**
     * Parse the numeric status from a stream-wrapper response-header list.
     *
     * @param list<string> $responseHeaders
     */
    private static function statusFromHeaders(array $responseHeaders): int
    {
        $status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1]; // keep the LAST status line (after any redirects)
            }
        }
        return $status;
    }

    /**
     * Whether $url is https AND its host resolves only to publicly-routable IPs.
     * Pure/deterministic for IP-literal hosts; performs DNS for hostnames.
     */
    public static function isPubliclyRoutableUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        // WHIT-1284 (#2214): no credentials in the authority. `https://a@b`
        // reads as host b to one parser and a to a careless reader.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }
        // Bracketed IPv6 literal → strip brackets.
        $host = trim($host, '[]');

        $ips = self::resolveIps($host);
        if ($ips === []) {
            // Unresolvable → cannot prove it is public → block (fail closed).
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::isGlobalIp($ip)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether one IP literal is on the public internet (WHIT-1248), by the
     * SDK's {@see AddressPolicy} (WHIT-1284), so core and plugins agree on it.
     * It also refuses multicast. NAT64 is refused here outright.
     *
     * FILTER_FLAG_GLOBAL_RANGE (RFC 6890) refuses everything the older
     * NO_PRIV_RANGE | NO_RES_RANGE pair did (private, loopback, link-local,
     * 0/8, 240/4) and also the ranges that pair let through: 100.64.0.0/10
     * (carrier-grade NAT, where some clouds put their metadata service),
     * 198.18.0.0/15, 192.0.0.0/24 and the documentation ranges.
     *
     * Two IPv6 forms carry an IPv4 address inside, and are judged by it:
     * IPv4-mapped (::ffff:127.0.0.1) and NAT64 (64:ff9b::/96, 64:ff9b:1::/48),
     * which RFC 6890 lists as global but which a NAT64 gateway turns back
     * into whatever IPv4 address they embed.
     */
    public static function isGlobalIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, "\x00\x64\xff\x9b")) {
            // 64:ff9b::/96 and the local-use 64:ff9b:1::/48: never fetch
            // through a translator, whatever it embeds.
            return false;
        }

        // WHIT-1284 (#2214): the one rule for "public", shared with plugins.
        return AddressPolicy::publicOnly()->permits($ip);
    }

    /**
     * @return list<string> Resolved IP literals for $host (or [$host] if it is
     *   already an IP literal), empty when a hostname cannot be resolved.
     */
    private static function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        // IPv4
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        // IPv6
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (isset($rec['ipv6']) && is_string($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function fetch(
        string $method,
        string $url,
        #[\SensitiveParameter] ?string $body,
        #[\SensitiveParameter] array $extraHeaders = [],
        string $contentType = 'application/x-www-form-urlencoded',
    ): ?string
    {
        if (!self::isPubliclyRoutableUrl($url)) {
            // A blocked URL is a configuration/security problem, not a transient
            // failure — surface it distinctly so the caller can log + 502 rather
            // than silently treating it like an unreachable host.
            throw new \RuntimeException('HttpFetcher: refused non-public or non-https URL');
        }

        $headers = "Accept: application/json\r\n";
        if ($body !== null) {
            $headers .= 'Content-Type: ' . $contentType . "\r\n";
        }
        foreach ($extraHeaders as $name => $value) {
            // Strip CR/LF so a header name or value cannot split the request.
            $name = str_replace(["\r", "\n"], '', (string) $name);
            $value = str_replace(["\r", "\n"], '', (string) $value);
            if ($name !== '') {
                $headers .= $name . ': ' . $value . "\r\n";
            }
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => $headers,
                'content'       => $body ?? '',
                'timeout'       => $this->timeoutSeconds,
                'ignore_errors' => true,   // read the body even on 4xx/5xx
                'max_redirects' => 0,      // never follow a redirect to a new host
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context, 0, max(0, $this->maxBytes));
        return $raw === false ? null : $raw;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(?string $body): ?array
    {
        if ($body === null || trim($body) === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
