<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Http\RequestHost;
use Whity\Core\Request;

/** WHIT-1294 (#2195): the one rule for the host a request was made to. */
final class RequestHostTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('cases')]
    public function testHost(array $headers, string $expected): void
    {
        self::assertSame($expected, RequestHost::fromRequest(new Request('GET', '/', $headers, '')));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function cases(): iterable
    {
        yield 'forwarded host wins' => [['Host' => 'internal:8080', 'X-Forwarded-Host' => 'Bakery.Whity.Test'], 'bakery.whity.test'];
        yield 'forwarded host with port' => [['Host' => 'internal', 'X-Forwarded-Host' => 'app.example.com:8443'], 'app.example.com:8443'];
        yield 'ipv6 literal' => [['Host' => 'internal', 'X-Forwarded-Host' => '[::1]:3000'], '[::1]:3000'];
        yield 'host when none forwarded' => [['Host' => 'acme.whity.test'], 'acme.whity.test'];
        yield 'list falls back to host' => [['Host' => 'other.whity.test', 'X-Forwarded-Host' => 'localhost, other.whity.test'], 'other.whity.test'];
        yield 'path falls back to host' => [['Host' => 'other.whity.test', 'X-Forwarded-Host' => 'evil.test/x'], 'other.whity.test'];
        yield 'scheme falls back to host' => [['Host' => 'other.whity.test', 'X-Forwarded-Host' => 'https://evil.test'], 'other.whity.test'];
        yield 'empty forwarded falls back' => [['Host' => 'other.whity.test', 'X-Forwarded-Host' => '  '], 'other.whity.test'];
        yield 'nothing usable' => [['Host' => 'bad host'], ''];
        yield 'no headers' => [[], ''];
    }
}
