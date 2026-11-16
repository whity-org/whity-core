<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Security;

use PHPUnit\Framework\TestCase;
use Whity\Core\Security\PublicToken;

/**
 * One way to make, hash and check a link token (merge map plumbing). The hash
 * is the SHA-256 every table already stored, so links made before still work.
 */
final class PublicTokenTest extends TestCase
{
    public function testATokenIs256BitsAndOnlyItsHashIsKept(): void
    {
        $token = PublicToken::make();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame(hash('sha256', $token), PublicToken::hash($token), 'the same hash the tables stored before');
        self::assertNotSame($token, PublicToken::make());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', PublicToken::makeUrlSafe());
    }

    public function testMatchingIsExactAndAnEmptyTokenNeverMatches(): void
    {
        $token = PublicToken::make();
        self::assertTrue(PublicToken::matches($token, PublicToken::hash($token)));
        self::assertFalse(PublicToken::matches($token . 'x', PublicToken::hash($token)));
        self::assertFalse(PublicToken::matches('', PublicToken::hash('')));
    }

    public function testAnExpiryInThePastHasExpiredAndNoneNeverDoes(): void
    {
        $now = new \DateTimeImmutable('2026-11-16 10:00:00');
        self::assertTrue(PublicToken::expired(new \DateTimeImmutable('2026-11-16 09:59:59'), $now));
        self::assertFalse(PublicToken::expired(new \DateTimeImmutable('2026-11-16 10:00:01'), $now));
        self::assertFalse(PublicToken::expired(null, $now));
    }
}
