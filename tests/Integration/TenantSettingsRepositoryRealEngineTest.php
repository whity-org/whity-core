<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Settings\TenantSettingsRepository;
use Tests\Support\SchemaFromMigrations;

/**
 * {@see TenantSettingsRepository::valuesFor()} reads only the asked keys of
 * one workspace (WHIT-1037).
 */
final class TenantSettingsRepositoryRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Pharmacy', 'pharmacy')");
    }

    public function testValuesForReadsOnlyTheAskedKeysOfOneWorkspace(): void
    {
        $repo = new TenantSettingsRepository($this->pdo);
        $repo->set(1, 'company.name', 'Example Bakery');
        $repo->set(1, 'company.tax_id', '123');
        $repo->set(1, 'timezone', 'Asia/Amman');
        $repo->set(2, 'company.name', 'Example Pharmacy');

        $values = $repo->valuesFor(1, ['company.name', 'company.tax_id', 'company.phone']);
        ksort($values);

        self::assertSame(['company.name' => 'Example Bakery', 'company.tax_id' => '123'], $values);
        self::assertSame(['company.name' => 'Example Pharmacy'], $repo->valuesFor(2, ['company.name', 'company.tax_id']));
        self::assertSame([], $repo->valuesFor(1, []));
    }
}
