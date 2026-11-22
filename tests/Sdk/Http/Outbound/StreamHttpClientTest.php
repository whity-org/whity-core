<?php

declare(strict_types=1);

namespace Tests\Sdk\Http\Outbound;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\Outbound\AddressPolicy;
use Whity\Sdk\Http\Outbound\HttpFailure;
use Whity\Sdk\Http\Outbound\StreamHttpClient;

/**
 * The SDK's outbound client (SDK 1.114, WHIT-1058) against a real local server:
 * what it sends and returns, and what it refuses.
 */
final class StreamHttpClientTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $base = '';

    protected function setUp(): void
    {
        $id = 'fake-http-' . bin2hex(random_bytes(6));
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $port = $this->freePort();
            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__, 3) . '/Support/FakeHttpServer/router.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                array_merge(getenv(), ['FAKE_HTTP_ID' => $id, 'PHP_CLI_SERVER_WORKERS' => '4']),
            );
            self::assertIsResource($process);
            $this->server = $process;
            $this->base = 'http://127.0.0.1:' . $port;
            if ($this->serverIs($id)) {
                return;
            }
            $this->stop();
        }
        self::fail('The fake server did not come up as this test\'s own');
    }

    protected function tearDown(): void
    {
        $this->stop();
    }

    public function testSendsTheMethodHeadersAndBodyAndReturnsTheAnswer(): void
    {
        $client = new StreamHttpClient(allowHttp: true);

        $answer = $client->send('post', $this->base . '/echo', ['Content-Type' => 'application/json', 'X-Test' => 'bakery'], '{"loaves":12}');

        self::assertSame(200, $answer->status);
        self::assertTrue($answer->ok());
        self::assertSame(['method' => 'POST', 'content_type' => 'application/json', 'x_test' => 'bakery', 'body' => '{"loaves":12}'], $answer->json());
    }

    public function testAnErrorStatusIsAnAnswerNotAFailure(): void
    {
        $answer = (new StreamHttpClient(allowHttp: true))->send('GET', $this->base . '/missing', []);

        self::assertSame(404, $answer->status);
        self::assertFalse($answer->ok());
        self::assertSame(['error' => 'nope'], $answer->json());
    }

    public function testARedirectIsReturnedNotFollowed(): void
    {
        $answer = (new StreamHttpClient(allowHttp: true))->send('GET', $this->base . '/redirect', []);

        self::assertSame(302, $answer->status);
    }

    public function testAnAnswerOverTheCapFails(): void
    {
        $this->expectException(HttpFailure::class);
        $this->expectExceptionMessage('larger than 1024 bytes');

        (new StreamHttpClient(maxBytes: 1024, allowHttp: true))->send('GET', $this->base . '/big', []);
    }

    public function testASlowAnswerTimesOut(): void
    {
        $this->expectException(HttpFailure::class);

        (new StreamHttpClient(timeoutSeconds: 1, allowHttp: true))->send('GET', $this->base . '/slow', []);
    }

    public function testPlainHttpIsRefusedUnlessAskedFor(): void
    {
        try {
            (new StreamHttpClient())->send('GET', $this->base . '/echo', []);
            self::fail('http was allowed by default');
        } catch (HttpFailure $e) {
            self::assertStringContainsString('Only https', $e->getMessage());
        }
    }

    public function testCredentialsInTheUrlAndHeaderInjectionAreRefused(): void
    {
        $client = new StreamHttpClient(allowHttp: true);
        foreach ([
            fn () => $client->send('GET', 'https://user:sk_live_SECRET@api.example.com/v1', []),
            fn () => $client->send('GET', $this->base . '/echo', ["X-Test" => "a\r\nX-Injected: 1"]),
            fn () => $client->send('GET', $this->base . '/echo', ["Bad Name" => 'a']),
            fn () => $client->send('GET', 'ftp://files.example.com/x', []),
            fn () => $client->send('GET /x', $this->base . '/echo', []),
        ] as $i => $call) {
            try {
                $call();
                self::fail("case {$i} was sent");
            } catch (HttpFailure $e) {
                self::assertStringNotContainsString('SECRET', $e->getMessage());
            }
        }
    }

    public function testAFailureNamesTheHostAndNothingElse(): void
    {
        $closed = $this->freePort();
        try {
            (new StreamHttpClient(timeoutSeconds: 2, allowHttp: true))->send(
                'POST',
                "http://127.0.0.1:{$closed}/v1/charges?api_key=sk_live_SECRET",
                ['Authorization' => 'Bearer sk_live_SECRET'],
                'card=4242424242424242',
            );
            self::fail('a closed port answered');
        } catch (HttpFailure $e) {
            self::assertStringContainsString('127.0.0.1', $e->getMessage());
            foreach (['SECRET', '4242', '/v1/charges', 'api_key', 'Bearer'] as $leak) {
                self::assertStringNotContainsString($leak, $e->getMessage());
            }
        }
    }

    public function testTheAnswersHeadersComeBackByAnySpelling(): void
    {
        $answer = (new StreamHttpClient(allowHttp: true))->send('GET', $this->base . '/headers', []);

        self::assertSame(429, $answer->status);
        self::assertSame('30', $answer->header('Retry-After'));
        self::assertSame('30', $answer->header('retry-after'));
        self::assertSame('one, two', $answer->header('X-Twice'));
        self::assertNull($answer->header('X-Absent'));
        self::assertArrayHasKey('content-type', $answer->headers);
    }

    public function testARedirectsLocationIsThereForTheCallerToJudge(): void
    {
        $answer = (new StreamHttpClient(allowHttp: true))->send('GET', $this->base . '/redirect', []);

        self::assertSame(302, $answer->status);
        self::assertSame('/echo', $answer->header('Location'));
    }

    public function testThePolicyRefusesTheServersOwnNetwork(): void
    {
        $resolver = static fn (string $host): array => match ($host) {
            'intranet.example.com' => ['10.0.0.5'],
            'rebind.example.com' => ['93.184.216.34', '127.0.0.1'],
            default => [],
        };
        $client = new StreamHttpClient(allowHttp: true, addressPolicy: AddressPolicy::publicOnly($resolver));
        $port = parse_url($this->base, PHP_URL_PORT);

        foreach ([
            $this->base . '/echo',
            "http://localhost:{$port}/echo",
            'http://169.254.169.254/latest/meta-data/',
            'http://[::1]/x',
            'http://[::ffff:127.0.0.1]/x',
            'http://2130706433/x',
            'http://0x7f.1/x',
            'http://0177.0.0.1/x',
            'http://127.1/x',
            'https://intranet.example.com/x',
            'https://rebind.example.com/x',
            'https://nowhere.example.com/x',
            'https://bäckerei.example/x',
        ] as $url) {
            try {
                $client->send('GET', $url, []);
                self::fail("{$url} was allowed");
            } catch (HttpFailure $e) {
                self::assertStringNotContainsString('/x', $e->getMessage(), 'a refusal names the host only');
                self::assertStringNotContainsString('meta-data', $e->getMessage());
            }
        }
    }

    public function testThePolicyConnectsToTheAddressItCheckedAndKeepsTheHostName(): void
    {
        $port = (int) parse_url($this->base, PHP_URL_PORT);
        $lookups = 0;
        $resolver = static function (string $host) use (&$lookups): array {
            $lookups++;

            return $host === 'bakery.example.com' ? ['127.0.0.1'] : [];
        };
        $policy = AddressPolicy::publicOnly($resolver)->allowing('127.0.0.1/32');

        $answer = (new StreamHttpClient(allowHttp: true, addressPolicy: $policy))
            ->send('GET', "http://bakery.example.com:{$port}/host", ['Host' => 'evil.example.com']);

        self::assertSame(200, $answer->status);
        self::assertSame("bakery.example.com:{$port}", $answer->body);
        self::assertSame(1, $lookups, 'resolved once');
    }

    private function serverIs(string $id): bool
    {
        $context = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
        for ($i = 0; $i < 100; $i++) {
            $reply = @file_get_contents($this->base . '/__whoami', false, $context);
            if ($reply !== false) {
                return $reply === $id;
            }
            usleep(50_000);
        }

        return false;
    }

    private function stop(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
