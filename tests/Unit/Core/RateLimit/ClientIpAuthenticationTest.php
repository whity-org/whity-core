<?php

declare(strict_types=1);

namespace Tests\Unit\Core\RateLimit;

use PHPUnit\Framework\TestCase;
use Whity\Core\RateLimit\ClientIp;
use Whity\Sdk\Http\Request;

/**
 * #2386, READINESS CE-4 / TM-19: X-Whity-Client-Ip is believed only from a
 * caller that proves it is the proxy, by signature or by address.
 */
final class ClientIpAuthenticationTest extends TestCase
{
    private const SECRET = 'a-shared-secret-that-is-long-enough-123';
    private const NOW = 1_800_000_000;

    /** @param array<string, string> $headers */
    private function request(array $headers): Request
    {
        return new Request('POST', '/api/v1/auth/login', $headers, '');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $env
     */
    private function resolved(array $headers, string $remote, array $env): ?string
    {
        $request = $this->request($headers);
        ClientIp::authenticate($request, ['REMOTE_ADDR' => $remote], $env, self::NOW);

        return ClientIp::fromRequest($request);
    }

    public function testUnconfiguredKeepsTheOldBehaviour(): void
    {
        self::assertSame('203.0.113.7', $this->resolved(['X-Whity-Client-Ip' => '203.0.113.7'], '198.51.100.9', []));
        self::assertNull($this->resolved([], '198.51.100.9', []));
    }

    public function testAProxyAddressIsBelieved(): void
    {
        $env = [ClientIp::PROXIES_ENV => '10.0.0.0/8, 172.16.0.0/12'];

        self::assertSame('203.0.113.7', $this->resolved(['X-Whity-Client-Ip' => '203.0.113.7'], '172.18.0.5', $env));
    }

    public function testAnyOtherCallerIsCountedAsItself(): void
    {
        $env = [ClientIp::PROXIES_ENV => '10.0.0.0/8'];

        // A caller outside the range who sets the header gets its own address, so rotating the header changes nothing.
        self::assertSame('198.51.100.9', $this->resolved(['X-Whity-Client-Ip' => '1.2.3.4'], '198.51.100.9', $env));
        self::assertSame('198.51.100.9', $this->resolved(['X-Whity-Client-Ip' => '5.6.7.8'], '198.51.100.9', $env));
        // No header: the peer.
        self::assertSame('198.51.100.9', $this->resolved([], '198.51.100.9', $env));
    }

    public function testAValidSignatureIsBelieved(): void
    {
        $env = [ClientIp::SECRET_ENV => self::SECRET];
        $headers = [
            'X-Whity-Client-Ip' => '203.0.113.7',
            'X-Whity-Client-Ip-Signature' => ClientIp::sign('203.0.113.7', self::SECRET, self::NOW - 10),
        ];

        self::assertSame('203.0.113.7', $this->resolved($headers, '172.18.0.4', $env));
    }

    public function testAWrongStaleOrMovedSignatureIsNot(): void
    {
        $env = [ClientIp::SECRET_ENV => self::SECRET];
        $cases = [
            'other secret' => ClientIp::sign('203.0.113.7', str_repeat('x', 40), self::NOW),
            'too old' => ClientIp::sign('203.0.113.7', self::SECRET, self::NOW - ClientIp::MAX_SKEW_SECONDS - 1),
            'other ip' => ClientIp::sign('203.0.113.8', self::SECRET, self::NOW),
            'garbage' => 'v1=abc',
        ];
        foreach ($cases as $name => $signature) {
            $headers = ['X-Whity-Client-Ip' => '203.0.113.7', 'X-Whity-Client-Ip-Signature' => $signature];
            self::assertSame('172.18.0.4', $this->resolved($headers, '172.18.0.4', $env), $name);
        }
        self::assertSame('172.18.0.4', $this->resolved(['X-Whity-Client-Ip' => '203.0.113.7'], '172.18.0.4', $env), 'unsigned');
    }

    public function testAShortSecretCountsAsUnset(): void
    {
        $env = [ClientIp::SECRET_ENV => 'short'];

        // Unset means the old behaviour, not "everything is refused".
        self::assertSame('203.0.113.7', $this->resolved(['X-Whity-Client-Ip' => '203.0.113.7'], '198.51.100.9', $env));
    }

    public function testRangesParseAndMatchBothFamilies(): void
    {
        $ranges = ClientIp::parseProxies('10.1.2.3, 192.168.0.0/16, fd00::/8, not-an-ip, 10.0.0.0/99');

        self::assertCount(3, $ranges);
        self::assertTrue(ClientIp::inAny('10.1.2.3', $ranges));
        self::assertFalse(ClientIp::inAny('10.1.2.4', $ranges));
        self::assertTrue(ClientIp::inAny('192.168.200.1', $ranges));
        self::assertTrue(ClientIp::inAny('fd12::1', $ranges));
        self::assertFalse(ClientIp::inAny('fe80::1', $ranges));
        self::assertFalse(ClientIp::inAny('garbage', $ranges));
        self::assertTrue(ClientIp::inAny('172.20.0.9', ClientIp::parseProxies('172.16.0.0/12')));
        self::assertFalse(ClientIp::inAny('172.32.0.1', ClientIp::parseProxies('172.16.0.0/12')));
    }

    public function testFromRequestReadsTheHeaderBeforeAuthentication(): void
    {
        self::assertSame('203.0.113.7', ClientIp::fromRequest($this->request(['X-Whity-Client-Ip' => '203.0.113.7'])));
    }
}
