<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Mail\MailerFactory;
use Whity\Core\Notification\Push\VapidKeys;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Security\SecretLocations;
use Whity\Core\Security\SecretReencryptor;
use Whity\Core\Security\SecretRef;

/**
 * Every stored secret moves onto the current key after a rotation, and only
 * that (merge map plumbing, WHIT-1047): `whity-cli secrets reencrypt`.
 */
final class SecretReencryptorRealEngineTest extends TestCase
{
    private PDO $pdo;
    private EncryptedSecretStore $old;
    private EncryptedSecretStore $rotated;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->old = new EncryptedSecretStore(['v1' => str_repeat('a', 32)], 'v1');
        $this->rotated = new EncryptedSecretStore(['v1' => str_repeat('a', 32), 'v2' => str_repeat('b', 32)], 'v2');
    }

    public function testEveryPlaceInTheListExistsInTheSchema(): void
    {
        foreach (SecretLocations::all() as $ref) {
            $columns = array_column($this->q("PRAGMA table_info({$ref->table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            if ($ref->table === 'messaging_settings' && $columns === []) {
                continue; // moved into settings by migration 294
            }
            self::assertContains($ref->column, $columns, "{$ref->table}.{$ref->column}");
            self::assertContains($ref->idColumn, $columns, "{$ref->table}.{$ref->idColumn}");
        }
    }

    public function testOldKeyValuesMoveAndEverythingStillReads(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery')");
        $this->setting(MailerFactory::SMTP_PASSWORD_SETTING_KEY, $this->old->encrypt('smtp-pass'));
        $this->setting(VapidKeys::SETTING_KEY, (string) json_encode(['public' => 'PUB', 'private' => $this->old->encrypt('PRIVATE-PEM')]));
        (new IdentityProviderRepository($this->pdo))->insert(1, [
            'provider_key' => 'whity_id', 'display_name' => 'Whity ID', 'client_id' => 'c', 'issuer' => 'https://id.example',
            'client_secret_encrypted' => $this->old->encrypt('client-secret'),
        ]);
        $this->pdo->exec("INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at) VALUES (1, 'notifications.email.credentials_encrypted', '" . $this->old->encrypt('{\"key\":\"k\"}') . "', CURRENT_TIMESTAMP)");

        $dry = $this->totals((new SecretReencryptor($this->pdo, $this->rotated))->run(true));
        self::assertSame(['moved' => 4, 'current' => 0, 'failed' => 0], $dry);
        self::assertFalse($this->rotated->isEncryptedWithCurrentKey((string) $this->value(MailerFactory::SMTP_PASSWORD_SETTING_KEY)), 'a dry run writes nothing');

        $first = $this->totals((new SecretReencryptor($this->pdo, $this->rotated))->run());
        self::assertSame(['moved' => 4, 'current' => 0, 'failed' => 0], $first);

        $smtp = (string) $this->value(MailerFactory::SMTP_PASSWORD_SETTING_KEY);
        self::assertTrue($this->rotated->isEncryptedWithCurrentKey($smtp));
        self::assertSame('smtp-pass', $this->rotated->decrypt($smtp));
        $vapid = json_decode((string) $this->value(VapidKeys::SETTING_KEY), true);
        self::assertSame('PUB', $vapid['public'], 'the rest of the JSON is kept');
        self::assertSame('PRIVATE-PEM', $this->rotated->decrypt($vapid['private']));
        $client = (string) $this->q('SELECT client_secret_encrypted FROM identity_providers')->fetchColumn();
        self::assertSame('client-secret', $this->rotated->decrypt($client));

        self::assertSame(['moved' => 0, 'current' => 4, 'failed' => 0], $this->totals((new SecretReencryptor($this->pdo, $this->rotated))->run()), 'safe to run again');
    }

    public function testAnUnreadableValueIsCountedAndLeftAlone(): void
    {
        $this->setting(MailerFactory::SMTP_PASSWORD_SETTING_KEY, 'v9:not-a-real-ciphertext');

        $report = (new SecretReencryptor($this->pdo, $this->rotated))->run(false, [SecretRef::instanceSetting(MailerFactory::SMTP_PASSWORD_SETTING_KEY, 'SMTP password')]);

        self::assertSame(1, $report[0]['failed']);
        self::assertSame('v9:not-a-real-ciphertext', $this->value(MailerFactory::SMTP_PASSWORD_SETTING_KEY));
    }

    /**
     * @param list<array{label: string, moved: int, current: int, failed: int, missing: bool}> $report
     * @return array{moved: int, current: int, failed: int}
     */
    private function totals(array $report): array
    {
        $out = ['moved' => 0, 'current' => 0, 'failed' => 0];
        foreach ($report as $line) {
            foreach ($out as $k => $_) {
                $out[$k] += $line[$k];
            }
        }

        return $out;
    }

    private function setting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO app_settings (setting_key, value, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP)');
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    private function value(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM app_settings WHERE setting_key = :k');
        $stmt->execute([':k' => $key]);
        $v = $stmt->fetchColumn();

        return is_string($v) ? $v : null;
    }

    private function q(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt;
    }
}
