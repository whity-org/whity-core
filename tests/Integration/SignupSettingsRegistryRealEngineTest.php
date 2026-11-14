<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\MoveSignupSettingsIntoRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Database\Database;

/**
 * Customer sign-up settings live in the settings registry (WHIT-1035): the
 * migration carries every workspace's saved values over, and the class reads
 * and writes the `signup.*` keys.
 */
final class SignupSettingsRegistryRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const PHARMACY = 2;
    private const CLINIC = 3;

    private PDO $pdo;
    private int $roleId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Pharmacy', 'pharmacy'), (3, 'Example Clinic', 'clinic')");
        $this->pdo->exec("INSERT INTO roles (name, description, tenant_id, created_at) VALUES ('customers', '', 1, CURRENT_TIMESTAMP)");
        $this->roleId = $this->scalar("SELECT id FROM roles WHERE name = 'customers'");
    }

    public function testANewWorkspaceIsOff(): void
    {
        self::assertSame(
            ['mode' => 'off', 'role_id' => null, 'allowed_domains' => [], 'customer_invites' => false, 'updated_at' => null],
            (new WorkspaceSignupSettings($this->pdo))->get(self::BAKERY)
        );
    }

    public function testSavedValuesReadBackForTheirOwnWorkspaceOnly(): void
    {
        $settings = new WorkspaceSignupSettings($this->pdo);
        $settings->save(self::BAKERY, 'approval', $this->roleId, ['example-bakery.com'], null, true);

        $read = $settings->get(self::BAKERY);
        self::assertSame('approval', $read['mode']);
        self::assertSame($this->roleId, $read['role_id']);
        self::assertSame(['example-bakery.com'], $read['allowed_domains']);
        self::assertTrue($read['customer_invites']);
        self::assertNotNull($read['updated_at']);
        self::assertSame('off', $settings->get(self::PHARMACY)['mode']);

        // Null keeps what is saved.
        $settings->save(self::BAKERY, 'open', $this->roleId, [], null);
        self::assertTrue($settings->get(self::BAKERY)['customer_invites']);
        self::assertSame([], $settings->get(self::BAKERY)['allowed_domains']);
    }

    public function testADeletedRoleReadsAsNotChosen(): void
    {
        $settings = new WorkspaceSignupSettings($this->pdo);
        $settings->save(self::BAKERY, 'open', $this->roleId, [], null);
        $this->pdo->exec('DELETE FROM roles WHERE id = ' . $this->roleId);

        self::assertNull($settings->get(self::BAKERY)['role_id']);
    }

    public function testABadValueIsRefusedAndNothingIsWritten(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new WorkspaceSignupSettings($this->pdo))->save(self::BAKERY, 'closed', null, [], null);
        } finally {
            self::assertSame([], (new TenantSettingsRepository($this->pdo))->allForTenant(self::BAKERY));
        }
    }

    public function testTheMigrationCarriesEveryWorkspacesRowOverOnce(): void
    {
        $this->pdo->exec('DELETE FROM tenant_settings');
        $this->pdo->prepare(
            "INSERT INTO workspace_signup_settings (tenant_id, mode, role_id, allowed_domains, customer_invites, updated_at)
             VALUES (1, 'approval', ?, '[\"example-bakery.com\"]', TRUE, '2026-11-01 10:00:00'),
                    (2, 'open', NULL, '[]', FALSE, '2026-11-02 10:00:00')"
        )->execute([$this->roleId]);
        // A value the workspace already holds under the new key wins.
        (new TenantSettingsRepository($this->pdo))->set(self::PHARMACY, 'signup.mode', 'off');

        $pdo = $this->pdo;
        $db = Database::withFactory(static fn (): PDO => $pdo);
        MoveSignupSettingsIntoRegistry::up($db);
        MoveSignupSettingsIntoRegistry::up($db);

        $settings = new WorkspaceSignupSettings($this->pdo);
        $bakery = $settings->get(self::BAKERY);
        self::assertSame('approval', $bakery['mode']);
        self::assertSame($this->roleId, $bakery['role_id']);
        self::assertSame(['example-bakery.com'], $bakery['allowed_domains']);
        self::assertTrue($bakery['customer_invites']);
        self::assertStringStartsWith('2026-11-01', (string) $bakery['updated_at']);

        $pharmacy = $settings->get(self::PHARMACY);
        self::assertSame('off', $pharmacy['mode']);
        self::assertNull($pharmacy['role_id']);
        self::assertFalse($pharmacy['customer_invites']);

        self::assertSame('off', $settings->get(self::CLINIC)['mode']);
        self::assertSame(8, $this->scalar("SELECT COUNT(*) FROM tenant_settings WHERE setting_key LIKE 'signup.%'"));

        MoveSignupSettingsIntoRegistry::down($db);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM tenant_settings WHERE setting_key LIKE 'signup.%'"));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM workspace_signup_settings'));
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
