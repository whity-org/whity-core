<?php

declare(strict_types=1);

namespace Tests\Core\Settings;

use Database\Migrations\MoveBillingSellerSettingsToCompany;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;

/**
 * Migration 188 (WHIT-788): `billing.seller_*` moves to `company.*` once, in
 * both layers, and never over a company value already set. Runs on SQLite by
 * default and on PostgreSQL when PHPUNIT_PG_DSN is set.
 */
final class MoveBillingSellerSettingsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Database $db;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b'), (3, 'c', 'c')");
        $this->db = Database::withFactory(fn (): PDO => $this->pdo);
        $this->db->setMaxLifetimeSeconds(86400);
        $this->db->setPingIntervalSeconds(86400);
        $this->db->forceConnect();
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
    }

    public function testAWorkspacesSellerValuesBecomeItsCompanyAndTheOldKeysGo(): void
    {
        $this->tenantRow(1, 'billing.seller_name', 'Example Bakery');
        $this->tenantRow(1, 'billing.seller_address', '1 Bread St, Amman');
        $this->tenantRow(1, 'billing.seller_tax_id', 'JO-123');

        MoveBillingSellerSettingsToCompany::up($this->db);

        $effective = $this->settings->effective(1);
        self::assertSame('Example Bakery', $effective[SettingsRegistry::COMPANY_NAME]);
        self::assertSame('1 Bread St, Amman', $effective[SettingsRegistry::COMPANY_ADDRESS]);
        self::assertSame('JO-123', $effective[SettingsRegistry::COMPANY_TAX_ID]);
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM tenant_settings WHERE setting_key LIKE 'billing.seller_%'"));
    }

    public function testGlobalDefaultsMoveAndAWorkspaceWithNoOverrideStillInheritsThem(): void
    {
        $this->globalRow('billing.seller_name', 'Whity Operator');

        MoveBillingSellerSettingsToCompany::up($this->db);

        self::assertSame('Whity Operator', $this->settings->effective(2)[SettingsRegistry::COMPANY_NAME]);
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM app_settings WHERE setting_key LIKE 'billing.seller_%'"));
    }

    public function testACompanyValueAlreadySetIsNeverOverwritten(): void
    {
        $this->tenantRow(1, 'billing.seller_name', 'Old Name');
        $this->tenantRow(1, 'company.name', 'New Name');
        $this->globalRow('billing.seller_name', 'Old Operator');
        $this->globalRow('company.name', 'New Operator');

        MoveBillingSellerSettingsToCompany::up($this->db);

        self::assertSame('New Name', $this->tenantValue(1, 'company.name'));
        self::assertSame('New Operator', $this->globalValue('company.name'));
        self::assertNull($this->tenantValue(1, 'billing.seller_name'), 'the old key goes anyway');
    }

    public function testAnEmptyCompanyValueCountsAsNotSet(): void
    {
        $this->tenantRow(1, 'billing.seller_name', 'Example Bakery');
        $this->tenantRow(1, 'company.name', '');

        MoveBillingSellerSettingsToCompany::up($this->db);

        self::assertSame('Example Bakery', $this->tenantValue(1, 'company.name'));
    }

    public function testAnEmptySellerOverrideStillStopsInheritingTheGlobalDefault(): void
    {
        $this->globalRow('billing.seller_tax_id', 'JO-OPERATOR');
        $this->tenantRow(3, 'billing.seller_tax_id', '');

        MoveBillingSellerSettingsToCompany::up($this->db);

        self::assertSame('', $this->tenantValue(3, 'company.tax_id'));
    }

    public function testItOnlyTouchesItsOwnWorkspacesAndRunsOnce(): void
    {
        $this->tenantRow(1, 'billing.seller_name', 'Example Bakery');

        MoveBillingSellerSettingsToCompany::up($this->db);
        // A person changes it, and the migration runs again: nothing to copy.
        $this->pdo->exec("UPDATE tenant_settings SET value = 'Renamed' WHERE tenant_id = 1 AND setting_key = 'company.name'");
        MoveBillingSellerSettingsToCompany::up($this->db);

        self::assertSame('Renamed', $this->tenantValue(1, 'company.name'));
        self::assertNull($this->tenantValue(2, 'company.name'));
    }

    public function testDownPutsTheSellerValuesBackAndDropsWhatHasNowhereToGo(): void
    {
        $this->tenantRow(1, 'billing.seller_name', 'Example Bakery');
        MoveBillingSellerSettingsToCompany::up($this->db);
        $this->tenantRow(1, 'company.phone', '+962 6 555 0100');
        $this->globalRow('company.email', 'hello@bakery.example');

        MoveBillingSellerSettingsToCompany::down($this->db);

        self::assertSame('Example Bakery', $this->tenantValue(1, 'billing.seller_name'));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM tenant_settings WHERE setting_key LIKE 'company.%'"));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM app_settings WHERE setting_key LIKE 'company.%'"));
    }

    private function tenantRow(int $tenantId, string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)')
            ->execute([$tenantId, $key, $value]);
    }

    private function globalRow(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO app_settings (setting_key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)')
            ->execute([$key, $value]);
    }

    private function tenantValue(int $tenantId, string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?');
        $stmt->execute([$tenantId, $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function globalValue(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function rows(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        return (int) $stmt->fetchColumn();
    }
}
