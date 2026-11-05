<?php

declare(strict_types=1);

namespace Tests\Support\FakeOAuth;

use Whity\Core\Http\HttpClient;

/**
 * Carries a token request to the local fake over real HTTP (WHIT-901).
 *
 * The only stand-in in the chain, needed because the production client rightly
 * refuses plain HTTP and loopback. It accepts the real endpoints and nothing else,
 * so a request for any other address fails the test instead of reaching the fake.
 */
final class LoopbackTokenHttp implements HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function getJson(string $url): ?array
    {
        throw new \LogicException('The token flow never issues a GET');
    }

    public function postForm(string $url, array $params): ?array
    {
        $this->urls[] = $url;

        $path = match (true) {
            $url === 'https://oauth2.googleapis.com/token' => '/google/token',
            preg_match('#^https://login\.microsoftonline\.com/([^/]+)/oauth2/v2\.0/token$#D', $url, $m) === 1 => '/microsoft/' . $m[1] . '/token',
            default => throw new \RuntimeException('Refused a token endpoint outside the fixed list: ' . $url),
        };

        $raw = @file_get_contents($this->baseUrl . $path, false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($params),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]));

        $decoded = $raw === false ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
