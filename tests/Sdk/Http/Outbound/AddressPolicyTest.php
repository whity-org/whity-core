<?php

declare(strict_types=1);

namespace Tests\Sdk\Http\Outbound;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\Outbound\AddressPolicy;
use Whity\Sdk\Http\Outbound\HttpFailure;

/** Which addresses a person-supplied URL may reach (SDK 1.122, #2151). */
final class AddressPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'public v4' => ['93.184.216.34', true];
        yield 'public v6' => ['2606:4700::1111', true];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'this network' => ['0.0.0.0', false];
        yield 'private 10' => ['10.1.2.3', false];
        yield 'private 172' => ['172.16.0.1', false];
        yield 'private 192' => ['192.168.1.1', false];
        yield 'shared 100.64' => ['100.64.0.1', false];
        yield 'link-local metadata' => ['169.254.169.254', false];
        yield 'benchmark' => ['198.18.0.1', false];
        yield 'documentation' => ['192.0.2.1', false];
        yield 'multicast v4' => ['224.0.0.1', false];
        yield 'class E' => ['240.0.0.1', false];
        yield 'broadcast' => ['255.255.255.255', false];
        yield 'v6 loopback' => ['::1', false];
        yield 'v6 unique local' => ['fd00::1', false];
        yield 'v6 link-local' => ['fe80::1', false];
        yield 'v6 multicast' => ['ff02::1', false];
        yield 'v4-mapped private' => ['::ffff:10.0.0.1', false];
        yield 'v4-mapped public' => ['::ffff:93.184.216.34', true];
        yield 'nat64 loopback' => ['64:ff9b::7f00:1', false];
        yield 'not an address' => ['bakery', false];
    }

    #[DataProvider('addresses')]
    public function testPublicOnly(string $address, bool $permitted): void
    {
        self::assertSame($permitted, AddressPolicy::publicOnly(static fn (): array => [])->permits($address));
    }

    public function testExtraRangesCanBeAllowed(): void
    {
        $policy = AddressPolicy::publicOnly(static fn (): array => [])->allowing('10.20.0.0/16', 'fd00:1::/64', '192.168.5.9');

        self::assertTrue($policy->permits('10.20.255.1'));
        self::assertFalse($policy->permits('10.21.0.1'));
        self::assertTrue($policy->permits('fd00:1::42'));
        self::assertFalse($policy->permits('fd00:2::42'));
        self::assertTrue($policy->permits('192.168.5.9'));
        self::assertFalse($policy->permits('192.168.5.10'));
    }

    public function testABadRangeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AddressPolicy::publicOnly()->allowing('10.0.0.0/33');
    }

    public function testResolveAnswersTheFirstCheckedAddress(): void
    {
        $policy = AddressPolicy::publicOnly(static fn (string $host): array => ['93.184.216.34', '2606:4700::1111']);

        self::assertSame('93.184.216.34', $policy->resolve('parts.example.com'));
        self::assertSame('93.184.216.34', $policy->resolve('93.184.216.34'));
        self::assertSame('2606:4700::1111', $policy->resolve('[2606:4700::1111]'));
    }

    public function testOneBadAddressAmongGoodOnesRefusesTheHost(): void
    {
        $policy = AddressPolicy::publicOnly(static fn (string $host): array => ['93.184.216.34', '10.0.0.1']);

        $this->expectException(HttpFailure::class);
        $this->expectExceptionMessage('parts.example.com');
        $policy->resolve('parts.example.com');
    }
}
