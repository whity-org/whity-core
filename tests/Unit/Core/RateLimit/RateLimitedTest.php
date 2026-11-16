<?php

declare(strict_types=1);

namespace Tests\Unit\Core\RateLimit;

use PHPUnit\Framework\TestCase;
use Whity\Core\RateLimit\RateLimited;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\RateLimit\RateLimits;
use Whity\Core\Store\ArraySharedStore;
use Whity\Http\Middleware\PublicFormGate;
use Whity\Sdk\Http\Response;

/**
 * The one over-the-limit answer and the keyed rule's four calls (merge map
 * plumbing, WHIT-1158).
 */
final class RateLimitedTest extends TestCase
{
    public function testTheSentenceSaysAMinuteMinutesOrHours(): void
    {
        self::assertSame('Too many attempts. Try again in a minute.', RateLimited::sentence(1));
        self::assertSame('Too many attempts. Try again in a minute.', RateLimited::sentence(60));
        self::assertSame('Too many attempts. Try again in 2 minutes.', RateLimited::sentence(61));
        self::assertSame('Too many attempts. Try again in 15 minutes.', RateLimited::sentence(900));
        self::assertSame('Too many attempts. Try again in 90 minutes.', RateLimited::sentence(5400));
        self::assertSame('Too many attempts. Try again in 2 hours.', RateLimited::sentence(5401));
        self::assertSame('Too many attempts. Try again in 24 hours.', RateLimited::sentence(86400));
    }

    public function testTheResponseIs429WithRetryAfterAndTheCode(): void
    {
        $response = RateLimited::response(300);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('300', self::header($response, 'Retry-After'));
        $body = self::body($response);
        self::assertSame('Too many attempts. Try again in 5 minutes.', $body['error']);
        self::assertSame(['code' => 'rate_limited', 'retry_after' => 300], $body['details']);
    }

    public function testAWaitOfNothingStillSaysOneSecond(): void
    {
        $response = RateLimited::response(0);

        self::assertSame('1', self::header($response, 'Retry-After'));
        self::assertSame(1, self::body($response)['details']['retry_after']);
    }

    public function testTakeCountsUpToTheLimitThenRefusesWithoutCounting(): void
    {
        $store = new ArraySharedStore();
        $rule = RateLimitRule::keyed('test.take', 3, 3600);

        for ($i = 0; $i < 3; $i++) {
            self::assertNull($rule->take($store, 'k'));
        }
        $refused = $rule->take($store, 'k');

        self::assertNotNull($refused);
        self::assertSame(429, $refused->getStatusCode());
        self::assertSame(RateLimited::CODE, self::body($refused)['details']['code']);
        self::assertSame(3, $store->count('k'), 'a refused take counts nothing');
    }

    public function testHitCountsFirstThenRefusesOverTheLimit(): void
    {
        $store = new ArraySharedStore();
        $rule = RateLimitRule::keyed('test.hit', 2, 3600);

        self::assertNull($rule->hit($store, 'k'));
        self::assertNull($rule->hit($store, 'k'));
        $refused = $rule->hit($store, 'k');

        self::assertNotNull($refused);
        self::assertSame(429, $refused->getStatusCode());
        self::assertSame(3, $store->count('k'), 'a hit counts even when it is refused');
    }

    public function testBlockedCountsNothingAndRecordCountsForTheWindow(): void
    {
        $store = new ArraySharedStore();
        $rule = RateLimitRule::keyed('test.blocked', 2, 600);

        self::assertNull($rule->blocked($store, 'k'));
        self::assertSame(0, $store->count('k'));

        self::assertSame(1, $rule->record($store, 'k'));
        self::assertSame(2, $rule->record($store, 'k'));
        self::assertGreaterThan(0, $store->ttl('k'));
        self::assertLessThanOrEqual(600, $store->ttl('k'));

        $refused = $rule->blocked($store, 'k');
        self::assertNotNull($refused);
        self::assertSame(2, $store->count('k'));
        self::assertSame((string) $store->ttl('k'), self::header($refused, 'Retry-After'));
    }

    public function testTheKeysAreSeparate(): void
    {
        $store = new ArraySharedStore();
        $rule = RateLimitRule::keyed('test.keys', 1, 3600);

        self::assertNull($rule->take($store, 'Example Bakery'));
        self::assertNotNull($rule->take($store, 'Example Bakery'));
        self::assertNull($rule->take($store, 'Whity team'));
    }

    /** The gate's public constants are what its tests fill a bucket with, so they must say the catalogue's numbers. */
    public function testThePublicFormGateConstantsMatchTheCatalogue(): void
    {
        self::assertSame(PublicFormGate::IP_MAX, RateLimits::pluginFormPerAddress()->limit);
        self::assertSame(PublicFormGate::FORM_MAX, RateLimits::pluginFormPerForm()->limit);
        self::assertSame(PublicFormGate::READ_IP_MAX, RateLimits::pluginFormReadPerAddress()->limit);
        self::assertSame(PublicFormGate::READ_ROUTE_IP_MAX, RateLimits::pluginReadPerAddress()->limit);
        self::assertSame(PublicFormGate::READ_ROUTE_MAX, RateLimits::pluginReadPerRead()->limit);
        foreach ([
            RateLimits::pluginFormPerAddress(),
            RateLimits::pluginFormPerForm(),
            RateLimits::pluginFormReadPerAddress(),
            RateLimits::pluginReadPerAddress(),
            RateLimits::pluginReadPerRead(),
        ] as $rule) {
            self::assertSame(PublicFormGate::WINDOW_SECONDS, $rule->window, $rule->name);
        }
    }

    /** Every catalogue entry has its own name, a limit and a window. */
    public function testTheCatalogueNamesAreUnique(): void
    {
        $names = [];
        foreach ((new \ReflectionClass(RateLimits::class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
            $rule = $method->invoke(null);
            self::assertInstanceOf(RateLimitRule::class, $rule);
            self::assertGreaterThan(0, $rule->limit, $rule->name);
            self::assertGreaterThan(0, $rule->window, $rule->name);
            $names[] = $rule->name;
        }

        self::assertNotEmpty($names);
        self::assertSame(array_unique($names), $names);
    }

    private static function header(Response $response, string $name): ?string
    {
        foreach ($response->getHeaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }
}
