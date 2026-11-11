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
use Whity\Core\Calendar\ExternalBusySource;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Usage\CustomerUsage;

/**
 * Bookings against the real schema (WHIT-941, ADR 0017): the rules, tenant
 * isolation, plan counting, and on Postgres the per-person lock that stops
 * two requests booking the same time.
 *
 * Example Bakery (tenant 1) has two bakers, 11 and 12, working Sunday to
 * Thursday 09:00-17:00 in Amman. Tenant 2 has its own baker, 21.
 */
final class BookingRealEngineTest extends TestCase
{
    private PDO $pdo;
    private SettingsService $settings;
    private BookingService $service;
    private CalendarRepository $calendar;
    private ServiceRepository $services;
    private BookingRepository $bookings;
    private int $cake;

    /** Sunday 15 November 2026, 07:00 in Amman. */
    private const NOW = '2026-11-15 04:00:00';

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 12 => 1, 21 => 2] as $profile => $tenant) {
            $this->person($profile);
            $this->membership($profile, $tenant, 'member');
        }
        $this->person(31);
        $this->membership(31, 1, 'customer');

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        foreach ([1, 2] as $tenant) {
            $this->settings->setTenant($tenant, 'timezone', 'Asia/Amman');
            $this->settings->setTenant($tenant, 'calendar.min_notice_minutes', '60');
            $this->settings->setTenant($tenant, 'calendar.slot_step_minutes', '30');
        }

        $this->service = $this->bookingService($this->pdo);
        $this->cake = $this->services->insert(1, [
            'name' => ['en' => 'Cake tasting', 'ar' => 'تذوق الكعك'], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 15,
            'price_minor' => 12500, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->services->replaceStaff(1, $this->cake, [11, 12]);
        foreach ([11, 12] as $baker) {
            $this->calendar->ensureStaff(1, $baker);
            $this->calendar->replaceHours(1, $baker, array_map(
                static fn (int $day): array => ['weekday' => $day, 'start_time' => '09:00', 'end_time' => '17:00'],
                [7, 1, 2, 3, 4],
            ));
        }
    }

    private function bookingService(PDO $pdo): BookingService
    {
        $this->calendar = new CalendarRepository($pdo);
        $this->services = new ServiceRepository($pdo);
        $this->bookings = new BookingRepository($pdo);
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));

        return new BookingService($pdo, $this->calendar, $this->services, $this->bookings, $settings, null, clock: static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
    }

    private static function at(string $iso): DateTimeImmutable
    {
        return Interval::fromApi($iso) ?? throw new \LogicException('bad instant ' . $iso);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{service_id: int, profile_id: ?int, starts_at: DateTimeImmutable, customer_profile_id: ?int, customer_name: string, customer_email: string, customer_phone: string, customer_note: string, source: string, created_by_profile_id: ?int, enforce_hours: bool, public: bool}
     */
    private function request(string $startsAt, ?int $profileId = null, array $overrides = []): array
    {
        /** @var array{service_id: int, profile_id: ?int, starts_at: DateTimeImmutable, customer_profile_id: ?int, customer_name: string, customer_email: string, customer_phone: string, customer_note: string, source: string, created_by_profile_id: ?int, enforce_hours: bool, public: bool} $request */
        $request = $overrides + [
            'service_id' => $this->cake,
            'profile_id' => $profileId,
            'starts_at' => self::at($startsAt),
            'customer_profile_id' => null,
            'customer_name' => 'Lina',
            'customer_email' => 'lina@example.com',
            'customer_phone' => '',
            'customer_note' => '',
            'source' => BookingRepository::SOURCE_PUBLIC,
            'created_by_profile_id' => null,
            'enforce_hours' => true,
            'public' => true,
        ];

        return $request;
    }

    public function testAvailabilityIsWorkingHoursInTheWorkspaceZone(): void
    {
        $slots = $this->service->availability(1, $this->cake, 11, self::at('2026-11-15T00:00:00+03:00'), self::at('2026-11-16T00:00:00+03:00'));
        // 09:00 Amman is 06:00Z; the last 30-minute slot starts at 16:30 (13:30Z).
        self::assertSame('2026-11-15T06:00:00Z', $slots[0]['starts_at']);
        self::assertSame('2026-11-15T13:30:00Z', $slots[count($slots) - 1]['starts_at']);
        self::assertSame([11], $slots[0]['profile_ids']);

        // Friday is not a working day.
        self::assertSame([], $this->service->availability(1, $this->cake, null, self::at('2026-11-20T00:00:00+03:00'), self::at('2026-11-21T00:00:00+03:00')));
    }

    public function testAnyoneFillsEachPersonThenRefusesWithTaken(): void
    {
        $first = $this->service->book(1, $this->request('2026-11-16T10:00:00+03:00'));
        $second = $this->service->book(1, $this->request('2026-11-16T10:00:00+03:00'));
        self::assertSame(11, $first['profile_id']);
        self::assertSame(12, $second['profile_id']);
        self::assertSame('2026-11-16T07:45:00Z', $first['blocks_until'], 'the 15-minute buffer after is held');

        try {
            $this->service->book(1, $this->request('2026-11-16T10:00:00+03:00'));
            self::fail('a third booking at a full time must be refused');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }

        // The buffer keeps 10:30 free but 10:15 is refused for the same baker.
        $this->expectExceptionObject(CalendarRejectedException::taken());
        $this->service->book(1, $this->request('2026-11-16T10:15:00+03:00', 11, ['enforce_hours' => false, 'public' => false]));
    }

    public function testPublicKeepsToHoursAndWindowButTheDeskMayNot(): void
    {
        try {
            $this->service->book(1, $this->request('2026-11-16T18:00:00+03:00', 11));
            self::fail('outside working hours must be refused for a public booking');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        try {
            $this->service->book(1, $this->request('2026-11-15T07:30:00+03:00', 11));
            self::fail('inside the minimum notice must be refused');
        } catch (CalendarRejectedException $e) {
            self::assertSame(422, $e->status);
        }
        $desk = $this->service->book(1, $this->request('2026-11-16T18:00:00+03:00', 11, ['enforce_hours' => false, 'public' => false, 'source' => 'staff']));
        self::assertSame('confirmed', $desk['status']);
    }

    public function testMoveAndCancelFreeTheOldTime(): void
    {
        $a = $this->service->book(1, $this->request('2026-11-16T10:00:00+03:00', 11));
        $b = $this->service->book(1, $this->request('2026-11-16T11:00:00+03:00', 11));

        try {
            $this->service->move(1, $b['id'], self::at('2026-11-16T10:00:00+03:00'), null, true);
            self::fail('moving onto a booked time must be refused');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        // Moving a booking onto its own time (or overlapping itself) is fine.
        $moved = $this->service->move(1, $a['id'], self::at('2026-11-16T10:15:00+03:00'), null, true);
        self::assertSame('2026-11-16T07:15:00Z', $moved['starts_at']);

        $this->service->cancel(1, $moved['id'], 'staff', 'Ill');
        $again = $this->service->book(1, $this->request('2026-11-16T10:00:00+03:00', 11));
        self::assertSame(11, $again['profile_id']);
    }

    public function testBusyEventsAndExternalBusyBlockAndAFailingSourceFailsOpen(): void
    {
        $this->service->createEvent(1, 11, 'Oven service', '', new Interval(self::at('2026-11-16T09:00:00+03:00'), self::at('2026-11-16T12:00:00+03:00')), false, true, 11);
        $slots = $this->service->availability(1, $this->cake, 11, self::at('2026-11-16T09:00:00+03:00'), self::at('2026-11-16T12:30:00+03:00'));
        self::assertSame(['2026-11-16T09:00:00Z'], array_column($slots, 'starts_at'));

        $this->service->addBusySource(new class implements ExternalBusySource {
            public function busyTimes(int $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
            {
                return $profileId === 12
                    ? [new Interval(new DateTimeImmutable('2026-11-16 06:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-11-16 14:00:00', new DateTimeZone('UTC')))]
                    : [];
            }
        });
        $this->service->addBusySource(new class implements ExternalBusySource {
            public function busyTimes(int $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
            {
                throw new \RuntimeException('provider down');
            }
        });
        self::assertSame([], $this->service->availability(1, $this->cake, 12, self::at('2026-11-16T00:00:00+03:00'), self::at('2026-11-17T00:00:00+03:00')));
        self::assertNotSame([], $this->service->availability(1, $this->cake, 11, self::at('2026-11-16T12:00:00+03:00'), self::at('2026-11-17T00:00:00+03:00')));
    }

    public function testAnotherWorkspacesServiceAndStaffAreInvisible(): void
    {
        try {
            $this->service->book(2, $this->request('2026-11-16T10:00:00+03:00'));
            self::fail('tenant 2 must not see tenant 1\'s service');
        } catch (CalendarRejectedException $e) {
            self::assertSame(404, $e->status);
        }

        // Tenant 1 naming tenant 2's baker finds nobody to book.
        $this->services->replaceStaff(1, $this->cake, [11, 12, 21]);
        $this->expectException(CalendarRejectedException::class);
        $this->service->book(1, $this->request('2026-11-16T10:00:00+03:00', 21, ['enforce_hours' => false, 'public' => false]));
    }

    public function testConfirmedBookingsCountCustomersOncePerPersonAndNeverStaff(): void
    {
        $usage = new CustomerUsage($this->pdo, new EntitlementService(new TenantEntitlementRepository($this->pdo)), $this->settings);
        $now = new DateTimeImmutable('2026-11-15 10:00:00', new DateTimeZone('UTC'));
        foreach ([
            $this->service->book(1, $this->request('2026-11-16T10:00:00+03:00', 11)),
            $this->service->book(1, $this->request('2026-11-16T12:00:00+03:00', 11, ['customer_email' => 'LINA@example.com'])),
            $this->service->book(1, $this->request('2026-11-16T13:00:00+03:00', 11, ['customer_profile_id' => 31])),
            $this->service->book(1, $this->request('2026-11-16T14:00:00+03:00', 11, ['customer_profile_id' => 12])),
        ] as $booking) {
            $usage->onBookingConfirmed(['id' => $booking['id'], 'tenant_id' => 1]);
        }
        // Lina (twice, by email) and customer 31; baker 12 is staff.
        self::assertSame(2, $usage->count(1, $now));
    }

    public function testThePersonsLockSerialisesTwoConnections(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('The row lock is a Postgres behaviour; SQLite serialises writers by itself.');
        }
        $other = $this->secondConnection();
        $otherService = $this->bookingService($other);

        // Connection A holds baker 11's lock with a booking not yet committed.
        $this->pdo->beginTransaction();
        $this->calendar = new CalendarRepository($this->pdo);
        $this->calendar->lockStaff(1, 11);

        // Connection B, asking for the same person, waits rather than reading
        // a stale calendar: with a short lock timeout it gives up instead.
        $other->exec("SET lock_timeout = '300ms'");
        try {
            $otherService->book(1, $this->request('2026-11-16T10:00:00+03:00', 11));
            self::fail('B must wait for A\'s lock');
        } catch (\PDOException $e) {
            self::assertStringContainsString('lock', strtolower($e->getMessage()));
        }
        // A different person is not held up.
        $b = $otherService->book(1, $this->request('2026-11-16T10:00:00+03:00', 12));
        self::assertSame(12, $b['profile_id']);

        $this->pdo->rollBack();
    }

    public function testParallelRequestsForOneTimeMakeExactlyOneBooking(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('Needs separate Postgres connections.');
        }
        [$dsn, $user, $password, $schema] = $this->connectionParts();
        $script = __DIR__ . '/fixtures/book-once.php';
        $procs = [];
        for ($i = 0; $i < 8; $i++) {
            $procs[] = proc_open(
                [PHP_BINARY, $script, $dsn, $user, $password, $schema, (string) $this->cake, self::NOW],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
            );
        }
        $outcomes = [];
        foreach ($procs as $i => $proc) {
            $outcomes[] = trim((string) stream_get_contents($pipes[$i][1])) . trim((string) stream_get_contents($pipes[$i][2]));
            if (is_resource($proc)) {
                proc_close($proc);
            }
        }
        sort($outcomes);
        self::assertSame(1, count(array_filter($outcomes, static fn (string $o): bool => $o === 'booked')), implode(' | ', $outcomes));
        self::assertSame(7, count(array_filter($outcomes, static fn (string $o): bool => $o === 'taken')), implode(' | ', $outcomes));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM calendar_bookings WHERE tenant_id = 1 AND status = 'confirmed'"));
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function connectionParts(): array
    {
        $base = (string) ($_ENV['PHPUNIT_PG_DSN'] ?? getenv('PHPUNIT_PG_DSN'));
        $db = (string) $this->scalar('SELECT current_database()');
        $schema = (string) $this->scalar('SELECT current_schema()');
        $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $db, $base) ?? $base;
        $user = (string) ($_ENV['PHPUNIT_PG_USER'] ?? getenv('PHPUNIT_PG_USER') ?: 'whity');
        $password = (string) ($_ENV['PHPUNIT_PG_PASSWORD'] ?? getenv('PHPUNIT_PG_PASSWORD') ?: 'whity_dev');

        return [$dsn, $user, $password, $schema];
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    private function secondConnection(): PDO
    {
        [$dsn, $user, $password, $schema] = $this->connectionParts();
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET search_path TO ' . $schema . ', public');

        return $pdo;
    }

    private function person(int $id): void
    {
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ({$id}, 'p{$id}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
    }

    private function membership(int $profileId, int $tenantId, string $kind): void
    {
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
             VALUES ({$profileId}, {$tenantId}, 901, 'active', '{$kind}', CURRENT_TIMESTAMP)"
        );
    }
}
