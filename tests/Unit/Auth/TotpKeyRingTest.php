<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Defuse\Crypto\Crypto;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Whity\Auth\TotpService;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * Two-step sign-in secrets on the shared key ring.
 *
 * Secrets stored before the move (a bare ciphertext under ENCRYPTION_KEY) must
 * keep working with no re-enrolment, including after a rotation retires the key
 * they were written under.
 */
final class TotpKeyRingTest extends TestCase
{
    private const OLD_KEY = 'old-platform-key-0123456789abcdefghijkl';
    private const NEW_KEY = 'new-platform-key-0123456789abcdefghijkl';
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    /** A value exactly as the earlier TotpService stored it. */
    private function storedBeforeKeyRing(string $key): string
    {
        return Crypto::encryptWithPassword(self::SECRET, $key);
    }

    public function testNewSecretsAreWrittenWithTheCurrentKeyId(): void
    {
        $service = new TotpService(new EncryptedSecretStore(['k7' => self::NEW_KEY], 'k7'));

        $stored = $service->encryptSecret(self::SECRET);

        $this->assertStringStartsWith('k7:', $stored);
        $this->assertTrue($service->isOnCurrentKey($stored));
        $this->assertSame(self::SECRET, $service->decryptSecret($stored));
    }

    public function testSecretStoredBeforeTheKeyRingStillDecrypts(): void
    {
        $stored = $this->storedBeforeKeyRing(self::OLD_KEY);
        $service = new TotpService(self::OLD_KEY);

        $this->assertSame(self::SECRET, $service->decryptSecret($stored));
        $this->assertFalse($service->isOnCurrentKey($stored));

        $code = TOTP::create(self::SECRET)->now();
        $this->assertTrue($service->validateCode($stored, $code));
        $this->assertIsInt($service->matchedStep($stored, $code));
    }

    public function testSecretsSurviveARotationWithTheOldKeyRetired(): void
    {
        $before = new TotpService(new EncryptedSecretStore(['v1' => self::OLD_KEY], 'v1'));
        $onRing = $before->encryptSecret(self::SECRET);
        $bare = $this->storedBeforeKeyRing(self::OLD_KEY);

        $after = TotpService::fromEnv([
            'APP_ENV' => 'production',
            'ENCRYPTION_KEY' => self::NEW_KEY,
            'ENCRYPTION_KEY_ID' => 'v2',
            'ENCRYPTION_KEYS_RETIRED' => 'v1=' . self::OLD_KEY,
        ]);

        $this->assertSame(self::SECRET, $after->decryptSecret($onRing));
        $this->assertSame(self::SECRET, $after->decryptSecret($bare));

        $code = TOTP::create(self::SECRET)->now();
        $this->assertTrue($after->validateCode($onRing, $code));
        $this->assertTrue($after->validateCode($bare, $code));

        foreach ([$onRing, $bare] as $stored) {
            $rewrapped = $after->rewrapForCurrentKey($stored);
            $this->assertNotNull($rewrapped);
            $this->assertStringStartsWith('v2:', $rewrapped);
            $this->assertSame(self::SECRET, $after->decryptSecret($rewrapped));
            $this->assertNull($after->rewrapForCurrentKey($rewrapped));
        }
    }

    public function testRewrappedSecretNoLongerNeedsTheRetiredKey(): void
    {
        $bare = $this->storedBeforeKeyRing(self::OLD_KEY);
        $during = TotpService::fromEnv([
            'APP_ENV' => 'production',
            'ENCRYPTION_KEY' => self::NEW_KEY,
            'ENCRYPTION_KEY_ID' => 'v2',
            'ENCRYPTION_KEYS_RETIRED' => 'v1=' . self::OLD_KEY,
        ]);
        $rewrapped = (string) $during->rewrapForCurrentKey($bare);

        $afterRetirement = TotpService::fromEnv([
            'APP_ENV' => 'production',
            'ENCRYPTION_KEY' => self::NEW_KEY,
            'ENCRYPTION_KEY_ID' => 'v2',
        ]);

        $this->assertSame(self::SECRET, $afterRetirement->decryptSecret($rewrapped));
        $this->expectException(\RuntimeException::class);
        $afterRetirement->decryptSecret($bare);
    }

    public function testDevelopmentFallbackKeyStillWorks(): void
    {
        $service = TotpService::fromEnv(['APP_ENV' => 'development']);
        $bare = $this->storedBeforeKeyRing('dev_secret');

        $this->assertSame(self::SECRET, $service->decryptSecret($bare));
        $this->assertSame(self::SECRET, $service->decryptSecret($service->encryptSecret(self::SECRET)));
    }

    public function testFromEnvStillRefusesAMissingKeyOutsideDevelopment(): void
    {
        $this->expectException(\RuntimeException::class);
        TotpService::fromEnv(['APP_ENV' => 'production']);
    }
}
