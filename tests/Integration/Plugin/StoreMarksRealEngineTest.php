<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plugin\PluginRecords;
use Whity\Core\Plugin\Store\StoreMarks;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * Store-signed Verified marks for code plugins (WHIT-1014): a plugin is Verified only while a
 * statement signed by a key pinned for its store names its exact installed package and says
 * `verified`. Real plugin_installs and app_settings; the store is a fake.
 */
final class StoreMarksRealEngineTest extends TestCase
{
    private const HOST = 'store.whity.test';
    private const SLUG = 'crm';
    private const VERSION = '1.2.0';

    private GlobalSettingsRepository $globals;
    private PluginRecords $records;
    private int $now = 2_000_000_000;
    /** @var non-empty-string */
    private string $seed;
    private string $sha;

    /** @var array<string, mixed>|null What the store's statement says next; null = unreachable. */
    private ?array $claims = null;
    /** @var non-empty-string|null Sign with this seed instead of the pinned one. */
    private ?string $signWith = null;
    private int $fetches = 0;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->globals = new GlobalSettingsRepository($pdo);
        $this->records = new PluginRecords($pdo);
        /** @var non-empty-string $seed */
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $this->seed = $seed;
        $this->sha = hash('sha256', 'crm-1.2.0-package');
        $this->records->recordInstall('Crm', 'store', self::HOST, self::SLUG, self::VERSION, $this->sha, [], [], null);
    }

    /** @param array<string, list<string>>|null $pinned */
    private function marks(?array $pinned = null): StoreMarks
    {
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($this->seed));

        return new StoreMarks(
            $this->globals,
            $this->records,
            function (string $url): ?array {
                $this->fetches++;
                if ($this->claims === null) {
                    return null;
                }
                self::assertSame('https://' . self::HOST . '/api/v1/plugin-store/plugins/' . self::SLUG, $url);

                return ['data' => ['slug' => self::SLUG, 'versions' => [
                    ['version' => '1.1.0', 'mark' => $this->sign(['version' => '1.1.0'] + $this->claims)],
                    ['version' => self::VERSION, 'mark' => $this->sign($this->claims)],
                ]]];
            },
            $pinned ?? [self::HOST => [self::b64u($public)]],
            fn (): int => $this->now,
        );
    }

    /** @param array<string, mixed> $extra */
    private function says(string $state, array $extra = []): void
    {
        $this->claims = array_merge([
            'iss' => 'https://' . self::HOST . '/api/v1/plugin-store',
            'plugin' => self::SLUG,
            'version' => self::VERSION,
            'sha256' => $this->sha,
            'state' => $state,
            'reviewed_at' => gmdate('c', $this->now - 86400),
            'revoked_at' => null,
            'iat' => $this->now,
            'exp' => $this->now + 86400,
        ], $extra);
    }

    /** @param array<string, mixed> $claims */
    private function sign(array $claims, string $typ = 'whity-mark+jwt'): string
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($this->signWith ?? $this->seed));
        $input = self::b64u((string) json_encode(['alg' => 'EdDSA', 'typ' => $typ, 'kid' => 'k']))
            . '.' . self::b64u((string) json_encode($claims));

        return $input . '.' . self::b64u(sodium_crypto_sign_detached($input, $secret));
    }

    public function testAReviewedPackageIsVerifiedFromTheCache(): void
    {
        $marks = $this->marks();
        self::assertNull($marks->mark('Crm'), 'nothing fetched yet, and mark() never fetches');
        self::assertSame(0, $this->fetches);

        $this->says('verified');
        self::assertSame(['checked' => 1, 'failed' => 0], $marks->refresh());
        $mark = $marks->mark('Crm');
        self::assertNotNull($mark);
        self::assertSame(self::HOST, $mark['host']);
        self::assertSame(gmdate('c', $this->now - 86400), $mark['reviewed_at']);
        self::assertFalse($mark['offline']);

        $fetches = $this->fetches;
        $marks->refresh();
        self::assertSame($fetches, $this->fetches, 'a recent statement is not fetched again');
    }

    public function testRevokedOrNeverReviewedIsNotVerified(): void
    {
        $marks = $this->marks();
        $this->says('verified');
        $marks->refresh();
        self::assertTrue($marks->isVerified('Crm'));

        $this->says('revoked');
        $marks->refresh(force: true);
        self::assertFalse($marks->isVerified('Crm'), 'a revocation counts at the next fetch');

        $this->says('none');
        $marks->refresh(force: true);
        self::assertFalse($marks->isVerified('Crm'));
    }

    public function testTheMarkMustNameTheExactInstalledPackage(): void
    {
        $marks = $this->marks();
        foreach ([
            ['sha256' => hash('sha256', 'other bytes')],
            ['plugin' => 'other'],
            ['iss' => 'https://elsewhere.test/api/v1/plugin-store'],
        ] as $wrong) {
            $this->says('verified', $wrong);
            $marks->refresh(force: true);
            self::assertFalse($marks->isVerified('Crm'), (string) json_encode($wrong));
        }

        // A newer version installed: the earlier version's statement no longer counts.
        $this->says('verified');
        $marks->refresh(force: true);
        self::assertTrue($marks->isVerified('Crm'));
        $this->records->recordInstall('Crm', 'store', self::HOST, self::SLUG, '1.3.0', hash('sha256', 'crm-1.3.0'), [], [], null);
        self::assertFalse($marks->isVerified('Crm'));
    }

    public function testOnlyAKeyPinnedForThatStoreCounts(): void
    {
        // Signed by a key nobody pinned.
        $this->signWith = str_repeat('k', 32);
        $this->says('verified');
        $marks = $this->marks();
        self::assertSame(['checked' => 0, 'failed' => 1], $marks->refresh());
        self::assertFalse($marks->isVerified('Crm'));

        // Signed by the right key, but for a store whose keys aren't pinned: another allowlisted
        // store can never vouch for code.
        $this->signWith = null;
        $unpinned = $this->marks(['app.whity.dev' => [self::b64u(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($this->seed)))]]);
        $unpinned->refresh(force: true);
        self::assertSame(0, $this->fetches - 1, 'a store without pinned keys is never asked');
        self::assertFalse($unpinned->isVerified('Crm'));

        // A hand-written cache row grants nothing either.
        $this->globals->set(StoreMarks::CACHE_PREFIX . 'Crm', (string) json_encode([
            'host' => self::HOST,
            'jws' => $this->sign($this->claims ?? [], 'whity-licence+jwt'),
            'checked_at' => $this->now,
        ]));
        self::assertFalse($this->marks()->isVerified('Crm'), 'a licence statement is not a mark');
    }

    public function testUploadedOrMountedPluginsHaveNoMark(): void
    {
        $this->records->recordInstall('Hand', 'upload', null, null, '1.0.0', $this->sha, [], [], null);
        $this->says('verified');
        $marks = $this->marks();
        $marks->refresh();
        self::assertFalse($marks->isVerified('Hand'));
        self::assertFalse($marks->isVerified('NeverInstalled'));
    }

    public function testOfflineWindowThenUnverified(): void
    {
        $marks = $this->marks();
        $this->says('verified');
        $marks->refresh();
        $checkedAt = $this->now;

        // The store goes away; the statement is still in date.
        $this->claims = null;
        $this->now += 2 * 3600;
        self::assertSame(['checked' => 0, 'failed' => 1], $marks->refresh());
        self::assertFalse($marks->mark('Crm')['offline'] ?? true);

        // Past the statement's day: still Verified, but offline.
        $this->now = $checkedAt + 3 * 86400;
        self::assertTrue($marks->mark('Crm')['offline'] ?? false);

        // Six days past its expiry, which comes before seven days from the last fetch.
        $this->now = $checkedAt + 86400 + 6 * 86400;
        self::assertFalse($marks->isVerified('Crm'));
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
