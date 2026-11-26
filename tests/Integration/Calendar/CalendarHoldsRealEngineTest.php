<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\CreateCalendarHolds;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\Availability;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarPlugins;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\HoldConflicts;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\RoomRepository;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;
use Whity\Sdk\Calendar\BookingPaymentStep;
use Whity\Sdk\Calendar\BookingQuote;
use Whity\Sdk\Calendar\PaymentHandoff;
use Whity\Sdk\Calendar\PaymentRequirement;
use Whity\Sdk\Calendar\PendingBooking;

/**
 * The database refuses double bookings (WHIT-1412, migration 360, design
 * section 39 §5.1, AP.11–AP.13).
 *
 * Example Bakery (tenant 1): bakers 11 and 12, Monday 09:00–17:00 in Amman,
 * a 30-minute cake tasting in the tasting room. Every check that matters here
 * is PostgreSQL's: the holds are written by triggers and refused by an
 * exclusion constraint, so on SQLite these tests are skipped.
 */
final class CalendarHoldsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private BookingRepository $bookings;
    private CalendarPlugins $plugins;
    private DateTimeImmutable $now;
    private int $cake;
    private int $room;

    /** Monday 16 November 2026, 10:00 in Amman. */
    private const MONDAY_10 = '2026-11-16T10:00:00+03:00';

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('The holds and their constraint are PostgreSQL\'s; SQLite serialises writers by itself.');
        }
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 12 => 1, 21 => 2] as $profile => $tenant) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, 'p{$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setTenant(1, 'timezone', 'Asia/Amman');
        $settings->setTenant(1, 'calendar.min_notice_minutes', '0');
        $settings->setTenant(1, 'calendar.payment_hold_minutes', '15');

        $this->now = new DateTimeImmutable('2026-11-15 04:00:00', new DateTimeZone('UTC'));
        $calendar = new CalendarRepository($this->pdo);
        $services = new ServiceRepository($this->pdo);
        $this->bookings = new BookingRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $calendar, $services, $this->bookings, $settings, null, clock: fn (): DateTimeImmutable => $this->now);
        $this->plugins = new CalendarPlugins();
        $this->service->setPlugins($this->plugins);

        $this->room = (new RoomRepository($this->pdo))->insert(1, ['name' => ['en' => 'Tasting room'], 'unit_id' => null, 'active' => true, 'sort_order' => 0]);
        $this->cake = $services->insert(1, [
            'name' => ['en' => 'Cake tasting'], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 15,
            'price_minor' => 12500, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
            'room_id' => $this->room,
        ]);
        $services->replaceStaff(1, $this->cake, [11, 12]);
        foreach ([11, 12] as $baker) {
            $calendar->ensureStaff(1, $baker);
            $calendar->replaceHours(1, $baker, [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']]);
        }
    }

    public function testTheLiveStatusesMatchTheBookingRepository(): void
    {
        $quoted = array_map(static fn (string $s): string => "'" . $s . "'", BookingRepository::LIVE);
        self::assertSame('(' . implode(', ', $quoted) . ')', CreateCalendarHolds::LIVE_SQL);
        self::assertSame(HoldConflicts::CONSTRAINT, CreateCalendarHolds::CONSTRAINT);
    }

    public function testABookingHoldsItsPersonAndRoomWithBuffersAndCancellingFreesThem(): void
    {
        $booking = $this->service->book(1, $this->staffRequest(self::MONDAY_10, 11));

        $holds = $this->holds((int) $booking['id']);
        self::assertCount(2, $holds);
        self::assertSame(['person', 11, '2026-11-16 07:00:00', '2026-11-16 07:45:00', true], $holds[0], 'The person, with the 15-minute buffer after.');
        self::assertSame(['resource', $this->room, '2026-11-16 07:00:00', '2026-11-16 07:45:00', true], $holds[1]);

        $this->service->cancel(1, (int) $booking['id'], 'staff', 'Changed plans');
        self::assertSame([], $this->holds((int) $booking['id']));
    }

    public function testAMoveRewritesTheHolds(): void
    {
        $booking = $this->service->book(1, $this->staffRequest(self::MONDAY_10, 11));
        $this->service->move(1, (int) $booking['id'], self::at('2026-11-16T12:00:00+03:00'), 12, false, false);

        $holds = $this->holds((int) $booking['id']);
        self::assertSame(['person', 12, '2026-11-16 09:00:00', '2026-11-16 09:45:00', true], $holds[0]);
    }

    /**
     * THE GUARD CAN FAIL: a booking written straight into the table, past
     * BookingService's locks and checks, is refused by the database itself.
     */
    public function testAnOverlapWrittenPastTheServiceIsRefusedByTheDatabase(): void
    {
        $this->service->book(1, $this->staffRequest(self::MONDAY_10, 11));

        try {
            $this->insertDirectly(11, '2026-11-16 07:30:00', null);
            self::fail('The database let a second booking take the same person at the same time.');
        } catch (PDOException $e) {
            self::assertSame(HoldConflicts::SQLSTATE, $e->errorInfo[0] ?? null);
            self::assertStringContainsString(HoldConflicts::CONSTRAINT, $e->getMessage());
            $rejected = HoldConflicts::fromPdo($e);
            self::assertNotNull($rejected);
            self::assertSame(409, $rejected->status);
            self::assertSame(CalendarRejectedException::PERSON_BUSY, $rejected->extra['reason']);
            self::assertTrue(HoldConflicts::isHeld($rejected));
        }
        self::assertSame(1, $this->liveCount(), 'Nothing of the refused booking stays.');

        // The buffer counts: 07:45 is free, 07:44 is not.
        $this->insertDirectly(11, '2026-11-16 07:45:00', null);
        self::assertSame(2, $this->liveCount());
    }

    public function testTheRoomIsGuardedAcrossPeople(): void
    {
        $this->service->book(1, $this->staffRequest(self::MONDAY_10, 11));

        try {
            $this->insertDirectly(12, '2026-11-16 07:15:00', $this->room);
            self::fail('The database let two bookings take the same room.');
        } catch (PDOException $e) {
            self::assertSame(CalendarRejectedException::ROOM_TAKEN, HoldConflicts::fromPdo($e)?->extra['reason']);
        }
    }

    public function testOtherWorkspacesAndOtherPeopleNeverClash(): void
    {
        $this->service->book(1, $this->staffRequest(self::MONDAY_10, 11));
        $this->insertDirectly(12, '2026-11-16 07:00:00', null);
        $this->pdo->exec("INSERT INTO calendar_services (id, tenant_id, name, description, duration_minutes, buffer_before_minutes, buffer_after_minutes, currency, bookable_online, active, sort_order, created_at, updated_at)
            VALUES (9001, 2, '{\"en\":\"Bread\"}', '[]', 30, 0, 0, 'JOD', true, true, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->insertDirectly(21, '2026-11-16 07:00:00', null, 2, 9001);
        self::assertSame(3, $this->liveCount() + $this->liveCount(2));
    }

    public function testBookAnywayLetsThePersonOverlapButNeverTheRoom(): void
    {
        $first = $this->service->book(1, $this->staffRequest(self::MONDAY_10, 11));
        // Clear the room from the service, so only the person is busy.
        $this->pdo->exec('UPDATE calendar_services SET room_id = NULL WHERE id = ' . $this->cake);

        $anyway = $this->service->book(1, $this->staffRequest(self::MONDAY_10, 11, ['book_anyway' => true]));
        $holds = $this->holds((int) $anyway['id']);
        self::assertSame(['person', 11, '2026-11-16 07:00:00', '2026-11-16 07:45:00', false], $holds[0], 'Not exclusive: staff chose Book anyway.');

        // Book anyway over a FREE person stays exclusive.
        $free = $this->service->book(1, $this->staffRequest('2026-11-16T13:00:00+03:00', 11, ['book_anyway' => true]));
        self::assertTrue($this->holds((int) $free['id'])[0][4]);

        // The room of the first booking is still guarded, whoever writes.
        try {
            $this->insertDirectly(12, '2026-11-16 07:00:00', $this->room);
            self::fail('Book anyway must never let a room be taken twice.');
        } catch (PDOException $e) {
            self::assertSame(CalendarRejectedException::ROOM_TAKEN, HoldConflicts::fromPdo($e)?->extra['reason']);
        }
        self::assertSame(3, $this->liveCount());
        self::assertNotSame($first['id'], $anyway['id']);
    }

    public function testEachPartOfABookingOfSeveralHoldsItsOwnPerson(): void
    {
        $this->pdo->beginTransaction();
        $id = $this->insertDirectly(11, '2026-11-16 07:00:00', null, 1, null, false);
        $this->bookings->insertItems(1, $id, [
            $this->part(11, '2026-11-16 07:00:00', 30, null),
            $this->part(12, '2026-11-16 07:30:00', 30, $this->room),
        ]);
        $this->pdo->commit();

        self::assertSame([
            ['person', 11, '2026-11-16 07:00:00', '2026-11-16 07:30:00', true],
            ['person', 12, '2026-11-16 07:30:00', '2026-11-16 08:00:00', true],
            ['resource', $this->room, '2026-11-16 07:30:00', '2026-11-16 08:00:00', true],
        ], $this->holds($id));

        // Baker 12 is busy 07:30–08:00 through the second part only.
        try {
            $this->insertDirectly(12, '2026-11-16 07:45:00', null);
            self::fail('The part\'s person was double booked.');
        } catch (PDOException $e) {
            self::assertNotNull(HoldConflicts::fromPdo($e));
        }
    }

    public function testTheServiceTurnsTheDatabasesRefusalIntoAConflict(): void
    {
        // Someone wrote a booking past the service AND past the service's
        // busy read: a booking row the service can't see as busy (cancelled)
        // whose holds are still there is the closest a test gets to a writer
        // that skipped the locks. Write the holds row by hand.
        $this->pdo->exec("INSERT INTO calendar_bookings (tenant_id, service_id, profile_id, customer_name, customer_email, customer_phone, customer_note,
                starts_at, ends_at, blocks_from, blocks_until, status, source, currency, created_at, updated_at)
            VALUES (1, {$this->cake}, 11, 'Ghost', 'g@example.com', '', '', '2026-11-16 07:00:00', '2026-11-16 07:30:00',
                '2026-11-16 07:00:00', '2026-11-16 07:45:00', 'cancelled', 'staff', 'JOD', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ghost = (int) $this->scalar('SELECT MAX(id) FROM calendar_bookings');
        $this->pdo->exec("INSERT INTO calendar_holds (tenant_id, booking_id, holder_kind, holder_id, holds_from, holds_until, exclusive)
            VALUES (1, {$ghost}, 'person', 11, '2026-11-16 07:00:00', '2026-11-16 07:45:00', true)");

        try {
            $this->service->book(1, $this->staffRequest(self::MONDAY_10, 11));
            self::fail('The service must not book over a hold the database keeps.');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(0, $this->liveCount());

        // "Anyone" moves on to the next baker instead.
        $booking = $this->service->book(1, $this->staffRequest(self::MONDAY_10, null));
        self::assertSame(12, (int) $booking['profile_id']);
    }

    public function testALapsedPaymentHoldIsExpiredBeforeItsTimeIsBookedAgain(): void
    {
        $this->deposit();
        $held = $this->service->book(1, $this->publicRequest(self::MONDAY_10, 11));
        self::assertSame(BookingRepository::STATUS_PENDING_PAYMENT, $held['status']);
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM calendar_holds WHERE tenant_id = 1 AND expires_at IS NOT NULL AND booking_id = ' . (int) $held['id']), 'The person and the room carry the hold expiry.');

        // Sixteen minutes later nobody has paid.
        $this->now = $this->now->modify('+16 minutes');
        $this->plugins = new CalendarPlugins();
        $this->service->setPlugins($this->plugins);
        $again = $this->service->book(1, $this->staffRequest(self::MONDAY_10, 11));

        self::assertSame(BookingRepository::STATUS_CONFIRMED, $again['status']);
        self::assertSame(BookingRepository::STATUS_EXPIRED, $this->bookings->find(1, (int) $held['id'])['status'] ?? null);
        self::assertSame([], $this->holds((int) $held['id']));

        // The late payment then loses: its time is taken.
        self::assertFalse($this->service->confirmPaid(1, (int) $held['id'], 'pay_late'));
    }

    public function testTheMigrationKeepsOverlapsThatWereAlreadyThereAndListsThem(): void
    {
        $db = $this->database();
        CreateCalendarHolds::down($db);
        // Two bookings of the same person and room at the same time, made before the guard.
        $first = $this->insertBeforeTheGuard(11, '2026-11-16 07:00:00', $this->room);
        $second = $this->insertBeforeTheGuard(11, '2026-11-16 07:15:00', $this->room);
        $later = $this->insertBeforeTheGuard(12, '2026-11-16 09:00:00', null);

        CreateCalendarHolds::up($db);
        CreateCalendarHolds::up($db); // twice: idempotent

        self::assertSame([false, true, false], array_map(fn (int $id): bool => $this->beforeGuard($id), [$first, $second, $later]));
        self::assertTrue($this->holds($first)[0][4], 'The earlier booking keeps its guard.');
        self::assertFalse($this->holds($second)[0][4]);
        self::assertFalse($this->holds($second)[1][4], 'Even its room: it already overlapped.');

        $overlaps = HoldConflicts::overlaps($this->pdo, 1, new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')));
        self::assertCount(2, $overlaps, 'Person and room.');
        self::assertSame([$second, $first, true], [$overlaps[0]['booking_id'], $overlaps[0]['other_booking_id'], $overlaps[0]['before_guard']]);
        self::assertSame([], HoldConflicts::overlaps($this->pdo, 2, new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'))));

        // From here on, a new overlap is refused.
        try {
            $this->insertDirectly(12, '2026-11-16 07:00:00', $this->room);
            self::fail('The guard is on again after the migration.');
        } catch (PDOException $e) {
            self::assertNotNull(HoldConflicts::fromPdo($e));
        }
    }

    public function testDownRemovesEverythingItAdded(): void
    {
        $db = $this->database();
        // Rolled back in order: 366 (WHIT-1439) builds on these holds.
        require_once dirname(__DIR__, 3) . '/database/migrations/366_create_calendar_booking_resources.php';
        \Database\Migrations\CreateCalendarBookingResources::down($db);
        CreateCalendarHolds::down($db);
        self::assertFalse((bool) $this->scalar("SELECT to_regclass('calendar_holds') IS NOT NULL"));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'calendar_bookings' AND column_name IN ('overlap_ok', 'overlap_before_guard')"));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM pg_proc WHERE proname LIKE 'whity_calendar_holds%'"));
        CreateCalendarHolds::up($db);
        self::assertTrue((bool) $this->scalar("SELECT to_regclass('calendar_holds') IS NOT NULL"));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private static function at(string $iso): DateTimeImmutable
    {
        return Interval::fromApi($iso) ?? throw new \LogicException('bad instant ' . $iso);
    }

    /**
     * @param array{book_anyway?: bool} $extra
     * @return array{service_id: int, profile_id: ?int, starts_at: DateTimeImmutable, customer_profile_id: ?int, customer_name: string, customer_email: string, customer_phone: string, customer_note: string, source: string, created_by_profile_id: ?int, enforce_hours: bool, public: bool, notify: bool, book_anyway?: bool}
     */
    private function staffRequest(string $startsAt, ?int $profileId, array $extra = []): array
    {
        return $extra + [
            'service_id' => $this->cake,
            'profile_id' => $profileId,
            'starts_at' => self::at($startsAt),
            'customer_profile_id' => null,
            'customer_name' => 'Lina',
            'customer_email' => 'lina@example.com',
            'customer_phone' => '',
            'customer_note' => '',
            'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => 11,
            'enforce_hours' => false,
            'public' => false,
            'notify' => false,
        ];
    }

    /** @return array{service_id: int, profile_id: ?int, starts_at: DateTimeImmutable, customer_profile_id: ?int, customer_name: string, customer_email: string, customer_phone: string, customer_note: string, source: string, created_by_profile_id: ?int, enforce_hours: bool, public: bool, notify: bool, book_anyway?: bool} */
    private function publicRequest(string $startsAt, ?int $profileId): array
    {
        $request = $this->staffRequest($startsAt, $profileId);
        $request['source'] = BookingRepository::SOURCE_PUBLIC;
        $request['created_by_profile_id'] = null;
        $request['enforce_hours'] = true;
        $request['public'] = true;

        return $request;
    }

    private function deposit(): void
    {
        $this->plugins->offerPaymentStep('WhityPay', new class implements BookingPaymentStep {
            public function requirement(BookingQuote $quote): ?PaymentRequirement
            {
                return $quote->priceMinor !== null ? new PaymentRequirement(2500, $quote->currency, 'Deposit') : null;
            }

            public function start(PendingBooking $booking, string $returnUrl): PaymentHandoff
            {
                return new PaymentHandoff('https://pay.example.com/checkout/' . $booking->bookingId);
            }
        });
    }

    /**
     * A live booking written straight through the repository, with no lock
     * and no check: what a careless writer would do. Autocommit, so the
     * deferred constraint is checked at once.
     */
    private function insertDirectly(int $profileId, string $startUtc, ?int $roomId, int $tenantId = 1, ?int $serviceId = null, bool $autocommit = true, int $minutes = 30): int
    {
        $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
        $buffer = $serviceId === null ? 15 : 0;

        return $this->bookings->insert($tenantId, [
            'service_id' => $serviceId ?? $this->cake,
            'profile_id' => $profileId,
            'customer_profile_id' => null,
            'customer_name' => 'Direct',
            'customer_email' => 'direct@example.com',
            'customer_phone' => '',
            'customer_note' => '',
            'starts_at' => $start,
            'ends_at' => $start->modify('+' . $minutes . ' minutes'),
            'block' => Availability::blockFor($start, $minutes, 0, $buffer),
            'status' => BookingRepository::STATUS_CONFIRMED,
            'hold_expires_at' => null,
            'source' => BookingRepository::SOURCE_STAFF,
            'price_minor' => null,
            'currency' => 'JOD',
            'reminder_due_at' => null,
            'created_by_profile_id' => null,
            'room_id' => $roomId,
        ]);
    }

    /** A live booking as the table was before migration 360 (no overlap columns). */
    private function insertBeforeTheGuard(int $profileId, string $startUtc, ?int $roomId): int
    {
        $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO calendar_bookings (tenant_id, service_id, profile_id, customer_name, customer_email, customer_phone, customer_note,
                    starts_at, ends_at, blocks_from, blocks_until, status, source, currency, room_id, created_at, updated_at)
             VALUES (1, :service, :profile, 'Before', 'before@example.com', '', '', :starts, :ends, :starts, :until, 'confirmed', 'staff', 'JOD', :room, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             RETURNING id"
        );
        $stmt->bindValue(':service', $this->cake, PDO::PARAM_INT);
        $stmt->bindValue(':profile', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':starts', Interval::toDb($start));
        $stmt->bindValue(':ends', Interval::toDb($start->modify('+30 minutes')));
        $stmt->bindValue(':until', Interval::toDb($start->modify('+45 minutes')));
        $stmt->bindValue(':room', $roomId, $roomId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array{service_id: int, profile_id: int, room_id: ?int, starts_at: DateTimeImmutable, ends_at: DateTimeImmutable, block: Interval, price_minor: ?int} */
    private function part(int $profileId, string $startUtc, int $minutes, ?int $roomId): array
    {
        $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));

        return [
            'service_id' => $this->cake,
            'profile_id' => $profileId,
            'room_id' => $roomId,
            'starts_at' => $start,
            'ends_at' => $start->modify('+' . $minutes . ' minutes'),
            'block' => Availability::blockFor($start, $minutes, 0, 0),
            'price_minor' => null,
        ];
    }

    /** @return list<array{0: string, 1: int, 2: string, 3: string, 4: bool}> */
    private function holds(int $bookingId): array
    {
        $stmt = $this->pdo->query(
            'SELECT holder_kind, holder_id, holds_from, holds_until, exclusive FROM calendar_holds
              WHERE tenant_id = 1 AND booking_id = ' . $bookingId . ' ORDER BY holder_kind, holds_from, holder_id'
        );
        self::assertNotFalse($stmt);
        /** @var list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

        return array_map(static fn (array $r): array => [(string) $r[0], (int) $r[1], (string) $r[2], (string) $r[3], \Whity\Core\Db\DbBool::of($r[4])], $rows);
    }

    private function liveCount(int $tenantId = 1): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM calendar_bookings WHERE tenant_id = {$tenantId} AND status IN ('confirmed', 'pending_payment', 'requested')");
    }

    private function beforeGuard(int $bookingId): bool
    {
        return \Whity\Core\Db\DbBool::of($this->scalar('SELECT overlap_before_guard FROM calendar_bookings WHERE tenant_id = 1 AND id = ' . $bookingId));
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    private function database(): Database
    {
        $pdo = $this->pdo;

        return Database::withFactory(static fn (): PDO => $pdo);
    }
}
