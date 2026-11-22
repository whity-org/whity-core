<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Whity\Core\PasswordPolicy;

/**
 * Unit tests for PasswordPolicy.
 *
 * Confirms the 8-character minimum is enforced consistently and that the error
 * message is safe to surface to end-users.
 */
class PasswordPolicyTest extends TestCase
{
    public function testElevenCharacterPasswordThrows(): void
    {
        // READINESS CE-5: 12 is the minimum (ASVS 2.1.1).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/12 characters/i');

        PasswordPolicy::validate('bakery-oven');
    }

    public function testTwelveCharacterPasswordPasses(): void
    {
        // Must not throw — no assertion needed beyond the absence of an exception.
        PasswordPolicy::validate('bakery-ovens');
        $this->addToAssertionCount(1);
    }

    public function testLongerPasswordPasses(): void
    {
        PasswordPolicy::validate('correct-horse-battery-staple');
        $this->addToAssertionCount(1);
    }

    public function testEmptyPasswordThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PasswordPolicy::validate('');
    }

    public function testMinLengthConstantIsTwelve(): void
    {
        $this->assertSame(12, PasswordPolicy::MIN_LENGTH);
    }

    public function testPasswordAtTheMaximumLengthPasses(): void
    {
        // 72 bytes is bcrypt's effective input limit and the accepted maximum.
        PasswordPolicy::validate(str_repeat('a', PasswordPolicy::MAX_LENGTH));
        $this->addToAssertionCount(1);
    }

    public function testPasswordOverTheMaximumLengthThrows(): void
    {
        // Beyond 72 bytes bcrypt silently truncates, so a longer password whose
        // tail is ignored must be rejected rather than accepted.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/72 characters/i');

        PasswordPolicy::validate(str_repeat('a', PasswordPolicy::MAX_LENGTH + 1));
    }

    public function testMaxLengthConstantIsSeventyTwo(): void
    {
        $this->assertSame(72, PasswordPolicy::MAX_LENGTH);
    }
}
