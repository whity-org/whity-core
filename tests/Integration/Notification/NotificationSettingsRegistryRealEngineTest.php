<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use Database\Migrations\MoveNotificationSettingsIntoRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Notification\TenantNotificationSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;

/**
 * Notification senders live in the settings registry (WHIT-1036): the
 * migration carries every workspace's channels over, credentials included and
 * still encrypted, and the repository reads and writes the
 * `notifications.<channel>.<field>` keys.
 */
final class NotificationSettingsRegistryRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const PHARMACY = 2;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Pharmacy', 'pharmacy')");
    }

    public function testTheMigrationCarriesEveryChannelOverOnce(): void
    {
        $this->pdo->exec('DELETE FROM tenant_settings');
        $this->pdo->exec(
            "INSERT INTO tenant_notification_settings (tenant_id, channel, transport, from_address, from_name, reply_to, config, credentials_encrypted, enabled, created_at, updated_at)
             VALUES (1, 'email', 'smtp', 'hello@example-bakery.com', 'Example Bakery', NULL, '{\"host\":\"mail.example-bakery.com\"}', 'k1:ciphertext', FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                    (2, 'sms', NULL, NULL, NULL, NULL, '{}', NULL, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        // A value the workspace already holds under the new key wins.
        (new TenantSettingsRepository($this->pdo))->set(self::PHARMACY, 'notifications.sms.enabled', 'false');

        $pdo = $this->pdo;
        $db = Database::withFactory(static fn (): PDO => $pdo);
        MoveNotificationSettingsIntoRegistry::up($db);
        MoveNotificationSettingsIntoRegistry::up($db);

        $repo = new TenantNotificationSettingsRepository($this->pdo);
        $email = $repo->findWithCredentials(self::BAKERY, 'email');
        self::assertNotNull($email);
        self::assertSame('smtp', $email['transport']);
        self::assertSame('hello@example-bakery.com', $email['from_address']);
        self::assertSame('Example Bakery', $email['from_name']);
        self::assertNull($email['reply_to']);
        self::assertSame(['host' => 'mail.example-bakery.com'], $email['config']);
        self::assertFalse($email['enabled']);
        self::assertTrue($email['has_credentials']);
        self::assertSame('k1:ciphertext', $email['credentials_encrypted'], 'copied as stored, never decrypted');
        self::assertArrayNotHasKey('credentials_encrypted', (array) $repo->findForChannel(self::BAKERY, 'email'));

        self::assertFalse($repo->findForChannel(self::PHARMACY, 'sms')['enabled'] ?? null);
        self::assertNull($repo->findForChannel(self::PHARMACY, 'email'));
        self::assertSame([], $repo->listForTenant(3));

        MoveNotificationSettingsIntoRegistry::down($db);
        self::assertSame([], $repo->listForTenant(self::BAKERY));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM tenant_notification_settings'));
    }

    public function testConfigAndCredentialsAreWrittenApartAndPerWorkspace(): void
    {
        $repo = new TenantNotificationSettingsRepository($this->pdo);
        $repo->setCredentials(self::BAKERY, 'email', 'k1:secret');
        $repo->upsertConfig(self::BAKERY, 'email', ['transport' => 'smtp', 'from_address' => 'hello@example-bakery.com', 'config' => ['port' => 587], 'enabled' => true]);

        $email = $repo->findWithCredentials(self::BAKERY, 'email');
        self::assertSame('k1:secret', $email['credentials_encrypted'] ?? null, 'config left the credentials alone');
        self::assertSame(['port' => 587], $email['config'] ?? null);
        self::assertNull($repo->findForChannel(self::PHARMACY, 'email'));

        $repo->setCredentials(self::BAKERY, 'email', null);
        self::assertFalse($repo->findForChannel(self::BAKERY, 'email')['has_credentials'] ?? null);
        self::assertSame('smtp', $repo->findForChannel(self::BAKERY, 'email')['transport'] ?? null, 'credentials left the config alone');

        self::assertTrue($repo->delete(self::BAKERY, 'email'));
        self::assertNull($repo->findForChannel(self::BAKERY, 'email'));
        self::assertFalse($repo->delete(self::BAKERY, 'email'));
    }

    public function testAValueTheRegistryRefusesWritesNothing(): void
    {
        $repo = new TenantNotificationSettingsRepository($this->pdo);

        try {
            $repo->upsertConfig(self::BAKERY, 'email', ['transport' => 'smtp', 'from_address' => 'not an address']);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException) {
        }

        self::assertNull($repo->findForChannel(self::BAKERY, 'email'), 'the transport before it was rolled back');
    }

    public function testTheKeyFamily(): void
    {
        self::assertSame('notifications.email.enabled', SettingsRegistry::notificationKey('email', 'enabled'));
        self::assertSame(['channel' => 'whatsapp', 'field' => 'from_name'], SettingsRegistry::parseNotificationKey('notifications.whatsapp.from_name'));
        self::assertNull(SettingsRegistry::parseNotificationKey('notifications.email.password'));
        self::assertNull(SettingsRegistry::parseNotificationKey('notifications.a.b.enabled'));
        self::assertFalse(SettingsRegistry::isKnown('notifications.email.enabled'), 'never on the generic settings API');
        self::assertNull(SettingsRegistry::validateNotificationValue('config', '{}'));
        self::assertNotNull(SettingsRegistry::validateNotificationValue('config', '[1,2]'));
        self::assertNotNull(SettingsRegistry::validateNotificationValue('enabled', '1'));
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
