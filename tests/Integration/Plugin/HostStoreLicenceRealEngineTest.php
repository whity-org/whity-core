<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plugin\Store\HostStoreLicence;
use Whity\Core\Plugin\Store\WhityStoreConnector;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Sdk\Licensing\LicenceState;

/**
 * Store licensing (PR-4, SDK 1.85): the host's licence answer, from the store's signed statement,
 * with the offline window. Real app_settings; the store and Whity ID are fakes.
 */
final class HostStoreLicenceRealEngineTest extends TestCase
{
    private const KEY = 'unit-test-encryption-key-please-ignore-0123456789';
    private const STORE_HOST = 'store.whity.test';
    private const CLIENT = 'wid_dyn_bakery';

    private GlobalSettingsRepository $globals;
    private EncryptedSecretStore $secrets;
    private int $now = 2_000_000_000;
    /** @var non-empty-string */
    private string $seed;

    /** @var array<string, mixed>|null what the store says next; null = unreachable */
    private ?array $statementClaims = null;

    private int $storeCalls = 0;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->globals = new GlobalSettingsRepository($pdo);
        $this->secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        /** @var non-empty-string $seed */
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $this->seed = $seed;
    }

    /** A connector already connected to the store, with a cached access token. */
    private function connector(): WhityStoreConnector
    {
        $this->globals->set(WhityStoreConnector::CONNECTION_KEY, (string) json_encode([
            'store_host' => self::STORE_HOST,
            'issuer' => 'https://id.whity.test',
            'client_id' => self::CLIENT,
            'resource' => 'https://' . self::STORE_HOST . '/api/v1/plugin-store',
            'token_endpoint' => 'https://id.whity.test/oauth/token',
            'revocation_endpoint' => null,
            'account' => ['sub' => 's', 'name' => 'Rania', 'email' => 'rania@example-bakery.test'],
            'connected_at' => $this->now,
            'connected_by' => 1,
            'needs_reconnect' => false,
        ]));
        $this->globals->set(WhityStoreConnector::REFRESH_TOKEN_KEY, $this->secrets->encrypt('refresh'));
        $this->globals->set(WhityStoreConnector::ACCESS_TOKEN_KEY, $this->secrets->encrypt((string) json_encode([
            'token' => 'access', 'expires_at' => $this->now + 10 * 86400, 'client_id' => self::CLIENT,
        ])));

        return new WhityStoreConnector(
            $this->globals,
            $this->secrets,
            ['https://id.whity.test'],
            'https://bakery.example.test/admin/plugins/store/connect',
            'Whity (bakery)',
            static fn (): ?array => null,
            static fn (): ?array => null,
            static fn (): ?array => null,
            fn (): int => $this->now,
        );
    }

    private function licence(bool $selfLicensed = false): HostStoreLicence
    {
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($this->seed));

        return new HostStoreLicence(
            $this->globals,
            $this->connector(),
            function (string $url, array $headers = []): ?array {
                $this->storeCalls++;
                if ($this->statementClaims === null) {
                    return null;
                }
                if (str_ends_with($url, '/licences/whity-pay')) {
                    return ['data' => ['licence' => $this->sign($this->statementClaims)]];
                }

                return null;
            },
            [self::STORE_HOST => [self::b64u($public)]],
            7,
            $selfLicensed,
            fn (): int => $this->now,
        );
    }

    /**
     * @param array<string, mixed> $claims
     * @param non-empty-string|null $seed
     */
    private function sign(array $claims, ?string $seed = null): string
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed ?? $this->seed));
        $input = self::b64u((string) json_encode(['alg' => 'EdDSA', 'typ' => 'whity-licence+jwt', 'kid' => 'k']))
            . '.' . self::b64u((string) json_encode($claims));

        return $input . '.' . self::b64u(sodium_crypto_sign_detached($input, $secret));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function claims(string $state, array $extra = []): array
    {
        return array_merge([
            'iss' => 'https://' . self::STORE_HOST . '/api/v1/plugin-store',
            'aud' => self::CLIENT,
            'plugin' => 'whity-pay',
            'state' => $state,
            'valid_until' => gmdate('c', $this->now + 30 * 86400),
            'grace_until' => gmdate('c', $this->now + 37 * 86400),
            'iat' => $this->now,
            'exp' => $this->now + 86400,
        ], $extra);
    }

    public function testALicensedStatementIsLicensedAndCached(): void
    {
        $this->statementClaims = $this->claims('licensed');
        $licence = $this->licence();

        $state = $licence->status('whity-pay');
        self::assertSame(LicenceState::LICENSED, $state->state);
        self::assertSame(gmdate('c', $this->now + 30 * 86400), $state->until);
        self::assertFalse($state->offline);

        $calls = $this->storeCalls;
        $this->now += 600;
        self::assertSame(LicenceState::LICENSED, $licence->status('whity-pay')->state);
        self::assertSame($calls, $this->storeCalls, 'answered from the cache inside the hour');
    }

    public function testGraceCarriesItsUntilDate(): void
    {
        $this->statementClaims = $this->claims('grace', ['grace_until' => gmdate('c', $this->now + 3 * 86400)]);

        $state = $this->licence()->status('whity-pay');
        self::assertSame(LicenceState::GRACE, $state->state);
        self::assertSame(gmdate('c', $this->now + 3 * 86400), $state->until);
    }

    public function testUnlicensedAndNotConnected(): void
    {
        $this->statementClaims = $this->claims('unlicensed');
        self::assertFalse($this->licence()->status('whity-pay')->isUsable());

        $this->globals->delete(WhityStoreConnector::CONNECTION_KEY);
        $licence = new HostStoreLicence(
            $this->globals,
            new WhityStoreConnector($this->globals, $this->secrets, [], 'https://x.test/c', 'x', static fn () => null, static fn () => null, static fn () => null),
            static fn () => null,
        );
        self::assertStringContainsString('Connect', $licence->status('whity-pay')->message);
    }

    public function testAForgedOrMisdirectedStatementIsRefused(): void
    {
        $this->statementClaims = $this->claims('licensed', ['aud' => 'wid_dyn_someone_else']);
        self::assertFalse($this->licence()->status('whity-pay')->isUsable());

        $this->globals->delete(HostStoreLicence::CACHE_PREFIX . 'whity-pay');
        $this->statementClaims = $this->claims('licensed', ['plugin' => 'other']);
        self::assertFalse($this->licence()->status('whity-pay')->isUsable());

        // Signed with a key the installation does not pin.
        $licence = $this->licence();
        $this->globals->set(HostStoreLicence::CACHE_PREFIX . 'whity-pay', (string) json_encode([
            'host' => self::STORE_HOST,
            'jws' => $this->sign($this->claims('licensed'), str_repeat('k', 32)),
            'checked_at' => $this->now,
        ]));
        $this->statementClaims = null;
        self::assertSame(LicenceState::UNLICENSED, $licence->status('whity-pay')->state, 'a hand-written cache row grants nothing');
    }

    public function testOfflineGraceThenUnlicensed(): void
    {
        $this->statementClaims = $this->claims('licensed');
        $licence = $this->licence();
        self::assertSame(LicenceState::LICENSED, $licence->status('whity-pay')->state);

        // The store goes away. Still inside the statement's day: licensed, but offline.
        $this->statementClaims = null;
        $this->now += 2 * 3600;
        $state = $licence->status('whity-pay');
        self::assertSame(LicenceState::LICENSED, $state->state);
        self::assertTrue($state->offline);

        // The statement has expired: grace until the last good check + 7 days.
        $checkedAt = $this->now - 2 * 3600;
        $this->now += 2 * 86400;
        $state = $licence->status('whity-pay');
        self::assertSame(LicenceState::GRACE, $state->state);
        self::assertSame(gmdate('c', $checkedAt + 7 * 86400), $state->until);
        self::assertTrue($state->offline);

        $this->now = $checkedAt + 7 * 86400 + 1;
        self::assertSame(LicenceState::UNLICENSED, $licence->status('whity-pay')->state);
    }

    public function testNeverCheckedAndOfflineIsUnlicensed(): void
    {
        $this->statementClaims = null;
        $state = $this->licence()->status('whity-pay');
        self::assertSame(LicenceState::UNLICENSED, $state->state);
        self::assertTrue($state->offline);
    }

    public function testTheStoreItselfIsLicensed(): void
    {
        self::assertSame(LicenceState::LICENSED, $this->licence(selfLicensed: true)->status('whity-pay')->state);
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
