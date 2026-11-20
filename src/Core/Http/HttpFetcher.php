<?php

declare(strict_types=1);

namespace Whity\Core\Http;

use Whity\Sdk\Http\Outbound\HttpFailure;
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
 * PINNED (WHIT-1303, #2188): the host is resolved ONCE, the addresses are
 * checked, and the request connects to that checked address, with the original
 * host in `Host` and as the TLS peer name (so the certificate is still checked
 * against it, and SNI still names it). A host whose DNS answers the check with
 * a public address and the fetch with 169.254.169.254 never gets a second
 * lookup to rebind. Same pinning as the S3 transport (WHIT-1284).
 */
final class HttpFetcher implements HttpClient
{
    /**
     * @param (\Closure(string): list<string>)|null $resolver host => its
     *        addresses; the system's DNS by default. Tests pass their own.
     * @param (\Closure(string, array<string, mixed>): array{0: string|false, 1: list<string>})|null $transport
     *        (pinned url, stream context options) => [body or false, response
     *        header lines]; file_get_contents by default. Tests pass their own.
     */
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly int $maxBytes = 1048576,
        private readonly ?\Closure $resolver = null,
        private readonly ?\Closure $transport = null,
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
        [$target, $hostHeader, $peer] = $this->pinned($url);

        $headerLines = 'Host: ' . $hostHeader . "\r\n";
        $headerLines .= "Accept: application/octet-stream, application/zip\r\n";
        foreach ($headers as $name => $value) {
            // Strip CR/LF so a crafted header name/value cannot inject additional
            // request headers (header-splitting).
            $name = str_replace(["\r", "\n"], '', $name);
            $value = str_replace(["\r", "\n"], '', $value);
            if ($name !== '' && strtolower($name) !== 'host') {
                $headerLines .= $name . ': ' . $value . "\r\n";
            }
        }

        [$raw, $responseHeaders] = $this->send($target, [
            'http' => [
                'method'          => 'GET',
                'header'          => $headerLines,
                'timeout'         => $this->timeoutSeconds,
                'ignore_errors'   => true,   // read the body so we can check status
                'max_redirects'   => 0,      // never follow a redirect to a new host
                'follow_location' => 0,
            ],
            'ssl' => self::tls($peer),
        ]);
        if ($raw === false) {
            return null;
        }
        $status = self::statusFromHeaders($responseHeaders);
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
        // A blocked URL is a configuration/security problem, not a transient
        // failure: pinned() throws so the caller can log + 502 rather than
        // treating it like an unreachable host.
        [$target, $hostHeader, $peer] = $this->pinned($url);

        $headers = 'Host: ' . $hostHeader . "\r\n";
        $headers .= "Accept: application/json\r\n";
        if ($body !== null) {
            $headers .= 'Content-Type: ' . $contentType . "\r\n";
        }
        foreach ($extraHeaders as $name => $value) {
            // Strip CR/LF so a header name or value cannot split the request.
            $name = str_replace(["\r", "\n"], '', (string) $name);
            $value = str_replace(["\r", "\n"], '', (string) $value);
            if ($name !== '' && strtolower($name) !== 'host') {
                $headers .= $name . ': ' . $value . "\r\n";
            }
        }

        [$raw] = $this->send($target, [
            'http' => [
                'method'        => $method,
                'header'        => $headers,
                'content'       => $body ?? '',
                'timeout'       => $this->timeoutSeconds,
                'ignore_errors' => true,   // read the body even on 4xx/5xx
                'max_redirects' => 0,      // never follow a redirect to a new host
                'follow_location' => 0,
            ],
            'ssl' => self::tls($peer),
        ]);

        return $raw === false ? null : $raw;
    }

    /**
     * The URL with its host replaced by the one checked address, the `Host`
     * header value, and the TLS peer name (WHIT-1303, #2188). The host is
     * resolved here and nowhere else.
     *
     * @return array{0: string, 1: string, 2: string}
     * @throws \RuntimeException when the URL is not https with a plain public host
     */
    private function pinned(string $url): array
    {
        $refused = new \RuntimeException('HttpFetcher: refused non-public or non-https URL');
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw $refused;
        }
        $host = (string) ($parts['host'] ?? '');
        $bare = trim($host, '[]');
        if ($bare === '') {
            throw $refused;
        }

        $resolver = $this->resolver ?? self::resolveIps(...);
        $policy = AddressPolicy::publicOnly(static function (string $name) use ($resolver): array {
            $addresses = $resolver($name);
            foreach ($addresses as $address) {
                if (!self::isGlobalIp($address)) {
                    return []; // NAT64 and the like: refuse the host, not just pick another address
                }
            }

            return $addresses;
        });
        try {
            $address = $policy->resolve($bare);
        } catch (HttpFailure) {
            throw $refused;
        }
        if (!self::isGlobalIp($address)) {
            throw $refused;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $at = (int) strpos($url, $host, strlen('https://'));
        $pinnedUrl = substr($url, 0, $at) . $target . substr($url, $at + strlen($host));

        return [$pinnedUrl, (str_contains($bare, ':') ? '[' . $bare . ']' : $bare) . $port, $bare];
    }

    /**
     * TLS checked against the original host, not the address connected to.
     *
     * @return array<string, mixed>
     */
    private static function tls(string $peer): array
    {
        return [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $peer,
            'SNI_enabled'      => true,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: string|false, 1: list<string>}
     */
    private function send(string $pinnedUrl, array $options): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($pinnedUrl, $options);
        }
        $http_response_header = [];
        $raw = @file_get_contents($pinnedUrl, false, stream_context_create($options), 0, max(0, $this->maxBytes));
        /** @var list<string> $lines */
        $lines = $http_response_header;

        return [$raw, $lines];
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
