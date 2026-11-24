<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plugin\Trust\PluginTrust;
use Whity\Core\Plugin\Trust\TrustedKeys;

/**
 * Whity Desktop verifies the same signed documents as core, in Rust
 * (whity-org/whity-desktop, crates/plugins/src/trust.rs). The fixture
 * `fixtures/whity-desktop-attestation-vector.json` is a byte-for-byte COPY of
 * that repository's `crates/plugins/tests/fixtures/attestation-vector.json`:
 * one attestation, one revoked attestation and one revocation list, signed once
 * by the desktop's test signer (a fixed-seed Ed25519 key that signs nothing
 * else). The desktop's tests expect the same answers from the same bytes, so a
 * change to the envelope format on either side fails the other's CI. Change
 * the fixture only by regenerating it on the desktop side and copying it here.
 *
 * The key in the fixture is a test key. It is pinned here for this test only;
 * {@see TrustedKeys::pinned()} stays empty until the owner supplies a key.
 */
final class PluginTrustDesktopVectorTest extends TestCase
{
    private PDO $pdo;

    /** @var array<string, mixed> */
    private array $vector;

    private PluginTrust $trust;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $json = (string) file_get_contents(__DIR__ . '/fixtures/whity-desktop-attestation-vector.json');
        /** @var array<string, mixed> $vector */
        $vector = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $this->vector = $vector;
        $this->trust = $this->trustWith([(string) $vector['publicKey']]);

        self::assertTrue($this->trust->applyRevocationList((string) $vector['revocations'])['ok'], 'the desktop-signed revocation list is accepted');
    }

    public function testTheDesktopSignedAttestationVerifiesHere(): void
    {
        $expect = $this->vector['expect'];

        $check = $this->trust->checkAttestation(
            (string) $this->vector['attestation'],
            (string) $expect['plugin'],
            (string) $expect['version'],
            (string) $expect['sha256'],
        );

        self::assertSame(['ok' => true, 'reason' => (string) $expect['reason']], $check);
    }

    public function testTheRevokedOneIsRevokedAndTheOthersFailForTheSameReasons(): void
    {
        $expect = $this->vector['expect'];
        $revoked = $this->vector['expectRevoked'];

        self::assertSame(
            (string) $revoked['reason'],
            $this->trust->checkAttestation((string) $this->vector['revokedAttestation'], (string) $revoked['plugin'], (string) $revoked['version'], (string) $expect['sha256'])['reason'],
        );
        self::assertSame(
            'mismatch',
            $this->trust->checkAttestation((string) $this->vector['attestation'], (string) $expect['plugin'], '9.9.9', (string) $expect['sha256'])['reason'],
        );

        $otherKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $withOtherKey = $this->trustWith([$otherKey]);
        self::assertSame(
            'bad_signature',
            $withOtherKey->checkAttestation((string) $this->vector['attestation'], (string) $expect['plugin'], (string) $expect['version'], (string) $expect['sha256'])['reason'],
        );
    }

    public function testTheFixtureIsTheDesktopsOwnShape(): void
    {
        foreach (['publicKey', 'now', 'attestation', 'revokedAttestation', 'revocations', 'expect', 'expectRevoked'] as $field) {
            self::assertArrayHasKey($field, $this->vector);
        }
        self::assertStringContainsString('crates/plugins/src/trust.rs', (string) $this->vector['$comment'], 'the comment names the desktop verifier it was made for');
    }

    /** @param list<string> $keys */
    private function trustWith(array $keys): PluginTrust
    {
        $now = (int) $this->vector['now'];

        return new PluginTrust($this->pdo, new TrustedKeys($keys), null, static fn (): int => $now);
    }
}
