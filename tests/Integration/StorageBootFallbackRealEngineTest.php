<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Storage\LocalStorageDriver;
use Whity\Storage\S3\S3StorageDriver;
use Whity\Storage\StorageDriverFactory;
use Whity\Storage\StorageException;

/**
 * #1622: a stored storage.* value the driver cannot be built from must not take
 * the instance down at boot.
 *
 * Runs against the migration-built schema (SQLite, or PostgreSQL when
 * PHPUNIT_PG_DSN is set). The bad values are written straight into
 * app_settings, the way a row saved before save-time validation existed — or
 * fixed by hand on the database host — would sit there.
 */
final class StorageBootFallbackRealEngineTest extends TestCase
{
    private const SECRET = ['STORAGE_S3_SECRET_KEY' => 'super-secret-key-value-1234567890'];

    private PDO $pdo;
    private GlobalSettingsRepository $globals;
    private SettingsService $settings;
    private string $root;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->globals = new GlobalSettingsRepository($this->pdo);
        $this->settings = new SettingsService($this->globals, new TenantSettingsRepository($this->pdo));
        $this->root = sys_get_temp_dir() . '/whity-1622-' . bin2hex(random_bytes(4));
        $this->logger = new RecordingLogger();
    }

    public function testAnS3EndpointWithNoSchemeBootsOnLocalStorageAndLogsWhy(): void
    {
        $this->storeCompleteS3Config(endpoint: 's3.eu-central-003.backblazeb2.com');

        $driver = StorageDriverFactory::fromSettingsOrLocal($this->settings, self::SECRET, $this->root, $this->logger);

        self::assertInstanceOf(LocalStorageDriver::class, $driver);
        $this->assertOneCriticalNaming('invalid endpoint');

        // The fallback is a working driver, not a placeholder.
        $driver->put('tenants/1/probe.txt', 'ok');
        self::assertSame('ok', $driver->get('tenants/1/probe.txt'));
    }

    public function testS3SelectedBeforeTheSecretIsInTheEnvironmentBootsOnLocalStorage(): void
    {
        $this->storeCompleteS3Config(endpoint: 'https://s3.eu-central-003.backblazeb2.com');

        $driver = StorageDriverFactory::fromSettingsOrLocal($this->settings, [], $this->root, $this->logger);

        self::assertInstanceOf(LocalStorageDriver::class, $driver);
        $this->assertOneCriticalNaming('STORAGE_S3_SECRET_KEY');
    }

    public function testOnceTheAdminCorrectsTheEndpointTheNextBootBuildsS3(): void
    {
        $this->storeCompleteS3Config(endpoint: 's3.eu-central-003.backblazeb2.com');
        self::assertInstanceOf(
            LocalStorageDriver::class,
            StorageDriverFactory::fromSettingsOrLocal($this->settings, self::SECRET, $this->root, $this->logger)
        );

        // The correction goes through the validated service path, as the admin's would.
        $this->settings->setGlobal(SettingsRegistry::STORAGE_S3_ENDPOINT, 'https://s3.eu-central-003.backblazeb2.com');
        $this->logger->records = [];

        $driver = StorageDriverFactory::fromSettingsOrLocal($this->settings, self::SECRET, $this->root, $this->logger);

        self::assertInstanceOf(S3StorageDriver::class, $driver);
        self::assertSame([], $this->logger->records, 'A buildable configuration logs nothing.');
    }

    public function testTheStrictPathStillRefusesTheSameConfiguration(): void
    {
        // The upload sweep deletes rows; it must fail rather than run against
        // a backend that does not hold the objects.
        $this->storeCompleteS3Config(endpoint: 's3.eu-central-003.backblazeb2.com');

        $this->expectException(StorageException::class);
        StorageDriverFactory::fromSettings($this->settings, self::SECRET, $this->root);
    }

    public function testTheServiceRefusesTheBadEndpointAtSave(): void
    {
        $this->expectException(\Whity\Core\Settings\SettingsValidationException::class);
        $this->settings->setGlobal(SettingsRegistry::STORAGE_S3_ENDPOINT, 's3.eu-central-003.backblazeb2.com');
    }

    private function storeCompleteS3Config(string $endpoint): void
    {
        // Raw repository writes: no validation, like a pre-#1622 row.
        $this->globals->set(SettingsRegistry::STORAGE_DRIVER, 's3');
        $this->globals->set(SettingsRegistry::STORAGE_S3_ENDPOINT, $endpoint);
        $this->globals->set(SettingsRegistry::STORAGE_S3_REGION, 'eu-central-003');
        $this->globals->set(SettingsRegistry::STORAGE_S3_BUCKET, 'whity-bucket');
        $this->globals->set(SettingsRegistry::STORAGE_S3_ACCESS_KEY, 'AKIAEXAMPLE');
    }

    private function assertOneCriticalNaming(string $reasonFragment): void
    {
        self::assertCount(1, $this->logger->records);
        [$level, $message, $context] = $this->logger->records[0];
        self::assertSame(LogLevel::CRITICAL, $level);
        self::assertStringContainsString('falling back to LOCAL storage', $message);
        self::assertStringContainsString($reasonFragment, (string) ($context['reason'] ?? ''));
    }
}

/** Keeps every record so a test can read what boot logged. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
