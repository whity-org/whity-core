<?php

declare(strict_types=1);

namespace Whity\Sdk\Testing;

use Whity\Sdk\Http\Outbound\HttpClient;
use Whity\Sdk\Http\Outbound\HttpFailure;
use Whity\Sdk\Http\Outbound\HttpResponse;

/**
 * Recorded answers for code that takes {@see HttpClient} (SDK 1.114).
 *
 * Script the answers by method and URL prefix, run the code, then assert on
 * exactly what was sent. Nothing opens a socket. From Whity Pay's tests, where
 * every provider driver is tested this way (WHIT-1058).
 *
 *     $http = (new RecordedHttpClient())
 *         ->on('POST', 'https://api.stripe.com/v1/checkout/sessions', ['id' => 'cs_1', 'url' => 'https://…'])
 *         ->failOn('GET', 'https://api.stripe.com/v1/events');
 */
final class RecordedHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $sent = [];

    /** @var list<array{method: string, prefix: string, response: HttpResponse|HttpFailure}> */
    private array $script = [];

    /** @param array<mixed>|string $body A string is sent as is; an array as JSON. */
    public function on(string $method, string $urlPrefix, array|string $body, int $status = 200): self
    {
        $this->script[] = [
            'method' => strtoupper($method),
            'prefix' => $urlPrefix,
            'response' => new HttpResponse($status, is_string($body) ? $body : (string) json_encode($body)),
        ];

        return $this;
    }

    /** The request gets no answer at all (timeout, connection refused). */
    public function failOn(string $method, string $urlPrefix): self
    {
        $this->script[] = ['method' => strtoupper($method), 'prefix' => $urlPrefix, 'response' => new HttpFailure('No answer (recorded).')];

        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body = null): HttpResponse
    {
        $method = strtoupper($method);
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        // The LAST matching entry wins, so a test can override a default.
        foreach (array_reverse($this->script) as $entry) {
            if ($entry['method'] === $method && str_starts_with($url, $entry['prefix'])) {
                if ($entry['response'] instanceof HttpFailure) {
                    throw $entry['response'];
                }

                return $entry['response'];
            }
        }

        return new HttpResponse(404, '{"error":"not recorded"}');
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public function to(string $method, string $urlPrefix): array
    {
        $method = strtoupper($method);

        return array_values(array_filter(
            $this->sent,
            static fn (array $r): bool => $r['method'] === $method && str_starts_with($r['url'], $urlPrefix)
        ));
    }

    /**
     * The decoded JSON or form body of the last request to a URL.
     *
     * @return array<mixed>
     */
    public function lastBody(string $method, string $urlPrefix): array
    {
        $requests = $this->to($method, $urlPrefix);
        $last = end($requests);
        if ($last === false || $last['body'] === null) {
            return [];
        }
        $json = json_decode($last['body'], true);
        if (is_array($json)) {
            return $json;
        }
        parse_str($last['body'], $form);

        return $form;
    }
}
