<?php

declare(strict_types=1);

namespace Whity\Sdk\Http\Outbound;

/**
 * Outbound HTTP over PHP's own streams (SDK 1.114, WHIT-1058).
 *
 * Safe by default:
 *  - HTTPS only, with the certificate and host name verified. Plain http only
 *    when the code asks for it with `allowHttp: true` (a builder on a local
 *    address, a test server).
 *  - No redirects: a 3xx comes back as the response, for the caller to judge.
 *  - A timeout for connecting and for reading, and a cap on the answer's size.
 *  - No credentials in the URL, and no line breaks in header names or values.
 *  - A failure says which host, at most. Never the path, the query, a header
 *    or a body, so it is safe to log.
 *
 * Response headers come back on {@see HttpResponse::$headers} (SDK 1.122). For
 * a URL a person supplied, pass an {@see AddressPolicy} so the request can't
 * reach the server's own network (SDK 1.122, #2151).
 *
 * Streams rather than curl, so the SDK needs no PHP extension a host might not
 * have (the desktop app's PHP among them).
 */
final class StreamHttpClient implements HttpClient
{
    public const DEFAULT_MAX_BYTES = 5_242_880;

    private const HEADER_NAME = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/';

    public function __construct(
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly bool $allowHttp = false,
        private readonly ?AddressPolicy $addressPolicy = null,
    ) {
        if ($timeoutSeconds < 1 || $maxBytes < 1) {
            throw new \InvalidArgumentException('The timeout and the size cap must be positive.');
        }
    }

    public function send(string $method, string $url, array $headers, ?string $body = null): HttpResponse
    {
        $method = strtoupper($method);
        if (preg_match('/^[A-Z]{1,16}$/', $method) !== 1) {
            throw new HttpFailure('Not an HTTP method.');
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if ($host === '' || !in_array($scheme, ['https', 'http'], true)) {
            throw new HttpFailure('Not an http(s) URL.');
        }
        if ($scheme === 'http' && !$this->allowHttp) {
            throw new HttpFailure("Only https is allowed (request to {$host}).");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpFailure("Credentials don't go in the URL (request to {$host}). Send them in a header.");
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            if (preg_match(self::HEADER_NAME, (string) $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new HttpFailure("A header name or value is not allowed (request to {$host}).");
            }
            if ($this->addressPolicy !== null && strtolower((string) $name) === 'host') {
                continue; // the policy sets Host to the host it checked
            }
            $lines[] = $name . ': ' . $value;
        }

        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
        ];
        if ($this->addressPolicy !== null) {
            // Connect to the address that was checked, never to a second DNS
            // answer; TLS and Host still carry the name the caller asked for.
            $address = $this->addressPolicy->resolve($host);
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $url = $scheme . '://' . (str_contains($address, ':') ? '[' . $address . ']' : $address) . $port
                . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $lines[] = 'Host: ' . $host . $port;
            $ssl['peer_name'] = trim($host, '[]');
        }
        $lines[] = 'Connection: close';

        $http = [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'timeout' => (float) $this->timeoutSeconds,
            'follow_location' => 0,
            'max_redirects' => 1,
            'ignore_errors' => true,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $context = stream_context_create([
            'http' => $http,
            'ssl' => $ssl,
        ]);

        // Connecting is bounded by default_socket_timeout, reading by the
        // context's timeout; both are this client's. PHP's own warnings can
        // quote the URL, so they are swallowed and replaced by the host alone.
        $previousTimeout = ini_set('default_socket_timeout', (string) $this->timeoutSeconds);
        set_error_handler(static fn (): bool => true);
        try {
            $stream = fopen($url, 'rb', false, $context);
            if ($stream === false) {
                throw new HttpFailure("The request to {$host} got no answer.");
            }
            $answer = stream_get_contents($stream, $this->maxBytes + 1);
            $meta = stream_get_meta_data($stream);
            fclose($stream);
        } finally {
            restore_error_handler();
            if ($previousTimeout !== false) {
                ini_set('default_socket_timeout', $previousTimeout);
            }
        }

        if ($meta['timed_out']) {
            throw new HttpFailure("The request to {$host} timed out.");
        }
        if ($answer === false) {
            throw new HttpFailure("The answer from {$host} could not be read.");
        }
        if (strlen($answer) > $this->maxBytes) {
            throw new HttpFailure("The answer from {$host} is larger than {$this->maxBytes} bytes.");
        }

        $status = 0;
        $answerHeaders = [];
        $wrapperData = $meta['wrapper_data'];
        foreach (is_array($wrapperData) ? $wrapperData : [] as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // A new status line starts a new header block (a 100 Continue first, say).
                $status = (int) $m[1];
                $answerHeaders = [];
            } elseif (preg_match('/^([^:\s]+):\s*(.*)$/', $line, $m) === 1) {
                $name = strtolower($m[1]);
                $answerHeaders[$name] = isset($answerHeaders[$name]) ? $answerHeaders[$name] . ', ' . trim($m[2]) : trim($m[2]);
            }
        }
        if ($status === 0) {
            throw new HttpFailure("The answer from {$host} had no status.");
        }

        return new HttpResponse($status, $answer, $answerHeaders);
    }
}
