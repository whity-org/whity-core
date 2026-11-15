<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

use Whity\Core\Http\HttpFetcher;

/**
 * The push client on PHP streams, no HTTP library (WHIT-1074).
 *
 * The endpoint was checked when it was stored (a known push service, https);
 * it is checked again here to resolve only to public addresses, with no
 * redirects and TLS verification on, the same guard {@see HttpFetcher} puts in
 * front of every URL the server fetches.
 */
final class StreamPushHttpClient implements PushHttpClient
{
    public function __construct(private readonly int $timeoutSeconds = 10)
    {
    }

    public function post(string $url, array $headers, string $body): int
    {
        if (!HttpFetcher::isPubliclyRoutableUrl($url)) {
            return 0;
        }
        $lines = [];
        foreach ($headers + ['Content-Length' => (string) strlen($body)] as $name => $value) {
            $lines[] = $name . ': ' . str_replace(["\r", "\n"], '', $value);
        }
        $context = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => implode("\r\n", $lines),
                'content'         => $body,
                'timeout'         => $this->timeoutSeconds,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'max_redirects'   => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $result = @file_get_contents($url, false, $context, 0, 65536);
        if ($result === false) {
            return 0;
        }
        // Filled by the stream wrapper on any completed response; with
        // ignore_errors a 4xx/5xx still completes, so its status is read too.
        $responseHeaders = $http_response_header;

        return self::status($responseHeaders);
    }

    /** @param list<string> $headers */
    private static function status(array $headers): int
    {
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }
}
