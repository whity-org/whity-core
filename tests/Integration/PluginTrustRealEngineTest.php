<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Trust\PluginTrust;
use Whity\Core\Plugin\Trust\TrustedKeys;
use Whity\Core\PluginRoleSeeder;

/**
 * Each control in section 8 of VERIFIED-PLUGINS-SECURITY.md, shown failing closed
 * on the real schema (SQLite, or Postgres with PHPUNIT_PG_DSN).
 *
 * The signing key here is generated in memory for each test and thrown away. It
 * stands in for the owner's key, which never comes near this repository.
 */
final class PluginTrustRealEngineTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MANIFEST_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private PDO $pdo;
    private string $secret;
    private string $public;
    private int $now;
    private PluginTrust $trust;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->now = 1_800_000_000;
        $this->trust = $this->trustWith([$this->public]);
    }

    public function testNoPublicKeyIsPinnedUntilTheOwnerSuppliesOneAndNothingThenPasses(): void
    {
        $trust = new PluginTrust($this->pdo, TrustedKeys::pinned());
        $this->applyFreshList();

        self::assertSame('no_keys', $trust->checkAttestation($this->attestation(), 'CakeOrders', '1.0.0', self::SHA)['reason']);
        self::assertFalse($trust->grant($this->attestation(), 'CakeOrders', '1.0.0', self::SHA, self::MANIFEST_HASH, null));
        self::assertSame(0, $this->marks());
    }

    public function testAValidAttestationGrantsTheMark(): void
    {
        $this->applyFreshList();

        self::assertTrue($this->trust->grant($this->attestation(), 'CakeOrders', '1.0.0', self::SHA, self::MANIFEST_HASH, null));
        self::assertTrue($this->trust->isVerified('CakeOrders', '1.0.0', self::MANIFEST_HASH));
    }

    public function testAMarkBelongsToOneManifest(): void
    {
        $this->applyFreshList();
        $this->trust->grant($this->attestation(), 'CakeOrders', '1.0.0', self::SHA, self::MANIFEST_HASH, null);

        self::assertFalse($this->trust->isVerified('CakeOrders', '1.0.0', str_repeat('c', 64)));
        self::assertFalse($this->trust->isVerified('CakeOrders', '1.0.1', self::MANIFEST_HASH));
    }

    public function testOneFlippedByteIsRefused(): void
    {
        $this->applyFreshList();
        $envelope = json_decode($this->attestation(), true);

        $payload = (string) base64_decode($envelope['payload'], true);
        $payload[10] = $payload[10] === 'a' ? 'b' : 'a';
        $tamperedPayload = json_encode(['payload' => base64_encode($payload)] + $envelope);

        $signature = (string) base64_decode($envelope['signature'], true);
        $signature[0] = $signature[0] === "\x00" ? "\x01" : "\x00";
        $tamperedSignature = json_encode(['signature' => base64_encode($signature)] + $envelope);

        foreach ([$tamperedPayload, $tamperedSignature, 'not json', '{}'] as $bad) {
            self::assertSame('bad_signature', $this->trust->checkAttestation((string) $bad, 'CakeOrders', '1.0.0', self::SHA)['reason']);
        }
        self::assertSame(0, $this->marks());
    }

    public function testASignatureFromAnUnpinnedKeyIsRefusedEvenWhenItNamesItself(): void
    {
        $this->applyFreshList();
        $other = sodium_crypto_sign_keypair();

        $forged = $this->sign(PluginTrust::ATTESTATION, $this->statement(), sodium_crypto_sign_secretkey($other), base64_encode(sodium_crypto_sign_publickey($other)));

        self::assertSame('bad_signature', $this->trust->checkAttestation($forged, 'CakeOrders', '1.0.0', self::SHA)['reason']);
    }

    public function testASignedListCannotPassAsAnAttestation(): void
    {
        $this->applyFreshList();
        $crossed = $this->sign(PluginTrust::REVOCATIONS, ['format' => PluginTrust::ATTESTATION] + $this->statement());

        self::assertSame('bad_signature', $this->trust->checkAttestation($crossed, 'CakeOrders', '1.0.0', self::SHA)['reason']);
    }

    public function testItMustNameThisPluginVersionAndPackage(): void
    {
        $this->applyFreshList();
        $good = $this->attestation();

        self::assertSame('mismatch', $this->trust->checkAttestation($good, 'Other', '1.0.0', self::SHA)['reason']);
        self::assertSame('mismatch', $this->trust->checkAttestation($good, 'CakeOrders', '2.0.0', self::SHA)['reason']);
        self::assertSame('mismatch', $this->trust->checkAttestation($good, 'CakeOrders', '1.0.0', str_repeat('d', 64))['reason']);
    }

    public function testAnExpiredAttestationIsRefused(): void
    {
        $this->applyFreshList();
        $expired = $this->sign(PluginTrust::ATTESTATION, ['expires_at' => $this->now - 1] + $this->statement());

        self::assertSame('expired', $this->trust->checkAttestation($expired, 'CakeOrders', '1.0.0', self::SHA)['reason']);
    }

    public function testNoListOrAStaleListMeansNoNewActivationButTheMarkStands(): void
    {
        self::assertSame('list_stale', $this->trust->checkAttestation($this->attestation(), 'CakeOrders', '1.0.0', self::SHA)['reason']);

        $this->applyFreshList();
        $this->trust->grant($this->attestation(), 'CakeOrders', '1.0.0', self::SHA, self::MANIFEST_HASH, null);

        $this->now += 8 * 86400;

        self::assertTrue($this->trust->isStale());
        self::assertSame('list_stale', $this->trust->checkAttestation($this->attestation(['expires_at' => $this->now + 86400]), 'CakeOrders', '1.0.0', self::SHA)['reason']);
        self::assertTrue($this->trust->isVerified('CakeOrders', '1.0.0', self::MANIFEST_HASH), 'decision 7: it keeps running');
    }

    public function testAnOlderListCannotBeReplayedToUndoARevocation(): void
    {
        $this->applyList(5, []);
        $this->applyList(6, [['plugin' => 'CakeOrders', 'version' => '1.0.0', 'action' => 'demote']]);

        self::assertSame('replay', $this->applyList(5, [])['reason']);
        self::assertSame('replay', $this->applyList(6, [])['reason']);
        self::assertSame(6, $this->trust->status()['sequence']);
        self::assertSame('revoked', $this->trust->checkAttestation($this->attestation(), 'CakeOrders', '1.0.0', self::SHA)['reason']);
    }

    public function testAForgedListChangesNothing(): void
    {
        $this->applyList(5, []);
        $other = sodium_crypto_sign_keypair();
        $forged = $this->sign(
            PluginTrust::REVOCATIONS,
            ['sequence' => 99, 'issued_at' => $this->now, 'expires_at' => $this->now + 86400, 'revoked' => []],
            sodium_crypto_sign_secretkey($other),
            $this->public
        );

        self::assertSame('bad_signature', $this->trust->applyRevocationList($forged)['reason']);
        self::assertSame(5, $this->trust->status()['sequence']);
    }

    public function testDemoteRemovesTheMarkAndLeavesThePluginOn(): void
    {
        [$tenant] = $this->installedAndOn();
        $this->applyFreshList();
        $this->trust->grant($this->attestation(), 'CakeOrders', '1.0.0', self::SHA, self::MANIFEST_HASH, null);

        $this->applyList(10, [['plugin' => 'CakeOrders', 'version' => '1.0.0', 'action' => 'demote', 'reason' => 'publisher lapsed']]);

        self::assertFalse($this->trust->isVerified('CakeOrders', '1.0.0', self::MANIFEST_HASH));
        self::assertFalse($this->trust->isKilled('CakeOrders', '1.0.0'));
        self::assertCount(1, (new TenantPluginRepository($this->pdo))->active($tenant));
    }

    public function testKillTurnsItOffInEveryWorkspaceAtOnce(): void
    {
        [$tenant, $other] = $this->installedAndOn();
        $this->applyFreshList();
        $this->trust->grant($this->attestation(), 'CakeOrders', '1.0.0', self::SHA, self::MANIFEST_HASH, null);

        $this->applyList(10, [['plugin' => 'CakeOrders', 'version' => '1.0.0', 'action' => 'kill', 'reason' => 'bad update']]);

        $plugins = new TenantPluginRepository($this->pdo);
        self::assertSame([], $plugins->active($tenant));
        self::assertSame([], $plugins->active($other));
        self::assertTrue($this->trust->isKilled('CakeOrders', '1.0.0'));
        self::assertFalse($this->trust->isVerified('CakeOrders', '1.0.0', self::MANIFEST_HASH));
    }

    public function testKillingOneVersionLeavesAnotherAlone(): void
    {
        [$tenant] = $this->installedAndOn();
        $this->applyList(10, [['plugin' => 'CakeOrders', 'version' => '9.9.9', 'action' => 'kill']]);

        self::assertCount(1, (new TenantPluginRepository($this->pdo))->active($tenant));
        self::assertFalse($this->trust->isKilled('CakeOrders', '1.0.0'));
    }

    public function testOnlyPluginTrustWritesTheMarkTables(): void
    {
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        $writers = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(plugin_version_marks|plugin_revocation_state)\b/i', $source) === 1) {
                $writers[] = basename($file->getPathname());
            }
        }

        // An empty result would mean the scan stopped matching, not that all is well.
        self::assertSame(['PluginTrust.php'], $writers);
    }

    public function testTheTableMigrationsRoundTrip(): void
    {
        $pdo = $this->pdo;
        $db = \Whity\Database\Database::withFactory(static fn (): PDO => $pdo);
        foreach ([[\Database\Migrations\CreatePluginVersionMarks::class, 'plugin_version_marks'], [\Database\Migrations\CreatePluginRevocationState::class, 'plugin_revocation_state']] as [$migration, $table]) {
            $migration::down($db);
            self::assertFalse($this->tableExists($table));
            $migration::up($db);
            self::assertTrue($this->tableExists($table));
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->prepare("SELECT 1 FROM {$table}")->execute();

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @param list<string> $keys */
    private function trustWith(array $keys): PluginTrust
    {
        return new PluginTrust($this->pdo, new TrustedKeys($keys), null, fn (): int => $this->now);
    }

    /** @return array<string, mixed> */
    private function statement(): array
    {
        return [
            'format' => PluginTrust::ATTESTATION,
            'plugin' => 'CakeOrders',
            'version' => '1.0.0',
            'sha256' => self::SHA,
            'issued_at' => $this->now,
            'expires_at' => $this->now + 30 * 86400,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function attestation(array $overrides = []): string
    {
        return $this->sign(PluginTrust::ATTESTATION, $overrides + $this->statement());
    }

    /** @param array<string, mixed> $body */
    private function sign(string $format, array $body, ?string $secret = null, ?string $public = null): string
    {
        $payload = (string) json_encode(['format' => $body['format'] ?? $format] + $body);
        /** @var non-empty-string $key */
        $key = $secret ?? $this->secret;

        return (string) json_encode([
            'key' => $public ?? $this->public,
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($format . "\n" . $payload, $secret ?? $this->secret)),
        ]);
    }

    private function applyFreshList(): void
    {
        self::assertTrue($this->applyList(1, [])['ok']);
    }

    /**
     * @param list<array<string, mixed>> $revoked
     * @return array{ok: bool, reason: string}
     */
    private function applyList(int $sequence, array $revoked): array
    {
        return $this->trust->applyRevocationList($this->sign(PluginTrust::REVOCATIONS, [
            'sequence' => $sequence,
            'issued_at' => $this->now,
            'expires_at' => $this->now + 7 * 86400,
            'revoked' => $revoked,
        ]));
    }

    private function marks(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM plugin_version_marks');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array{0: int, 1: int} Two workspaces with the plugin on. */
    private function installedAndOn(): array
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
        SchemaFromMigrations::syncSequences($this->pdo);
        $publisher = new TenantManifestPublisher($this->pdo, new TenantPluginRepository($this->pdo), new PluginRoleSeeder($this->pdo), []);
        $manifest = [
            'manifest' => 'whity.plugin/1',
            'plugin' => ['name' => 'CakeOrders', 'version' => '1.0.0', 'label' => ['en' => 'Cake orders', 'ar' => 'طلبات الكعك']],
        ];
        $publisher->publish(1, $manifest);
        $publisher->publish(2, $manifest);

        return [1, 2];
    }
}
