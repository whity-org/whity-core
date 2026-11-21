<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Commission;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Staff commission against the real schema (WHIT-1334, design section 35 §4):
 * rates with per-service overrides, the report from done bookings (each line
 * of a booking of several for its own person), periods in the workspace's
 * zone, and workspace isolation.
 *
 * Example Bakery (tenant 1): bakers 11 and 12; tenant 2: baker 21.
 */
final class CommissionRealEngineTest extends TestCase
{
    /** Wednesday 18 November 2026, 15:00 in Amman. */
    private const NOW = '2026-11-18 12:00:00';

    private PDO $pdo;
    private Commission $commission;
    private ServiceRepository $services;
    private int $tasting;
    private int $decorating;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 12 => 1, 21 => 2] as $profile => $tenant) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$profile}, 'Baker {$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $this->pdo->exec(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
                 VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)"
            );
        }
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        foreach ([1, 2] as $tenant) {
            $settings->setTenant($tenant, 'timezone', 'Asia/Amman');
        }
        $calendar = new CalendarRepository($this->pdo);
        $this->services = new ServiceRepository($this->pdo);
        $bookings = new BookingRepository($this->pdo);
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
        $booking = new BookingService($this->pdo, $calendar, $this->services, $bookings, $settings, null, clock: $clock);
        $this->commission = new Commission($this->pdo, $booking, $calendar, $this->services, $clock);

        $this->tasting = $this->service('Cake tasting', 10000);
        $this->decorating = $this->service('Decorating', 20000);
        foreach ([[1, 11], [1, 12], [2, 21]] as [$tenant, $baker]) {
            $calendar->ensureStaff($tenant, $baker);
        }
    }

    public function testRatesWithAnOverrideForOneService(): void
    {
        $this->commission->setRate(1, ['profile_id' => 11, 'percent' => '30'], 11);
        $override = $this->commission->setRate(1, ['profile_id' => 11, 'service_id' => $this->decorating, 'percent' => '12,5%'], 11);
        self::assertSame(1250, $override['percent_bp']);
        self::assertSame('12.5%', $override['percent']);

        // Setting it again changes it rather than adding another.
        $this->commission->setRate(1, ['profile_id' => 11, 'percent' => 40], 11);
        $rates = $this->commission->rates(1, 'ar');
        self::assertCount(2, $rates);
        self::assertSame(['40%', '12.5%'], array_column($rates, 'percent'));
        self::assertSame('كل الخدمات', $rates[0]['service']);

        self::assertSame([], $this->commission->rates(2));
        $this->expectException(CalendarRejectedException::class);
        $this->commission->removeRate(2, $rates[0]['id']);
    }

    public function testRatesRefuseWhatIsNotAPercentageOrNotHere(): void
    {
        foreach ([
            ['profile_id' => 11, 'percent' => '101'],
            ['profile_id' => 11, 'percent' => 'thirty'],
            ['profile_id' => 21, 'percent' => '30'],
            ['profile_id' => 11, 'service_id' => 999, 'percent' => '30'],
        ] as $input) {
            try {
                $this->commission->setRate(1, $input, 11);
                self::fail('Accepted ' . json_encode($input));
            } catch (CalendarRejectedException $e) {
                self::assertSame(422, $e->status);
            }
        }
    }

    public function testReportCountsDoneBookingsLineByLine(): void
    {
        $this->commission->setRate(1, ['profile_id' => 11, 'percent' => '30'], 11);
        $this->commission->setRate(1, ['profile_id' => 11, 'service_id' => $this->decorating, 'percent' => '10'], 11);

        // Done this month: 11 does a tasting (JOD 10.000), and a booking of
        // two where 11 tastes and 12 decorates.
        $this->booking(11, $this->tasting, '2026-11-02 08:00:00', '2026-11-02 08:30:00', 10000);
        $two = $this->booking(11, $this->tasting, '2026-11-03 08:00:00', '2026-11-03 09:00:00', 30000);
        $this->line($two, 0, 11, $this->tasting, 10000);
        $this->line($two, 1, 12, $this->decorating, 20000);
        // Not counted: still on, cancelled, last month, another workspace's.
        $this->booking(11, $this->tasting, '2026-11-18 11:45:00', '2026-11-18 12:15:00', 10000);
        $this->booking(11, $this->tasting, '2026-11-04 08:00:00', '2026-11-04 08:30:00', 10000, 'cancelled');
        $this->booking(11, $this->tasting, '2026-10-30 08:00:00', '2026-10-30 08:30:00', 10000);

        $rows = $this->commission->report(1, 'this_month');
        self::assertCount(2, $rows);
        [$eleven, $twelve] = $rows;
        self::assertSame(11, $eleven['profile_id']);
        self::assertSame(2, $eleven['bookings']);
        self::assertSame(20000, $eleven['sales_minor']);
        self::assertSame(6000, $eleven['commission_minor']);
        self::assertSame('JOD 6.000', $eleven['commission']);
        self::assertSame(12, $twelve['profile_id']);
        self::assertSame(20000, $twelve['sales_minor']);
        self::assertFalse($twelve['rated']);
        self::assertSame('No rate set', $twelve['commission']);

        $last = $this->commission->report(1, 'last_month');
        self::assertSame(1, $last[0]['bookings']);
        self::assertSame([], $this->commission->report(2, 'this_month'));
    }

    public function testPeriodsAreInTheWorkspacesZoneAndWeek(): void
    {
        // A booking at 23:30 on 31 October in Amman is November's in UTC but
        // October's here.
        $this->booking(11, $this->tasting, '2026-10-31 20:30:00', '2026-10-31 21:00:00', 10000);
        self::assertSame([], $this->commission->report(1, 'this_month'));
        self::assertSame(1, $this->commission->report(1, 'last_month')[0]['bookings']);

        $periods = array_column($this->commission->periods(1), null, 'id');
        self::assertSame('2026-11-01', $periods['this_month']['from']);
        self::assertSame('2026-11-30', $periods['this_month']['to']);
        // The week starts on Saturday by default: Saturday 14 November.
        self::assertSame('2026-11-14', $periods['this_week']['from']);
        self::assertSame('2026-11-12', $periods['last_7_days']['from']);

        $this->expectException(CalendarRejectedException::class);
        $this->commission->report(1, 'forever');
    }

    private function service(string $name, int $price): int
    {
        $id = $this->services->insert(1, [
            'name' => ['en' => $name], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => $price, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->services->replaceStaff(1, $id, [11, 12]);

        return $id;
    }

    private function booking(int $profile, int $service, string $starts, string $ends, int $price, string $status = 'confirmed'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO calendar_bookings (tenant_id, service_id, profile_id, starts_at, ends_at, blocks_from, blocks_until, status,
                    customer_name, customer_email, customer_phone, customer_note, source, price_minor, currency, created_at, updated_at)
             VALUES (1, :service, :profile, :starts, :ends, :starts, :ends, :status, 'Lina', '', '', '', 'staff', :price, 'JOD', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([':service' => $service, ':profile' => $profile, ':starts' => $starts, ':ends' => $ends, ':status' => $status, ':price' => $price]);

        return (int) $this->pdo->lastInsertId();
    }

    private function line(int $booking, int $position, int $profile, int $service, int $price): void
    {
        $this->pdo->prepare(
            'INSERT INTO calendar_booking_services (tenant_id, booking_id, service_id, profile_id, position, starts_at, ends_at, blocks_from, blocks_until, price_minor, created_at)
             VALUES (1, :b, :s, :p, :pos, :at, :at, :at, :at, :price, CURRENT_TIMESTAMP)'
        )->execute([':b' => $booking, ':s' => $service, ':p' => $profile, ':pos' => $position, ':at' => '2026-11-03 08:00:00', ':price' => $price]);
    }
}
