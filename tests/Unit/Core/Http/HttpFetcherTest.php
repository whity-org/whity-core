<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Http;

use PHPUnit\Framework\TestCase;
use Whity\Core\Http\HttpFetcher;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the SSRF guard on {@see HttpFetcher} (WC-ae16). Uses IP-literal
 * and loopback hosts so the checks are deterministic and touch no network.
 */
final class HttpFetcherTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            ['http://8.8.8.8/x'],                         // not https
            ['ftp://8.8.8.8/x'],                          // not https
            ['https://127.0.0.1/x'],                      // loopback
            ['https://10.0.0.5/x'],                       // private (10/8)
            ['https://192.168.1.10/x'],                   // private (192.168/16)
            ['https://172.16.5.5/x'],                     // private (172.16/12)
            ['https://169.254.169.254/latest/meta-data'], // cloud-metadata link-local
            ['https://0.0.0.0/x'],                         // reserved
            ['https://[::1]/x'],                           // IPv6 loopback
            // WHIT-1248: ranges the old NO_PRIV|NO_RES pair let through.
            ['https://100.100.100.200/x'],                 // carrier-grade NAT (a cloud metadata address)
            ['https://100.64.0.1/x'],                      // carrier-grade NAT
            ['https://198.18.0.1/x'],                      // benchmarking
            ['https://192.0.0.8/x'],                       // IETF protocol assignments
            ['https://[::ffff:127.0.0.1]/x'],              // IPv4-mapped loopback
            ['https://[::ffff:169.254.169.254]/x'],        // IPv4-mapped metadata
            ['https://[64:ff9b::a9fe:a9fe]/x'],            // NAT64 of 169.254.169.254
            ['https://[fe80::1]/x'],                       // IPv6 link-local
            ['https://[fc00::1]/x'],                       // IPv6 unique local
            // WHIT-1284 (#2214): credentials in the authority, multicast.
            ['https://user:pass@8.8.8.8/x'],
            ['https://user@8.8.8.8/x'],
            ['https://8.8.8.8@127.0.0.1/x'],
            ['https://224.0.0.1/x'],                       // IPv4 multicast
            ['https://[ff02::1]/x'],                       // IPv6 multicast
            ['https://[64:ff9b::808:808]/x'],              // NAT64, even of a public address
            ['https://localhost/x'],                       // resolves to loopback
            ['https:///no-host'],                          // no host
            ['not-a-url'],                                 // unparseable
        ];
    }

    #[DataProvider('blockedUrls')]
    public function testBlocksNonPublicOrNonHttpsUrls(string $url): void
    {
        self::assertFalse(HttpFetcher::isPubliclyRoutableUrl($url), "must block: {$url}");
    }

    /**
     * @return list<array{0: string}>
     */
    public static function allowedUrls(): array
    {
        return [
            ['https://8.8.8.8/x'],           // public IPv4 literal
            ['https://1.1.1.1/'],            // public IPv4 literal
            ['https://[2001:4860:4860::8888]/x'], // public IPv6 literal (Google DNS)
            ['HTTPS://8.8.8.8/x'],           // the scheme is not case-sensitive
        ];
    }

    #[DataProvider('allowedUrls')]
    public function testAllowsPubliclyRoutableHttpsUrls(string $url): void
    {
        self::assertTrue(HttpFetcher::isPubliclyRoutableUrl($url), "must allow: {$url}");
    }

    public function testFetchThrowsOnABlockedUrl(): void
    {
        // getJson runs the guard before any I/O, so a blocked URL throws rather
        // than attempting a request.
        $this->expectException(\RuntimeException::class);
        (new HttpFetcher())->getJson('https://169.254.169.254/latest/meta-data');
    }

    /**
     * WHIT-1303 (#2188): a host whose DNS answers the check with a public
     * address and a later lookup with the metadata address is resolved once,
     * and the request goes to the address that was checked.
     */
    public function testTheRequestConnectsToTheCheckedAddressNotASecondLookup(): void
    {
        $lookups = 0;
        $resolver = static function (string $host) use (&$lookups): array {
            ++$lookups;

            return $lookups === 1 ? ['93.184.215.14'] : ['169.254.169.254'];
        };
        $sent = [];
        $transport = static function (string $url, array $options) use (&$sent): array {
            $sent[] = ['url' => $url, 'options' => $options];

            return ['{"ok":true}', ['HTTP/1.1 200 OK']];
        };
        $fetcher = new HttpFetcher(resolver: $resolver, transport: $transport);

        self::assertSame(['ok' => true], $fetcher->getJson('https://rebind.example.com:8443/.well-known/openid-configuration', ['Host' => 'evil.internal']));
        self::assertSame(1, $lookups);
        self::assertSame('https://93.184.215.14:8443/.well-known/openid-configuration', $sent[0]['url']);
        $header = (string) $sent[0]['options']['http']['header'];
        self::assertStringContainsString("Host: rebind.example.com:8443\r\n", $header);
        self::assertStringNotContainsString('evil.internal', $header, "a caller's Host never replaces the checked one");
        self::assertSame('rebind.example.com', $sent[0]['options']['ssl']['peer_name']);
        self::assertTrue($sent[0]['options']['ssl']['verify_peer_name']);

        // The next request resolves again, gets the metadata address, and is refused.
        try {
            $fetcher->getBinary('https://rebind.example.com/pkg.zip');
            self::fail('the rebound answer must be refused');
        } catch (\RuntimeException) {
        }
        self::assertSame(2, $lookups, 'each request resolves once');
        self::assertCount(1, $sent, 'nothing was sent to the rebound address');
    }

    public function testAHostWithAnyNonPublicAddressIsRefusedBeforeAnyRequest(): void
    {
        $sent = 0;
        $transport = static function () use (&$sent): array {
            ++$sent;

            return ['{}', ['HTTP/1.1 200 OK']];
        };
        foreach ([['93.184.215.14', '10.0.0.5'], ['169.254.169.254'], ['64:ff9b::808:808'], []] as $answer) {
            $fetcher = new HttpFetcher(resolver: static fn (): array => $answer, transport: $transport);
            try {
                $fetcher->postForm('https://mixed.example.com/token', ['a' => 'b']);
                self::fail('must refuse ' . implode(',', $answer));
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('refused', $e->getMessage());
            }
        }
        self::assertSame(0, $sent);
    }

    public function testAnIpv6AnswerIsBracketedInThePinnedUrl(): void
    {
        $sent = [];
        $fetcher = new HttpFetcher(
            resolver: static fn (): array => ['2001:4860:4860::8888'],
            transport: static function (string $url) use (&$sent): array {
                $sent[] = $url;

                return ['{}', ['HTTP/1.1 200 OK']];
            },
        );
        $fetcher->getJson('https://v6.example.com/x?y=1');
        self::assertSame(['https://[2001:4860:4860::8888]/x?y=1'], $sent);
    }
}
