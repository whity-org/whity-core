<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\AddCalendarDeskStatuses;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\CalendarApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * The front desk (WHIT-1441, design section 39 §10, AP.27-AP.29): the visit's
 * steps, a customer running late, the day in one read with a light check for
 * changes, and a staff member running late, planned then moved with each
 * customer told. Real engine (SQLite, or Postgres when PHPUNIT_PG_DSN is set).
 */
final class CalendarFrontDeskRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private BookingRepository $bookings;
    private CalendarRepository $calendar;
    private ServiceRepository $services;
    private SettingsService $settings;
    /** 08:00 in Amman. */
    private string $now = '2026-11-16 05:00:00';
    private int $cut;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 'Amro Saleh', 12 => 'Awss Haddad'] as $profile => $name) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setTenant(1, 'timezone', 'Asia/Amman');
        $this->settings->setTenant(1, 'calendar.min_notice_minutes', '0');
        $this->calendar = new CalendarRepository($this->pdo);
        $this->services = new ServiceRepository($this->pdo);
        $this->bookings = new BookingRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $this->calendar, $this->services, $this->bookings, $this->settings, null,
            clock: fn (): DateTimeImmutable => new DateTimeImmutable($this->now, new DateTimeZone('UTC')));
        foreach ([11, 12] as $person) {
            $this->calendar->ensureStaff(1, $person);
            $this->calendar->replaceHours(1, $person, array_map(static fn (int $d): array => ['weekday' => $d, 'start_time' => '08:00', 'end_time' => '18:00'], range(1, 7)));
        }
        $this->cut = $this->services->insert(1, [
            'name' => ['en' => 'Haircut'], 'description' => [], 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 10000, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->services->replaceStaff(1, $this->cut, [11, 12]);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testTheVisitGoesStepByStepAndEachStepCanBeUndone(): void
    {
        $id = $this->book(11, '2026-11-16 05:30:00');
        self::assertSame('expected', $this->service->bookingOrFail(1, $id)['desk_status']);

        $started = $this->service->setDeskStatus(1, $id, 'with_staff', 11);
        self::assertSame('with_staff', $started['desk_status']);
        self::assertNotNull($started['checked_in_at'], 'Start checks them in too');
        self::assertSame('attended', $started['attendance']);
        self::assertSame('2026-11-16T05:00:00Z', $started['started_at']);

        $this->now = '2026-11-16 05:40:00';
        $done = $this->service->setDeskStatus(1, $id, 'done', 11);
        self::assertSame(['done', '2026-11-16T05:00:00Z', '2026-11-16T05:40:00Z'], [$done['desk_status'], $done['started_at'], $done['finished_at']]);

        $back = $this->service->setDeskStatus(1, $id, 'arrived', 11);
        self::assertSame(['arrived', null, null], [$back['desk_status'], $back['started_at'], $back['finished_at']]);
        $expected = $this->service->setDeskStatus(1, $id, 'expected', 11);
        self::assertSame(['expected', null, null], [$expected['desk_status'], $expected['checked_in_at'], $expected['attendance']]);

        $this->now = '2026-11-16 06:00:00';
        self::assertSame('no_show', $this->service->markAttendance(1, $id, 'no_show', 11)['desk_status']);
        try {
            $this->service->setDeskStatus(1, $id, 'lunch', 11);
            self::fail('Not a step');
        } catch (CalendarRejectedException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testACustomerRunningLateMovesNothing(): void
    {
        $id = $this->book(11, '2026-11-16 06:00:00');
        $late = $this->service->markLate(1, $id, 10);
        self::assertSame([10, '2026-11-16T05:00:00Z', '2026-11-16T06:00:00Z'], [$late['late_minutes'], $late['late_marked_at'], $late['starts_at']]);
        self::assertNull($this->service->markLate(1, $id, null)['late_minutes']);
        $this->expectException(CalendarRejectedException::class);
        $this->service->markLate(1, $id, 500);
    }

    public function testTheDayIsOneReadAndTheCheckReturnsOnlyWhatChanged(): void
    {
        $first = $this->book(11, '2026-11-16 06:00:00');
        $second = $this->book(12, '2026-11-16 07:00:00');
        $tomorrow = $this->book(12, '2026-11-17 07:00:00');
        $handler = $this->handler();

        $day = json_decode((string) $handler->desk(new Request('GET', '/x?date=2026-11-16', ['date' => '2026-11-16']))->getBody(), true)['data'];
        self::assertSame(['2026-11-16', 'Asia/Amman', true], [$day['date'], $day['time_zone'], $day['full']]);
        self::assertSame([$first, $second], array_column($day['bookings'], 'id'));
        self::assertNotContains($tomorrow, array_column($day['bookings'], 'id'));

        // Nothing changed since: the check is empty. Then one is checked in, one cancelled.
        $since = (new DateTimeImmutable('+1 second', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        self::assertSame([], $this->service->deskDay(1, '2026-11-16', null, new DateTimeImmutable($since))['bookings']);
        sleep(1);
        $this->service->setDeskStatus(1, $first, 'arrived', 11);
        $this->service->cancel(1, $second, 'staff', '');
        $changed = $this->service->deskDay(1, '2026-11-16', null, new DateTimeImmutable($since));
        self::assertFalse($changed['full']);
        self::assertSame([[$first, 'arrived'], [$second, 'cancelled']], array_map(static fn (array $b): array => [$b['id'], $b['desk_status']], $changed['bookings']));
        self::assertSame(422, $handler->desk(new Request('GET', '/x?since=yesterday', ['since' => 'yesterday']))->getStatusCode());
    }

    public function testAStaffMemberRunningLateIsPlannedThenMovedAndNothingElse(): void
    {
        // Amro: 09:00, 09:30 and 12:00 in Amman, and a meeting at 12:40.
        $a = $this->book(11, '2026-11-16 06:00:00');
        $b = $this->book(11, '2026-11-16 06:30:00');
        $c = $this->book(11, '2026-11-16 09:00:00');
        $this->pdo->exec("INSERT INTO calendar_events (tenant_id, profile_id, title, notes, starts_at, ends_at, all_day, busy, status, created_at, updated_at)
            VALUES (1, 11, 'Team meeting', '', '2026-11-16 09:40:00', '2026-11-16 10:30:00', FALSE, TRUE, 'confirmed', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $other = $this->book(12, '2026-11-16 06:00:00');

        $plan = $this->service->lateStaffPlan(1, 11, 15);
        self::assertSame(
            [[$a, '2026-11-16T06:15:00Z', null], [$b, '2026-11-16T06:45:00Z', null], [$c, null, CalendarRejectedException::PERSON_BUSY]],
            array_map(static fn (array $s): array => [$s['booking']['id'], $s['to'], $s['reason']], $plan),
            'pushed back to back; the last would run into the meeting, so it needs a decision',
        );
        self::assertSame('2026-11-16T06:00:00Z', $this->service->bookingOrFail(1, $a)['starts_at'], 'the plan moves nothing');

        $result = $this->service->applyLateStaff(1, 11, 15, [$a, $b, $c], 11);
        self::assertSame([[$a, true], [$b, true], [$c, false]], array_map(static fn (array $r): array => [$r['id'], $r['moved']], $result));
        self::assertSame(['2026-11-16T06:15:00Z', '2026-11-16T06:45:00Z', '2026-11-16T09:00:00Z', '2026-11-16T06:00:00Z'], [
            $this->service->bookingOrFail(1, $a)['starts_at'],
            $this->service->bookingOrFail(1, $b)['starts_at'],
            $this->service->bookingOrFail(1, $c)['starts_at'],
            $this->service->bookingOrFail(1, $other)['starts_at'],
        ]);
        self::assertSame(1, (int) $this->service->bookingOrFail(1, $a)['ics_sequence'], 'moved as a move is, so the customer is told');

        // Only the bookings named move.
        $this->service->applyLateStaff(1, 11, 5, [$b], 11);
        self::assertSame(['2026-11-16T06:15:00Z', '2026-11-16T06:50:00Z'], [$this->service->bookingOrFail(1, $a)['starts_at'], $this->service->bookingOrFail(1, $b)['starts_at']]);
    }

    public function testABookingWaitingBecauseTheyAreBehindIsPushedTooButNotOneThatStartedOrWasMissed(): void
    {
        $waiting = $this->book(11, '2026-11-16 04:50:00');
        $started = $this->book(11, '2026-11-16 04:20:00');
        $missed = $this->book(12, '2026-11-16 04:40:00');
        $this->service->setDeskStatus(1, $started, 'with_staff', 11);
        $this->service->markAttendance(1, $missed, 'no_show', 11);

        self::assertSame([[$waiting, '2026-11-16T05:00:00Z']], array_map(static fn (array $s): array => [$s['booking']['id'], $s['to']], $this->service->lateStaffPlan(1, 11, 10)));
        self::assertSame([], $this->service->lateStaffPlan(1, 12, 10));
    }

    public function testTheMigrationRunsTwiceAndRollsBack(): void
    {
        require_once dirname(__DIR__, 3) . '/database/migrations/368_add_calendar_desk_statuses.php';
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $id = $this->book(11, '2026-11-16 06:00:00');
        $this->service->markLate(1, $id, 5);
        AddCalendarDeskStatuses::up($db);
        AddCalendarDeskStatuses::up($db);
        self::assertSame(5, $this->service->bookingOrFail(1, $id)['late_minutes']);
        AddCalendarDeskStatuses::down($db);
        AddCalendarDeskStatuses::down($db);
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM calendar_bookings WHERE id = {$id}");
        self::assertNotFalse($stmt);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'the booking stays');
        AddCalendarDeskStatuses::up($db);
        self::assertNull($this->service->bookingOrFail(1, $id)['late_minutes']);
    }

    // ---------------------------------------------------------------------

    private function book(int $profile, string $startsUtc): int
    {
        return (int) $this->service->book(1, [
            'service_id' => $this->cut, 'profile_id' => $profile, 'starts_at' => new DateTimeImmutable($startsUtc, new DateTimeZone('UTC')),
            'customer_profile_id' => null, 'customer_name' => 'Emad Nasser', 'customer_email' => '',
            'customer_phone' => '', 'customer_note' => '', 'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => 11, 'enforce_hours' => false, 'public' => false, 'notify' => false,
        ])['id'];
    }

    private function handler(): CalendarApiHandler
    {
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        TenantContext::setTenantId(1);

        return new CalendarApiHandler($this->calendar, $this->services, $this->bookings, $this->service, $this->settings, $roles);
    }
}
