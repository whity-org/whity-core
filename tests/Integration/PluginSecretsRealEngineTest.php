<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Plugin\HostPluginSecrets;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Secrets\SecretStore;

/**
 * Plugin secrets (SDK 1.60, WHIT-838), against the migration-built schema: kept
 * encrypted, apart by plugin and by workspace, audited by key only, and moved
 * to the current key when read after a rotation.
 */
final class PluginSecretsRealEngineTest extends TestCase
{
    private const CONNECTION = '{"builder_url":"https://builder.example.test","connection_id":"c-42","secret":"s3cr3t-value"}';

    private PDO $pdo;
    /** @var AuditLoggerInterface&object{log: list<array{string, array<string, mixed>}>} */
    private AuditLoggerInterface $audit;
    private EncryptedSecretStore $cipher;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe') ON CONFLICT DO NOTHING");
        $this->cipher = new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1');
        $this->audit  = new class implements AuditLoggerInterface {
            /** @var list<array{string, array<string, mixed>}> */
            public array $log = [];

            public function record(string $action, array $options = []): void
            {
                $this->log[] = [$action, $options];
            }
        };
    }

    public function testASecretIsKeptEncryptedAndReadBack(): void
    {
        $store = $this->store('BuilderConnector');

        $store->set(1, 'connection', self::CONNECTION);

        self::assertSame(self::CONNECTION, $store->get(1, 'connection'));
        $stored = $this->scalar('SELECT value FROM plugin_secrets');
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString('s3cr3t-value', $stored);
    }

    public function testSettingAgainReplacesTheValue(): void
    {
        $store = $this->store('BuilderConnector');
        $store->set(1, 'connection', 'first');
        $store->set(1, 'connection', 'second');

        self::assertSame('second', $store->get(1, 'connection'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM plugin_secrets'));
    }

    public function testEachPluginAndEachWorkspaceIsKeptApart(): void
    {
        $this->store('BuilderConnector')->set(1, 'connection', self::CONNECTION);

        self::assertNull($this->store('Invoicing')->get(1, 'connection'), 'another plugin');
        self::assertNull($this->store('BuilderConnector')->get(2, 'connection'), 'another workspace');
    }

    public function testDeleteRemovesIt(): void
    {
        $store = $this->store('BuilderConnector');
        $store->set(1, 'connection', self::CONNECTION);

        $store->delete(1, 'connection');
        $store->delete(1, 'connection');

        self::assertNull($store->get(1, 'connection'));
    }

    public function testTheAuditTrailNamesTheKeyNeverTheValue(): void
    {
        $store = $this->store('BuilderConnector');
        $store->set(1, 'connection', self::CONNECTION);
        $store->delete(1, 'connection');

        self::assertSame(['plugin.secret.set', 'plugin.secret.deleted'], array_column($this->audit->log, 0));
        self::assertSame(['plugin' => 'builderconnector', 'key' => 'connection'], $this->audit->log[0][1]['metadata']);
        self::assertStringNotContainsString('s3cr3t-value', (string) json_encode($this->audit->log));
    }

    public function testAfterARotationAReadMovesTheSecretToTheCurrentKey(): void
    {
        $this->store('BuilderConnector')->set(1, 'connection', self::CONNECTION);
        $this->cipher = new EncryptedSecretStore(['v2' => str_repeat('n', 32), 'v1' => str_repeat('k', 32)], 'v2');

        self::assertSame(self::CONNECTION, $this->store('BuilderConnector')->get(1, 'connection'));
        self::assertStringStartsWith('v2:', $this->scalar('SELECT value FROM plugin_secrets'));
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'an empty key'      => ['', 'x'],
            'an uppercase key'  => ['Connection', 'x'],
            'a value too long'  => ['connection', str_repeat('x', SecretStore::MAX_VALUE_BYTES + 1)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testABadKeyOrAValueTooLongIsRefused(string $key, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store('BuilderConnector')->set(1, $key, $value);
    }

    public function testAPluginWithNoUsableNameKeepsNoSecrets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store('!!!');
    }

    public function testNoKeyIsNeededUntilASecretIsUsed(): void
    {
        $secrets = new HostPluginSecrets($this->pdo, static fn (): EncryptedSecretStore => throw new \RuntimeException('ENCRYPTION_KEY is not set'));

        $store = $secrets->for($this->plugin('BuilderConnector'));

        $this->expectExceptionMessage('ENCRYPTION_KEY is not set');
        $store->set(1, 'connection', 'x');
    }

    private function store(string $pluginName): SecretStore
    {
        $cipher = $this->cipher;
        return (new HostPluginSecrets($this->pdo, static fn (): EncryptedSecretStore => $cipher, $this->audit))->for($this->plugin($pluginName));
    }

    private function scalar(string $sql): string
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);
        return (string) $stmt->fetchColumn();
    }

    private function plugin(string $name): PluginInterface
    {
        $plugin = $this->createStub(PluginInterface::class);
        $plugin->method('getName')->willReturn($name);
        return $plugin;
    }
}
