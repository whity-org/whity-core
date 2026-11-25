<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Identity;

use PHPUnit\Framework\TestCase;
use Whity\Core\Identity\BreachedPasswords;
use Whity\Core\PasswordPolicy;

/**
 * New passwords that were in a breach, or are too common, are refused
 * (READINESS CE-7), and only five hex characters of the hash leave the server.
 */
final class BreachedPasswordsTest extends TestCase
{
    protected function tearDown(): void
    {
        PasswordPolicy::setBreachedPasswords(null);
    }

    public function testTheBuiltInListAndRepeatsAreRefusedOffline(): void
    {
        $check = new BreachedPasswords();

        self::assertTrue($check->isBreached('Password1234'));
        self::assertTrue($check->isBreached('aaaaaaaaaaaaaaa'));
        self::assertTrue($check->isBreached('abababababab'));
        self::assertFalse($check->isBreached('bakery-oven-at-dawn'));
    }

    public function testOnlyTheHashPrefixIsSentAndTheSuffixIsMatchedHere(): void
    {
        $password = 'bakery-oven-at-dawn';
        $hash = strtoupper(sha1($password));
        $asked = [];
        $check = new BreachedPasswords(static function (string $url) use (&$asked, $hash): string {
            $asked[] = $url;

            return "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n" . substr($hash, 5) . ":42\r\n00D4F6E8FA6EECAD2A3AA415EEC418D38EC:0";
        });

        self::assertTrue($check->isBreached($password));
        self::assertSame([BreachedPasswords::RANGE_URL . substr($hash, 0, 5)], $asked);
        self::assertStringNotContainsString(substr($hash, 5), $asked[0]);
    }

    public function testAPaddingLineWithCountZeroIsNotABreach(): void
    {
        $password = 'bakery-oven-at-dawn';
        $check = new BreachedPasswords(static fn (string $url): string => substr(strtoupper(sha1($password)), 5) . ':0');

        self::assertFalse($check->isBreached($password));
    }

    public function testAnOutageFailsOpenToTheBuiltInList(): void
    {
        $check = new BreachedPasswords(static fn (string $url): ?string => null);
        self::assertFalse($check->isBreached('bakery-oven-at-dawn'));
        self::assertTrue($check->isBreached('password1234'));

        $throws = new BreachedPasswords(static function (string $url): string {
            throw new \RuntimeException('network down');
        });
        self::assertFalse($throws->isBreached('bakery-oven-at-dawn'));
    }

    public function testThePolicyRefusesABreachedPasswordWhenSetAndAShortOneAlways(): void
    {
        PasswordPolicy::setBreachedPasswords(new BreachedPasswords());

        try {
            PasswordPolicy::validate('password1234');
            self::fail('a common password was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(PasswordPolicy::BREACHED_MESSAGE, $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        PasswordPolicy::validate('elevenchars');
    }
}
