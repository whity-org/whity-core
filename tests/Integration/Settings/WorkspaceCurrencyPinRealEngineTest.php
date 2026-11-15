<?php

declare(strict_types=1);

namespace Tests\Integration\Settings;

use Database\Migrations\PinExistingWorkspaceCurrency;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;

/**
 * Design 3.5.1 makes US dollars the default (CU.1). Workspaces that existed
 * before keep the currency they had, and only setting rows are written
 * (WHIT-1087).
 */
final class WorkspaceCurrencyPinRealEngineTest extends TestCase
{
    private const KEY = SettingsRegistry::BILLING_DEFAULT_CURRENCY;

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $pdo = $this->pdo;
        $this->db = Database::withFactory(static fn (): PDO => $pdo);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Pharmacy', 'pharmacy'), (3, 'Example Salon', 'salon')");
        $this->pdo->exec('DELETE FROM tenant_settings');
        $this->pdo->exec('DELETE FROM app_settings');
    }

    public function testANewWorkspaceStartsInDollars(): void
    {
        self::assertSame('USD', SettingsRegistry::defaultFor(self::KEY));
        self::assertSame('USD', $this->effective(1));
    }

    public function testExistingWorkspacesKeepDinarsAndAChosenCurrencyIsLeftAlone(): void
    {
        $this->pdo->exec("INSERT INTO tenant_settings (tenant_id, setting_key, value) VALUES (2, 'billing.default_currency', 'SAR')");

        PinExistingWorkspaceCurrency::up($this->db);
        PinExistingWorkspaceCurrency::up($this->db);

        self::assertSame('JOD', $this->effective(1));
        self::assertSame('SAR', $this->effective(2));
        self::assertSame('JOD', $this->effective(3));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM tenant_settings WHERE tenant_id = 0'), 'the system workspace follows the new default');
        self::assertSame(3, $this->rows("SELECT COUNT(*) FROM tenant_settings WHERE setting_key = 'billing.default_currency'"));

        // A workspace made after the migration is in dollars.
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (4, 'Example Florist', 'florist')");
        self::assertSame('USD', $this->effective(4));
    }

    public function testAnInstallWideCurrencyIsTheOneKept(): void
    {
        $this->pdo->exec("INSERT INTO app_settings (setting_key, value) VALUES ('billing.default_currency', 'aed')");

        PinExistingWorkspaceCurrency::up($this->db);

        self::assertSame('AED', $this->pinned(1));
    }

    public function testDownRemovesOnlyWhatItWroteAndNobodyChanged(): void
    {
        PinExistingWorkspaceCurrency::up($this->db);
        $this->pdo->exec("UPDATE tenant_settings SET value = 'USD', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = 3 AND setting_key = 'billing.default_currency'");

        PinExistingWorkspaceCurrency::down($this->db);

        self::assertNull($this->pinned(1));
        self::assertSame('USD', $this->pinned(3));
    }

    private function effective(int $tenantId): string
    {
        return (string) (new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)))->effective($tenantId)[self::KEY];
    }

    private function pinned(int $tenantId): ?string
    {
        $statement = $this->pdo->prepare("SELECT value FROM tenant_settings WHERE tenant_id = :tenant AND setting_key = 'billing.default_currency'");
        $statement->execute([':tenant' => $tenantId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function rows(string $sql): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}
