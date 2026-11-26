<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\CalendarApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Calendar\AttendanceReport;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\CalendarSettings;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * Reports › Appointments (WHIT-1437, design section 39 §4.2–§4.3, AP.6–AP.9):
 * periods in the workspace's zone, each breakdown, the period before, CSV,
 * and who may see whose rates. Real engine (SQLite, or Postgres when
 * PHPUNIT_PG_DSN is set).
 */
final class CalendarAttendanceReportRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private BookingRepository $bookings;
    private CalendarRepository $calendar;
    private ServiceRepository $services;
    private SettingsService $settings;
    private int $cake;
    private int $icing;
    private string $now = '2026-11-01 05:00:00';

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 'Amro Saleh', 12 => 'Awss Haddad'] as $profile => $name) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
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
        foreach (['cake' => ['Cake tasting', 'تذوق الكعك'], 'icing' => ['Icing class', 'درس التزيين']] as $var => [$en, $ar]) {
            $this->{$var} = $this->services->insert(1, [
                'name' => ['en' => $en, 'ar' => $ar], 'description' => [],
                'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
                'price_minor' => 12500, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
            ]);
            $this->services->replaceStaff(1, $this->{$var}, [11, 12]);
        }
        foreach ([11, 12] as $baker) {
            $this->calendar->ensureStaff(1, $baker);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testPeriodsAreInTheWorkspacesZoneAndTheOneBeforeIsAsLong(): void
    {
        $settings = CalendarSettings::fromSettings($this->settings->effective(1));
        // Wednesday 18 November 2026, 01:00 in Amman (Tuesday 22:00 UTC). The week starts on Saturday.
        $now = new DateTimeImmutable('2026-11-17 22:00:00', new DateTimeZone('UTC'));
        $fmt = static fn (array $p): array => array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i'), $p);

        // Amman is UTC+3 all year (no summer time since 2022).
        self::assertSame(['2026-11-13 21:00', '2026-11-20 21:00', '2026-11-06 21:00'], $fmt(AttendanceReport::period('this_week', $now, $settings)), 'Saturday 14 November, Amman');
        self::assertSame(['2026-10-31 21:00', '2026-11-30 21:00', '2026-09-30 21:00'], $fmt(AttendanceReport::period('this_month', $now, $settings)), 'November, and October before it');
        self::assertSame(['2026-09-30 21:00', '2026-10-31 21:00', '2026-08-31 21:00'], $fmt(AttendanceReport::period('last_month', $now, $settings)));
        self::assertSame(['2026-08-31 21:00', '2026-11-30 21:00', '2026-05-31 21:00'], $fmt(AttendanceReport::period('last_3_months', $now, $settings)), 'September to November');
        $london = new CalendarSettings(new DateTimeZone('Europe/London'), true, 0, 60, 15, 24, 15, 6, [5, 6], 'GBP');
        self::assertSame(['2026-09-30 23:00', '2026-11-01 00:00', '2026-08-31 23:00'], $fmt(AttendanceReport::period('last_month', $now, $london)), 'October in London spans the change of clocks');
    }

    public function testTheBreakdownsCountEachBookingOnceAndTheTrendComparesThePeriodBefore(): void
    {
        // October, the period before.
        $this->marked('2026-10-05 07:00:00', 11, $this->cake, 'rana@example.com', 'attended');
        $this->marked('2026-10-06 07:00:00', 11, $this->cake, 'rana@example.com', 'attended');
        // November.
        $this->marked('2026-11-02 07:00:00', 11, $this->cake, 'rana@example.com', 'no_show');   // Monday 10:00 Amman
        $this->marked('2026-11-03 07:00:00', 11, $this->icing, 'ahmad@example.com', 'attended'); // Tuesday 10:00
        $this->marked('2026-11-04 08:00:00', 12, $this->cake, 'ahmad@example.com', 'attended');  // Wednesday 11:00
        $late = $this->book('2026-11-05 07:00:00', 12, $this->cake, 'rana@example.com');
        $this->now = '2026-11-05 01:00:00';
        $this->service->cancel(1, $late, 'customer', ''); // six hours before: late
        $this->now = '2026-11-01 05:00:00';
        $this->book('2026-11-06 07:00:00', 12, $this->icing, 'emad@example.com'); // not marked
        $ours = $this->book('2026-11-07 07:00:00', 12, $this->icing, 'emad@example.com');
        $this->service->cancel(1, $ours, 'staff', '', 'business'); // never counts
        $this->now = '2026-11-10 05:00:00';

        $staff = $this->report('staff');
        self::assertSame([4, 1, 1, 1, 1], [$staff['totals']['due'], $staff['totals']['no_show'], $staff['totals']['late_cancels'], $staff['totals']['unmarked'], $staff['totals']['came'] - 1]);
        self::assertSame(0.25, $staff['totals']['no_show_rate']);
        self::assertSame(0.5, $staff['totals']['missed_rate']);
        self::assertSame([2, 0, null], [$staff['previous']['due'], $staff['previous']['no_show'], $staff['previous']['late_cancel_rate'] === 0.0 ? null : $staff['previous']['late_cancel_rate']]);
        self::assertSame(
            [['11', 'Amro Saleh', 2, 1], ['12', 'Awss Haddad', 2, 0]],
            array_map(static fn (array $r): array => [$r['key'], $r['label'], $r['due'], $r['no_show']], $staff['rows']),
        );

        $service = $this->report('service');
        self::assertEquals(['en' => 'Cake tasting', 'ar' => 'تذوق الكعك'], $service['rows'][0]['label']);
        self::assertSame(3, $service['rows'][0]['due']);

        $customer = $this->report('customer');
        self::assertSame('Rana', explode(' ', (string) $customer['rows'][0]['label'])[0], 'most missed first');
        self::assertSame([1, 1], [$customer['rows'][0]['no_show'], $customer['rows'][0]['late_cancels']]);

        $hours = $this->report('weekday_hour');
        self::assertSame(['1-10', '2-10', '3-11', '4-10', '5-10'], array_column($hours['rows'], 'key'), 'ISO weekday and hour in Amman, in order');

        $returning = $this->report('returning');
        self::assertSame(['returning' => 3, 'new' => 2], array_combine(array_column($returning['rows'], 'key'), array_map(static fn (array $r): int => $r['due'] + $r['unmarked'], $returning['rows'])), 'Ahmad\'s and Emad\'s first bookings are new; Rana booked in October');

        $source = $this->report('source');
        self::assertSame(['staff'], array_column($source['rows'], 'key'));

        $this->pdo->exec("UPDATE calendar_bookings SET reminder_sent_at = '2026-11-01 07:00:00' WHERE starts_at = '2026-11-02 07:00:00'");
        $this->pdo->exec("UPDATE calendar_bookings SET confirmed_by_customer_at = '2026-11-02 05:00:00' WHERE starts_at = '2026-11-03 07:00:00'");
        $reminder = array_column($this->report('reminder')['rows'], 'no_show', 'key');
        self::assertSame(['confirmed' => 0, 'not_reminded' => 0, 'reminded' => 1], self::sorted($reminder));

        self::assertSame(2, $this->report('staff', ['profile_id' => 12])['totals']['due'], 'one staff member: a came and a late cancel');
        self::assertSame(0, $this->report('staff', [], 2)['totals']['due'], 'another workspace sees nothing');

        $this->expectException(CalendarRejectedException::class);
        $this->report('colour');
    }

    public function testTheCsvIsSafeToOpenAndInTheReadersLanguage(): void
    {
        $this->marked('2026-11-02 07:00:00', 11, $this->cake, 'x@example.com', 'no_show', '=HYPERLINK("http://bad")');
        $this->now = '2026-11-10 05:00:00';
        $report = $this->report('customer');

        $csv = AttendanceReport::csv($report, 'en');
        self::assertStringStartsWith("\xEF\xBB\xBF" . 'Name,Due,Came', $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv, 'a formula is written as text');
        self::assertStringContainsString('100.0%', $csv);
        self::assertStringContainsString('الاسم', AttendanceReport::csv($report, 'ar'));
        $hours = AttendanceReport::csv($this->report('weekday_hour'), 'en');
        self::assertStringContainsString("\n\"Monday 10:00\",", $hours, 'the day and hour in words, in Amman');
        self::assertStringContainsString("\n\"الإثنين 10:00\",", AttendanceReport::csv($this->report('weekday_hour'), 'ar'));
        self::assertStringContainsString("\n\"Booked by the team\",", AttendanceReport::csv($this->report('source'), 'en'));
    }

    public function testEveryoneSeesTheirOwnRatesAndCalendarReportsSeesEveryones(): void
    {
        $this->marked('2026-11-02 07:00:00', 11, $this->cake, 'a@example.com', 'no_show');
        $this->marked('2026-11-03 07:00:00', 12, $this->cake, 'b@example.com', 'attended');
        $this->now = '2026-11-10 05:00:00';
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(static fn (int $p, string $perm): bool => $p === 12 && $perm === 'calendar:reports');
        $handler = new CalendarApiHandler($this->calendar, $this->services, $this->bookings, $this->service, $this->settings, $roles);
        TenantContext::setTenantId(1);
        $as = static function (int $profileId, string $query): Request {
            $r = new Request('GET', '/api/v1/calendar/attendance/report?' . $query, [], '');
            $r->user = (object) ['profile_id' => $profileId];

            return $r;
        };

        $own = self::data($handler->attendanceReport($as(11, 'period=this_month')));
        self::assertTrue($own['own']);
        self::assertSame(['11'], array_column($own['rows'], 'key'), 'without calendar:reports: only their own');
        self::assertSame(403, $handler->attendanceReport($as(11, 'period=this_month&profile_id=12'))->getStatusCode());

        $all = self::data($handler->attendanceReport($as(12, 'period=this_month')));
        self::assertFalse($all['own']);
        self::assertSame(['11', '12'], array_column($all['rows'], 'key'));

        $csv = $handler->attendanceReport($as(12, 'period=this_month&format=csv&lang=ar'));
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringStartsWith('text/csv', (string) ($csv->getHeaders()['content-type'] ?? ''));
        self::assertSame(422, $handler->attendanceReport($as(12, 'period=forever'))->getStatusCode());
        self::assertSame(422, $handler->attendanceReport($as(12, 'by=colour'))->getStatusCode());
    }

    // ---------------------------------------------------------------------

    /**
     * @param array{profile_id?: ?int, service_id?: ?int, source?: ?string} $filters
     * @return array<string, mixed>
     */
    private function report(string $by, array $filters = [], int $tenant = 1): array
    {
        $settings = CalendarSettings::fromSettings($this->settings->effective($tenant));
        $now = new DateTimeImmutable($this->now, new DateTimeZone('UTC'));
        [$from, $to, $prev] = AttendanceReport::period('this_month', $now, $settings);

        return (new AttendanceReport($this->pdo, $this->calendar, $this->services))->build($tenant, $from, $to, $prev, $by, $filters, $settings, $now);
    }

    private function marked(string $startsUtc, int $profile, int $service, string $email, string $attendance, string $name = ''): int
    {
        $id = $this->book($startsUtc, $profile, $service, $email, $name);
        $was = $this->now;
        $this->now = (new DateTimeImmutable($startsUtc, new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s');
        $this->service->markAttendance(1, $id, $attendance, $profile);
        $this->now = $was;

        return $id;
    }

    private function book(string $startsUtc, int $profile, int $service, string $email, string $name = ''): int
    {
        $start = new DateTimeImmutable($startsUtc, new DateTimeZone('UTC'));
        $was = $this->now;
        $this->now = $start->modify('-2 days')->format('Y-m-d H:i:s');
        $made = $this->service->book(1, [
            'service_id' => $service, 'profile_id' => $profile, 'starts_at' => $start,
            'customer_profile_id' => null, 'customer_name' => $name !== '' ? $name : ucfirst(explode('@', $email)[0]) . ' Customer', 'customer_email' => $email,
            'customer_phone' => '', 'customer_note' => '', 'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => 11, 'enforce_hours' => false, 'public' => false, 'notify' => false, 'book_anyway' => true,
        ]);
        $this->now = $was;

        return (int) $made['id'];
    }

    /**
     * @param array<string, int> $a
     * @return array<string, int>
     */
    private static function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }

    /** @return array<string, mixed> */
    private static function data(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['data']);

        return $body['data'];
    }
}
