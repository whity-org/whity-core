<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\AddCalendarAttendance;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\CalendarApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Calendar\AttendanceAutoMarkJob;
use Whity\Core\Calendar\AttendanceRepository;
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
 * Did they come (WHIT-1427, design section 39 §4, AP.3–AP.6, AP.8–AP.10):
 * marks, cancel kinds, the rates, the customer's flag, Did they come? and
 * end-of-day marking, on the real engine (SQLite, or Postgres when
 * PHPUNIT_PG_DSN is set).
 */
final class CalendarAttendanceRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private BookingRepository $bookings;
    private CalendarRepository $calendar;
    private ServiceRepository $services;
    private SettingsService $settings;
    private int $cake;
    private string $now = '2026-11-16 05:00:00';
    /** @var object{log: list<array{0: string, 1: array<string, mixed>}>} */
    private object $audit;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11, 12] as $profile) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, 'Baker {$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        foreach ([1, 2] as $tenant) {
            $this->settings->setTenant($tenant, 'timezone', 'Asia/Amman');
            $this->settings->setTenant($tenant, 'calendar.min_notice_minutes', '0');
        }
        $this->calendar = new CalendarRepository($this->pdo);
        $this->services = new ServiceRepository($this->pdo);
        $this->bookings = new BookingRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $this->calendar, $this->services, $this->bookings, $this->settings, null,
            clock: fn (): DateTimeImmutable => new DateTimeImmutable($this->now, new DateTimeZone('UTC')));
        $this->audit = new class implements AuditLoggerInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $log = [];

            public function record(string $action, array $options = []): void
            {
                $this->log[] = [$action, $options];
            }
        };
        $this->service->setAudit($this->audit);
        $this->cake = $this->services->insert(1, [
            'name' => ['en' => 'Cake tasting', 'ar' => 'تذوق الكعك'], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 12500, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->services->replaceStaff(1, $this->cake, [11, 12]);
        foreach ([11, 12] as $baker) {
            $this->calendar->ensureStaff(1, $baker);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testCheckingInMarksCameAndUndoingItClearsTheMark(): void
    {
        $id = $this->book('2026-11-16 07:00:00', 'lina@example.com');
        $this->now = '2026-11-16 07:02:00';
        $in = $this->service->checkIn(1, $id, true, 11);
        self::assertSame('attended', $in['attendance']);
        self::assertSame(11, $in['attendance_by_profile_id']);
        self::assertFalse($in['attendance_auto']);
        self::assertSame(['calendar.attendance.marked', 'booking', $id, 'attended', null], $this->lastAudit());

        $out = $this->service->checkIn(1, $id, false, 11);
        self::assertNull($out['attendance'], 'undoing the check-in undoes the mark');
        self::assertNull($out['attendance_at']);
    }

    public function testDidntComeOnlyAfterTheStartAndAMarkCanAlwaysChange(): void
    {
        $id = $this->book('2026-11-16 07:00:00', 'lina@example.com');
        try {
            $this->service->markAttendance(1, $id, 'no_show', 11);
            self::fail('A booking that has not started cannot be marked');
        } catch (CalendarRejectedException $e) {
            self::assertSame(422, $e->status);
        }

        $this->now = '2026-11-16 07:30:00';
        $marked = $this->service->markAttendance(1, $id, 'no_show', 12);
        self::assertSame('no_show', $marked['attendance']);
        self::assertSame(12, $marked['attendance_by_profile_id']);
        self::assertSame('2026-11-16T07:30:00Z', $marked['attendance_at']);

        $came = $this->service->markAttendance(1, $id, 'attended', 11);
        self::assertSame('attended', $came['attendance'], 'Mark as came');
        self::assertSame(['calendar.attendance.marked', 'booking', $id, 'attended', 'no_show'], $this->lastAudit());

        self::assertNull($this->service->markAttendance(1, $id, null, 11)['attendance'], 'a mark can be cleared');
        $this->expectException(CalendarRejectedException::class);
        $this->service->markAttendance(1, $id, 'maybe', 11);
    }

    public function testACheckedInCustomerMarkedDidntComeLosesTheCheckIn(): void
    {
        $id = $this->book('2026-11-16 07:00:00', 'lina@example.com');
        $this->now = '2026-11-16 07:05:00';
        $this->service->checkIn(1, $id, true, 11);
        $marked = $this->service->markAttendance(1, $id, 'no_show', 11);
        self::assertSame('no_show', $marked['attendance']);
        self::assertNull($marked['checked_in_at']);
    }

    public function testCancelKindsFollowTheWindowAndWhoseCancelItIs(): void
    {
        $early = $this->book('2026-11-18 07:00:00', 'a@example.com');
        $late = $this->book('2026-11-16 20:00:00', 'b@example.com');
        $asked = $this->book('2026-11-16 21:00:00', 'c@example.com');
        $ours = $this->book('2026-11-16 22:00:00', 'd@example.com');
        $silent = $this->book('2026-11-16 23:00:00', 'e@example.com');

        self::assertSame('early', $this->service->cancel(1, $early, 'customer', '')['cancel_kind'], 'more than 24 hours before');
        self::assertSame('late', $this->service->cancel(1, $late, 'customer', '')['cancel_kind'], 'inside 24 hours');
        self::assertSame('late', $this->service->cancel(1, $asked, 'staff', '', 'customer')['cancel_kind'], 'staff: the customer asked');
        self::assertSame('business', $this->service->cancel(1, $ours, 'staff', 'Oven broke', 'business')['cancel_kind'], 'staff: we cancelled');
        self::assertNull($this->service->cancel(1, $silent, 'staff', '')['cancel_kind'], 'an older client says nothing: no kind');

        $this->settings->setTenant(1, 'calendar.late_cancel_hours', '2');
        $now = $this->book('2026-11-16 18:00:00', 'f@example.com');
        self::assertSame('early', $this->service->cancel(1, $now, 'customer', '')['cancel_kind'], 'the window is the workspace\'s');
    }

    public function testTheRatesCountDueBookingsAndLeaveUnmarkedOnesOut(): void
    {
        $came = $this->book('2026-11-16 06:00:00', 'a@example.com');
        $missed = $this->book('2026-11-16 06:30:00', 'b@example.com');
        $this->book('2026-11-16 07:00:00', 'c@example.com'); // never marked
        $soon = $this->book('2026-11-16 07:50:00', 'd@example.com'); // inside the grace minutes
        $late = $this->book('2026-11-16 12:00:00', 'e@example.com');
        $ours = $this->book('2026-11-16 13:00:00', 'f@example.com');
        $this->service->cancel(1, $late, 'customer', '');
        $this->service->cancel(1, $ours, 'staff', '', 'business');

        $this->now = '2026-11-16 08:00:00';
        $this->service->markAttendance(1, $came, 'attended', 11);
        $this->service->markAttendance(1, $missed, 'no_show', 11);

        $rates = $this->service->attendanceRates(1, $this->at('2026-11-16 00:00:00'), $this->at('2026-11-17 00:00:00'), []);
        self::assertSame([1, 1, 1, 0, 1, 3], [$rates['came'], $rates['no_show'], $rates['late_cancels'], $rates['early_cancels'], $rates['unmarked'], $rates['due']]);
        self::assertSame(0.3333, $rates['no_show_rate']);
        self::assertSame(0.6667, $rates['missed_rate']);
        self::assertSame(0.25, $rates['unmarked_share'], 'one unmarked of four: the 07:50 one is still within the grace minutes');
        self::assertSame(1, $this->service->attendanceRates(1, $this->at('2026-11-16 00:00:00'), $this->at('2026-11-17 00:00:00'), ['profile_id' => 12])['due'] + 0 * $soon, 'by staff member');
        self::assertSame(0, $this->service->attendanceRates(2, $this->at('2026-11-16 00:00:00'), $this->at('2026-11-17 00:00:00'), [])['due'], 'another workspace sees nothing');
    }

    public function testDidTheyComeListsStartedBookingsNobodyMarkedAfterTheGrace(): void
    {
        $a = $this->book('2026-11-16 06:00:00', 'a@example.com');
        $b = $this->book('2026-11-16 06:50:00', 'b@example.com');
        $c = $this->book('2026-11-16 06:30:00', 'c@example.com');
        $this->now = '2026-11-16 07:00:00';
        $this->service->markAttendance(1, $c, 'attended', 11);

        self::assertSame([$a], array_column($this->service->unmarkedToday(1, null, null), 'id'), '06:50 is inside the 15 minutes; 06:30 is marked');
        $this->settings->setTenant(1, 'calendar.no_show_grace_minutes', '5');
        self::assertSame([$a, $b], array_column($this->service->unmarkedToday(1, null, null), 'id'));
    }

    public function testTheFlagCountsMissesOverTheWindowAndCanStartAgain(): void
    {
        $first = $this->book('2026-11-02 07:00:00', 'rana@example.com');
        $second = $this->book('2026-11-09 07:00:00', 'Rana@Example.com');
        $third = $this->book('2026-11-12 07:00:00', 'rana@example.com');
        $next = $this->book('2026-11-20 07:00:00', 'rana@example.com');
        $other = $this->book('2026-11-20 08:00:00', 'awss@example.com');
        $this->service->markAttendance(1, $first, 'no_show', 11);
        $this->service->markAttendance(1, $second, 'attended', 11);
        self::assertNull($this->service->bookingOrFail(1, $next)['customer_flag'], 'one miss: no flag at 2');

        $this->service->markAttendance(1, $third, 'no_show', 11);
        self::assertSame(['misses' => 2, 'of' => 3], $this->service->bookingOrFail(1, $next)['customer_flag'], 'Missed 2 of the last 3');
        self::assertNull($this->service->bookingOrFail(1, $other)['customer_flag']);

        $listed = $this->service->withCustomerFlags(1, array_map([BookingRepository::class, 'toApi'], $this->bookings->list(1, [], $this->at('2026-11-20 00:00:00'), $this->at('2026-11-21 00:00:00'))));
        self::assertSame([['misses' => 2, 'of' => 3], null], array_column($listed, 'customer_flag'), 'the calendar list carries it too');

        // An email becomes the customer's contact, so that is who they are.
        $key = AttendanceRepository::customerKey($this->service->bookingOrFail(1, $next));
        self::assertIsString($key);
        self::assertStringStartsWith('c:', $key);
        $line = $this->service->customerAttendance(1, $key);
        self::assertSame([1, 2, 0], [$line['came'], $line['no_show'], $line['late_cancels']]);
        self::assertSame('2026-11-02T07:00:00Z', $line['since']);

        $this->service->resetCustomerAttendance(1, $key, 11);
        self::assertNull($this->service->bookingOrFail(1, $next)['customer_flag'], 'Start again from today');
        self::assertSame('calendar.attendance.reset', $this->audit->log[count($this->audit->log) - 1][0]);
        self::assertSame(2, $this->service->customerAttendance(1, $key)['no_show'], 'the history itself stays');

        $this->settings->setTenant(1, 'calendar.no_show_flag_after', '0');
        $this->service->markAttendance(1, $first, 'no_show', 11);
        self::assertNull($this->service->bookingOrFail(1, $next)['customer_flag'], '0 turns the flag off');
    }

    public function testLateCancelsCountAsMissesUnlessTurnedOff(): void
    {
        $a = $this->book('2026-11-16 10:00:00', 'rana@example.com');
        $b = $this->book('2026-11-16 11:00:00', 'rana@example.com');
        $next = $this->book('2026-11-25 07:00:00', 'rana@example.com');
        $this->service->cancel(1, $a, 'customer', '');
        $this->service->cancel(1, $b, 'customer', '');
        $this->now = '2026-11-16 12:00:00';
        self::assertSame(['misses' => 2, 'of' => 2], $this->service->bookingOrFail(1, $next)['customer_flag']);
        $this->settings->setTenant(1, 'calendar.no_show_count_late_cancels', 'false');
        self::assertNull($this->service->bookingOrFail(1, $next)['customer_flag']);
    }

    public function testEndOfDayMarkingIsOffByDefaultAndMarksOnlyEndedDaysSinceItWasTurnedOn(): void
    {
        $longAgo = $this->book('2026-11-10 07:00:00', 'a@example.com');
        $yesterday = $this->book('2026-11-15 07:00:00', 'b@example.com');
        $marked = $this->book('2026-11-15 08:00:00', 'c@example.com');
        $today = $this->book('2026-11-16 05:30:00', 'd@example.com');
        $other = $this->book('2026-11-15 09:00:00', 'e@example.com');
        $this->now = '2026-11-16 06:00:00';
        $this->service->markAttendance(1, $marked, 'attended', 11);
        $job = new AttendanceAutoMarkJob($this->service);
        self::assertSame(['marked' => 0], $job->handle([]), 'off by default');

        $this->settings->setTenant(1, 'calendar.auto_no_show', 'true');
        $this->pdo->exec("UPDATE tenant_settings SET updated_at = '2026-11-14 00:00:00' WHERE tenant_id = 1 AND setting_key = 'calendar.auto_no_show'");
        $this->service->cancel(1, $other, 'staff', '', 'business');
        self::assertSame(['marked' => 1], $job->handle([]));
        $row = $this->service->bookingOrFail(1, $yesterday);
        self::assertSame(['no_show', true, null], [$row['attendance'], $row['attendance_auto'], $row['attendance_by_profile_id']], 'marked by Whity');
        self::assertNull($this->service->bookingOrFail(1, $longAgo)['attendance'], 'before the switch was on: left alone');
        self::assertNull($this->service->bookingOrFail(1, $today)['attendance'], 'today has not ended in Amman');
        self::assertSame('attended', $this->service->bookingOrFail(1, $marked)['attendance'], 'a person\'s mark stays');
        self::assertSame(['marked' => 0], $job->handle([]), 'twice is fine');

        self::assertSame('attended', $this->service->markAttendance(1, $yesterday, 'attended', 11)['attendance'], 'a person can change it');
        self::assertFalse($this->service->bookingOrFail(1, $yesterday)['attendance_auto']);
    }

    public function testTheRatesNeedCalendarReportsExceptForYourOwn(): void
    {
        $this->book('2026-11-16 06:00:00', 'a@example.com');
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(static fn (int $p, string $perm): bool => $p === 12 && $perm === 'calendar:reports');
        $handler = new CalendarApiHandler($this->calendar, $this->services, $this->bookings, $this->service, $this->settings, $roles);
        TenantContext::setTenantId(1);
        $as = static function (int $profileId, string $query): Request {
            $r = new Request('GET', '/api/v1/calendar/attendance?' . $query, [], '');
            $r->user = (object) ['profile_id' => $profileId];

            return $r;
        };
        $period = 'from=' . rawurlencode('2026-11-01T00:00:00+00:00') . '&to=' . rawurlencode('2026-12-01T00:00:00+00:00');
        self::assertSame(403, $handler->attendanceRates($as(11, $period))->getStatusCode());
        self::assertSame(200, $handler->attendanceRates($as(11, $period . '&profile_id=11'))->getStatusCode(), 'your own rate');
        self::assertSame(200, $handler->attendanceRates($as(12, $period))->getStatusCode(), 'calendar:reports');
        self::assertSame(422, $handler->attendanceRates($as(12, 'from=x'))->getStatusCode());
    }

    public function testTheMigrationFillsWhatIsKnownAndReverses(): void
    {
        $in = $this->book('2026-11-16 06:00:00', 'a@example.com');
        $early = $this->book('2026-11-20 07:00:00', 'b@example.com');
        $late = $this->book('2026-11-16 12:00:00', 'c@example.com');
        $this->now = '2026-11-16 06:05:00';
        $this->bookings->checkIn(1, $in, $this->at('2026-11-16 06:05:00'));
        $this->bookings->transition(1, $early, BookingRepository::LIVE, BookingRepository::STATUS_CANCELLED, ['cancelled_by' => 'customer']);
        $this->bookings->transition(1, $late, BookingRepository::LIVE, BookingRepository::STATUS_CANCELLED, ['cancelled_by' => 'customer']);
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        require_once dirname(__DIR__, 3) . '/database/migrations/364_add_calendar_attendance.php';

        AddCalendarAttendance::down($db);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM permissions WHERE name = 'calendar:reports'"));
        AddCalendarAttendance::up($db);
        AddCalendarAttendance::up($db);
        // The cancels happened "now" in the database's own clock; place them as they would be.
        $this->pdo->exec("UPDATE calendar_bookings SET cancelled_at = '2026-11-16 05:00:00', cancel_kind = NULL WHERE id IN ({$early}, {$late})");
        $this->pdo->exec("UPDATE calendar_bookings SET attendance = NULL WHERE id = {$in}");
        AddCalendarAttendance::up($db);

        self::assertSame('attended', $this->scalar("SELECT attendance FROM calendar_bookings WHERE id = {$in}"));
        self::assertSame('early', $this->scalar("SELECT cancel_kind FROM calendar_bookings WHERE id = {$early}"));
        self::assertSame('late', $this->scalar("SELECT cancel_kind FROM calendar_bookings WHERE id = {$late}"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM permissions WHERE name = 'calendar:reports'"));
    }

    // ---------------------------------------------------------------------

    private function book(string $startsUtc, string $email): int
    {
        $was = $this->now;
        $start = $this->at($startsUtc);
        if ($this->at($this->now) >= $start) {
            $this->now = $start->modify('-1 day')->format('Y-m-d H:i:s');
        }
        $made = $this->service->book(1, [
            'service_id' => $this->cake, 'profile_id' => str_ends_with($startsUtc, ':30:00') ? 12 : 11, 'starts_at' => $start,
            'customer_profile_id' => null, 'customer_name' => 'A customer', 'customer_email' => $email,
            'customer_phone' => '', 'customer_note' => '', 'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => 11, 'enforce_hours' => false, 'public' => false, 'notify' => false, 'book_anyway' => true,
        ]);
        $this->now = $was;

        return (int) $made['id'];
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /** @return list<mixed> */
    private function lastAudit(): array
    {
        $last = $this->audit->log[count($this->audit->log) - 1];
        $meta = is_array($last[1]['metadata'] ?? null) ? $last[1]['metadata'] : [];

        return [$last[0], $last[1]['target_type'] ?? null, $last[1]['target_id'] ?? null, $meta['attendance'] ?? null, $meta['previous'] ?? null];
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }
}
