<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\SalonsCopy;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\RBAC\CorePermissions;

/**
 * Copying the retired Salons plugin's rows into core (WHIT-1334, design
 * section 35 §6): walk-ins by legacy id, rates by person and service, grants
 * and the walk-ins switch; run twice, it copies nothing more; with no Salons
 * tables it does nothing.
 *
 * Salons' tables are made with the plugin's own DDL (salons 0.7.1).
 */
final class SalonsCopyRealEngineTest extends TestCase
{
    private PDO $pdo;
    private int $haircut;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (5, 'Example Salon', 'example-salon'), (9, 'Other Salon', 'other-salon')");
        foreach ([4, 6] as $profile) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$profile}, 'Person {$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
        }
        $this->haircut = (new ServiceRepository($this->pdo))->insert(5, [
            'name' => ['en' => 'Haircut'], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 5000, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
    }

    public function testNothingToDoWithoutSalons(): void
    {
        $copy = new SalonsCopy($this->pdo);
        self::assertSame(['walkins' => 0, 'walkins_skipped' => 0, 'rates' => 0, 'grants' => 0, 'switched_on' => 0], $copy->run());
        self::assertSame([], $copy->counts());
    }

    public function testCopiesOnceAndPicksUpWhatCameLater(): void
    {
        $this->salonsTables();
        $this->pdo->exec("INSERT INTO salons_walkins (tenant_id, name, phone, service_id, preferred_profile_id, note, status, added_at, added_by_profile_id)
                          VALUES (5, 'Lina', '+962790000001', {$this->haircut}, 6, 'Fringe only', 'waiting', '2026-11-20 10:00:00', 4)");
        $this->pdo->exec("INSERT INTO salons_walkins (tenant_id, name, phone, service_id, status, added_at, assigned_profile_id, booking_id, contact_id, assigned_at)
                          VALUES (5, 'Omar', '', {$this->haircut}, 'served', '2026-11-20 10:05:00', 6, 999, 998, '2026-11-20 10:20:00')");
        $this->pdo->exec("INSERT INTO salons_walkins (tenant_id, name, phone, service_id, status, added_at)
                          VALUES (5, 'Sami', '', {$this->haircut}, 'assigning', '2026-11-20 10:06:00')");
        // A service since removed: skipped, not failed.
        $this->pdo->exec("INSERT INTO salons_walkins (tenant_id, name, phone, service_id, status, added_at) VALUES (9, 'Dana', '', 777, 'left', '2026-11-20 10:07:00')");
        $this->pdo->exec("INSERT INTO salons_commission_rates (tenant_id, profile_id, service_id, percent_bp, updated_at) VALUES (5, 6, 0, 3000, '2026-11-19 09:00:00'), (5, 6, {$this->haircut}, 2500, '2026-11-19 09:00:00'), (5, 404, 0, 1000, '2026-11-19 09:00:00')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (950, 'front', '', NULL, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO permissions (name, description, created_at) VALUES ('salons:front_desk', 'Salons plugin permission', CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) SELECT 950, id, CURRENT_TIMESTAMP FROM permissions WHERE name = 'salons:front_desk'");

        $copy = new SalonsCopy($this->pdo);
        $first = $copy->run();
        self::assertSame(3, $first['walkins']);
        self::assertSame(1, $first['walkins_skipped']);
        self::assertSame(2, $first['rates'], 'the rate of a person who is gone is left');
        self::assertSame(1, $first['grants']);
        self::assertSame(2, $first['switched_on']);

        $stmt = $this->pdo->query('SELECT name, status, preferred_profile_id, booking_id, contact_id, legacy_id FROM calendar_walkins ORDER BY legacy_id');
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['Lina', 'Omar', 'Sami'], array_column($rows, 'name'));
        self::assertSame(['waiting', 'served', 'waiting'], array_column($rows, 'status'));
        self::assertSame(6, (int) $rows[0]['preferred_profile_id']);
        self::assertNull($rows[1]['booking_id'], 'a booking that is not there is left empty');
        self::assertNull($rows[1]['contact_id']);
        self::assertTrue($this->roleHolds(950, CorePermissions::CALENDAR_FRONT_DESK));
        self::assertSame('true', $this->setting(5));
        self::assertSame('true', $this->setting(9));

        // Again: nothing more. Then a walk-in added since, and a workspace that
        // turned walk-ins off keeps its choice.
        self::assertSame(['walkins' => 0, 'walkins_skipped' => 1, 'rates' => 0, 'grants' => 0, 'switched_on' => 0], $copy->run());
        $this->pdo->exec("UPDATE tenant_settings SET value = 'false' WHERE tenant_id = 9 AND setting_key = 'calendar.walkins_enabled'");
        $this->pdo->exec("INSERT INTO salons_walkins (tenant_id, name, phone, service_id, status, added_at) VALUES (5, 'Rana', '', {$this->haircut}, 'waiting', '2026-11-21 09:00:00')");
        self::assertSame(1, $copy->run()['walkins']);
        self::assertSame('false', $this->setting(9));

        self::assertSame(['walkins' => 4, 'walkins_copied' => 4, 'rates' => 3, 'rates_in_core' => 2], $copy->counts()[5]);
    }

    private function salonsTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS salons_walkins (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(200) NOT NULL,
                phone VARCHAR(40) NOT NULL DEFAULT '',
                service_id INTEGER NOT NULL,
                preferred_profile_id INTEGER NULL,
                note VARCHAR(500) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'waiting',
                added_at TIMESTAMP NOT NULL,
                added_by_profile_id INTEGER NULL,
                assigned_at TIMESTAMP NULL,
                assigned_profile_id INTEGER NULL,
                booking_id INTEGER NULL,
                contact_id INTEGER NULL,
                left_at TIMESTAMP NULL
            )
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS salons_commission_rates (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                profile_id INTEGER NOT NULL,
                service_id INTEGER NOT NULL DEFAULT 0,
                percent_bp INTEGER NOT NULL,
                updated_at TIMESTAMP NOT NULL,
                updated_by_profile_id INTEGER NULL,
                CHECK (percent_bp >= 0 AND percent_bp <= 10000)
            )
        ");
    }

    private function roleHolds(int $roleId, string $permission): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r AND p.name = :n');
        $stmt->execute([':r' => $roleId, ':n' => $permission]);

        return $stmt->fetchColumn() !== false;
    }

    private function setting(int $tenantId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM tenant_settings WHERE tenant_id = :t AND setting_key = 'calendar.walkins_enabled'");
        $stmt->execute([':t' => $tenantId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }
}
