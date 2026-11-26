<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\AddCalendarStaffAndTime;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\CalendarApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\BusyCheckFailures;
use Whity\Core\Calendar\CalendarPlugins;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\RoomRepository;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\PluginCalendarBusyInterface;

/**
 * Staff and time (WHIT-1440, design section 39 §5.3, §5.4 and §7.1, AP.16,
 * AP.17 and AP.19): each person's own length, buffer after and price, kept
 * by the booking; a branch's own time zone; a group's resource from the
 * person's branch; time off listing the bookings it covers; an external
 * calendar that fails is kept once, by name, and pruned. Real engine (SQLite,
 * or Postgres when PHPUNIT_PG_DSN is set).
 */
final class CalendarStaffAndTimeRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private BookingRepository $bookings;
    private CalendarRepository $calendar;
    private ServiceRepository $services;
    private RoomRepository $rooms;
    private SettingsService $settings;
    private string $now = '2026-11-15 05:00:00';
    private int $cut;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 'Amro Saleh', 12 => 'Awss Haddad', 13 => 'Ahmad Khalil'] as $profile => $name) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setTenant(1, 'timezone', 'Asia/Amman');
        $this->settings->setTenant(1, 'calendar.min_notice_minutes', '0');
        $this->settings->setTenant(1, 'calendar.multi_service_booking', 'true');
        $this->calendar = new CalendarRepository($this->pdo);
        $this->services = new ServiceRepository($this->pdo);
        $this->bookings = new BookingRepository($this->pdo);
        $this->rooms = new RoomRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $this->calendar, $this->services, $this->bookings, $this->settings, null,
            clock: fn (): DateTimeImmutable => new DateTimeImmutable($this->now, new DateTimeZone('UTC')));
        foreach ([11, 12, 13] as $person) {
            $this->calendar->ensureStaff(1, $person);
            $this->calendar->replaceHours(1, $person, array_map(static fn (int $d): array => ['weekday' => $d, 'start_time' => '08:00', 'end_time' => '18:00'], range(1, 7)));
        }
        // A haircut: 30 minutes, JOD 10.000. Awss takes 45 minutes, 15 after, JOD 15.000.
        $this->cut = $this->newService('Haircut', 30, 10000);
        $this->services->replaceStaff(1, $this->cut, [11, 12], [12 => ['duration_minutes' => 45, 'buffer_after_minutes' => 15, 'price_minor' => 15000]]);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAPersonsOwnLengthBufferAndPriceAreUsedAndKept(): void
    {
        $service = $this->services->find(1, $this->cut);
        self::assertSame([
            ['profile_id' => 11, 'duration_minutes' => null, 'buffer_after_minutes' => null, 'price_minor' => null],
            ['profile_id' => 12, 'duration_minutes' => 45, 'buffer_after_minutes' => 15, 'price_minor' => 15000],
        ], $service['staff'] ?? null);

        $awss = $this->service->availability(1, $this->cut, 12, $this->at('2026-11-16 05:00:00'), $this->at('2026-11-16 06:00:00'));
        self::assertSame('2026-11-16T05:45:00Z', $awss[0]['ends_at'], 'his free times are 45 minutes long');
        $amro = $this->service->availability(1, $this->cut, 11, $this->at('2026-11-16 05:00:00'), $this->at('2026-11-16 06:00:00'));
        self::assertSame('2026-11-16T05:30:00Z', $amro[0]['ends_at'], 'the service’s own 30');

        $booked = $this->service->bookingOrFail(1, $this->book($this->cut, 12, '2026-11-16 07:00:00'));
        self::assertSame(['2026-11-16T07:45:00Z', '2026-11-16T08:00:00Z', 15000], [$booked['ends_at'], $booked['blocks_until'], $booked['price_minor']]);

        // The service changes later; the booking keeps what it used, also when moved.
        $this->pdo->exec("UPDATE calendar_services SET duration_minutes = 60, buffer_after_minutes = 0, price_minor = 20000 WHERE id = {$this->cut}");
        $this->services->replaceStaff(1, $this->cut, [11, 12], []);
        $moved = $this->service->move(1, (int) $booked['id'], $this->at('2026-11-16 09:00:00'), null, false);
        self::assertSame(['2026-11-16T09:45:00Z', '2026-11-16T10:00:00Z', 15000], [$moved['ends_at'], $moved['blocks_until'], $moved['price_minor']]);
    }

    public function testAnOlderClientSendingOnlyThePeopleKeepsTheirValues(): void
    {
        $handler = $this->handler(true);
        $updated = self::data($handler->updateService(self::request('PATCH', ['staff_profile_ids' => [12, 13]]), ['id' => (string) $this->cut]));
        self::assertSame([12, 13], $updated['staff_profile_ids']);
        self::assertSame(45, $updated['staff'][0]['duration_minutes'], 'Awss keeps his own length');
        self::assertNull($updated['staff'][1]['duration_minutes']);

        $updated = self::data($handler->updateService(self::request('PATCH', ['staff' => [['profile_id' => 13, 'duration_minutes' => 20, 'price_minor' => null]]]), ['id' => (string) $this->cut]));
        self::assertSame([13], $updated['staff_profile_ids']);
        self::assertSame([['profile_id' => 13, 'duration_minutes' => 20, 'buffer_after_minutes' => null, 'price_minor' => null]], $updated['staff']);
        self::assertSame(422, $handler->updateService(self::request('PATCH', ['staff' => [['profile_id' => 13, 'duration_minutes' => 2]]]), ['id' => (string) $this->cut])->getStatusCode());
    }

    public function testSeveralServicesFollowEachPersonsLengthAndPrice(): void
    {
        $wash = $this->newService('Wash', 30, 5000);
        $this->services->replaceStaff(1, $wash, [11]);
        $booking = $this->service->book(1, $this->bookingRequest(null, '2026-11-16 07:00:00') + [
            'items' => [['service_id' => $this->cut, 'profile_id' => 12], ['service_id' => $wash, 'profile_id' => 11]],
        ]);
        self::assertSame(
            [['2026-11-16T07:00:00Z', '2026-11-16T07:45:00Z'], ['2026-11-16T07:45:00Z', '2026-11-16T08:15:00Z']],
            array_map(static fn (array $i): array => [$i['starts_at'], $i['ends_at']], $booking['items']),
            'the wash starts when Awss’s 45 minutes end',
        );
        self::assertSame(20000, $booking['price_minor'], 'his 15.000 and the wash’s 5.000');
    }

    public function testABranchZoneMovesItsPeoplesHoursAndTimes(): void
    {
        $london = $this->branch('London branch');
        $this->calendar->setStaffUnit(1, 12, $london);
        $handler = $this->handler(true);
        self::assertSame(422, $handler->updateBranch(self::request('PATCH', ['time_zone' => 'Mars/Olympus']), ['id' => (string) $london])->getStatusCode());
        self::assertSame(404, $handler->updateBranch(self::request('PATCH', ['time_zone' => 'Europe/London']), ['id' => '9999'])->getStatusCode());
        self::assertSame('Europe/London', self::data($handler->updateBranch(self::request('PATCH', ['time_zone' => 'Europe/London']), ['id' => (string) $london]))['time_zone']);
        $listed = json_decode((string) $handler->listBranches(self::request('GET', []))->getBody(), true);
        self::assertSame(['London branch', 'Europe/London', true, 'Asia/Amman'], [$listed['data'][0]['name'], $listed['data'][0]['time_zone'], $listed['data'][0]['in_use'], $listed['workspace_time_zone']]);

        // 08:00 in London (GMT in November) is 08:00 UTC; in Amman it is 05:00 UTC.
        $day = [$this->at('2026-11-16 00:00:00'), $this->at('2026-11-17 00:00:00')];
        self::assertSame('2026-11-16T08:00:00Z', $this->service->availability(1, $this->cut, 12, ...$day)[0]['starts_at']);
        self::assertSame('2026-11-16T05:00:00Z', $this->service->availability(1, $this->cut, 11, ...$day)[0]['starts_at']);
        $booked = $this->service->bookingOrFail(1, $this->book($this->cut, 12, '2026-11-16 09:00:00'));
        self::assertSame('Europe/London', $booked['time_zone'], 'emails and the page write his times in London');
        self::assertNull($this->service->bookingOrFail(1, $this->book($this->cut, 11, '2026-11-16 09:00:00'))['time_zone']);
        self::assertSame(['Ahmad Khalil' => null, 'Amro Saleh' => null, 'Awss Haddad' => 'Europe/London'], array_column($this->calendar->listStaff(1), 'time_zone', 'display_name'));
    }

    public function testAnyOneFromAGroupIsTakenInThePersonsBranch(): void
    {
        $amman = $this->branch('Amman branch');
        $london = $this->branch('London branch');
        $resource = fn (string $en, ?int $unit, int $sort): int => $this->rooms->insert(1, [
            'name' => ['en' => $en], 'unit_id' => $unit, 'active' => true, 'sort_order' => $sort, 'kind' => 'chair', 'group_key' => 'Chairs',
        ]);
        $first = $resource('Chair A', $amman, 1);
        $second = $resource('Chair B', $london, 2);
        $this->rooms->replaceRequirements(1, $this->cut, [['resource_id' => null, 'group_key' => 'Chairs']]);
        $this->calendar->setStaffUnit(1, 12, $london);

        self::assertSame([$second], $this->bookings->resourcesOf(1, $this->book($this->cut, 12, '2026-11-16 07:00:00'))[0] ?? [], 'Awss works in London');
        self::assertSame([$first], $this->bookings->resourcesOf(1, $this->book($this->cut, 11, '2026-11-16 07:00:00'))[0] ?? [], 'Amro has no branch: the first free chair');
    }

    public function testTimeOffListsTheBookingsItCovers(): void
    {
        $covered = $this->book($this->cut, 11, '2026-11-16 07:00:00');
        $this->book($this->cut, 11, '2026-11-16 12:00:00');
        $handler = $this->handler(true);
        $response = $handler->createException(self::request('POST', ['profile_id' => 11, 'kind' => 'off', 'starts_at' => '2026-11-16T06:00:00Z', 'ends_at' => '2026-11-16T09:00:00Z']));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([$covered], array_column($body['covered_bookings'], 'id'));
        self::assertSame('A customer', $body['covered_bookings'][0]['customer']['name']);

        $open = json_decode((string) $handler->createException(self::request('POST', ['profile_id' => 11, 'kind' => 'open', 'starts_at' => '2026-11-16T06:00:00Z', 'ends_at' => '2026-11-16T09:00:00Z']))->getBody(), true);
        self::assertSame([], $open['covered_bookings'], 'extra hours cover nothing');
    }

    public function testAFailedCalendarIsKeptOnceByNameAndPruned(): void
    {
        $plugins = new CalendarPlugins();
        $plugins->addBusyPlugin('Google Calendar', new class implements PluginCalendarBusyInterface {
            public function busyTimes(int $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
            {
                throw new \RuntimeException('token expired');
            }
        });
        $this->service->setPlugins($plugins);
        $day = [$this->at('2026-11-16 00:00:00'), $this->at('2026-11-17 00:00:00')];
        self::assertNotSame([], $this->service->availability(1, $this->cut, 11, ...$day), 'booking stays open');
        $this->service->availability(1, $this->cut, 11, ...$day);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM calendar_busy_check_failures WHERE tenant_id = 1 AND profile_id = 11'), 'once inside five minutes');

        $failures = new BusyCheckFailures($this->pdo);
        $seen = $failures->recent(1, $this->at('2026-11-14 00:00:00'));
        self::assertSame([['profile_id' => 11, 'name' => 'Amro Saleh', 'source' => 'Google Calendar', 'failed_at' => '2026-11-15T05:00:00Z']], $seen, 'by name, never an id alone');
        self::assertSame([], $failures->recent(1, $this->at('2026-11-14 00:00:00'), 12), 'Awss sees only his own');
        self::assertSame([], $failures->recent(2, $this->at('2026-11-14 00:00:00')), 'another workspace sees nothing');

        $this->pdo->exec("INSERT INTO calendar_busy_check_failures (tenant_id, profile_id, source, failed_at) VALUES (1, 12, 'Google Calendar', '2026-11-07 05:00:00')");
        self::assertSame(2, $failures->countSince($this->at('2026-11-01 00:00:00')));
        self::assertSame(1, $failures->prune($this->at($this->now)), 'older than a week is deleted');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM calendar_busy_check_failures'));
    }

    public function testTheMigrationRunsTwiceAndRollsBack(): void
    {
        require_once dirname(__DIR__, 3) . '/database/migrations/367_add_calendar_staff_and_time.php';
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        AddCalendarStaffAndTime::up($db);
        AddCalendarStaffAndTime::up($db);
        self::assertSame(15000, (int) $this->scalar("SELECT price_minor FROM calendar_service_staff WHERE service_id = {$this->cut} AND profile_id = 12"));
        AddCalendarStaffAndTime::down($db);
        AddCalendarStaffAndTime::down($db);
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM calendar_service_staff WHERE service_id = {$this->cut}"), 'the people stay');
        AddCalendarStaffAndTime::up($db);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM calendar_busy_check_failures'));
        self::assertNull($this->scalar("SELECT price_minor FROM calendar_service_staff WHERE service_id = {$this->cut} AND profile_id = 12"));
    }

    // ---------------------------------------------------------------------

    private function newService(string $name, int $minutes, ?int $price): int
    {
        return $this->services->insert(1, [
            'name' => ['en' => $name], 'description' => [], 'duration_minutes' => $minutes, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => $price, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
    }

    private function branch(string $name): int
    {
        $this->pdo->exec("INSERT INTO organizational_units (tenant_id, name, slug, description, created_at)
            VALUES (1, '{$name}', '" . strtolower(str_replace(' ', '-', $name)) . "', '', CURRENT_TIMESTAMP)");

        return (int) $this->scalar("SELECT id FROM organizational_units WHERE tenant_id = 1 AND name = '{$name}'");
    }

    /**
     * @return array{
     *   service_id: int, profile_id: ?int, starts_at: DateTimeImmutable,
     *   customer_profile_id: ?int, customer_name: string, customer_email: string,
     *   customer_phone: string, customer_note: string, source: string,
     *   created_by_profile_id: ?int, enforce_hours: bool, public: bool, notify: bool
     * }
     */
    private function bookingRequest(?int $profile, string $startsUtc): array
    {
        return [
            'service_id' => $this->cut, 'profile_id' => $profile, 'starts_at' => $this->at($startsUtc),
            'customer_profile_id' => null, 'customer_name' => 'A customer', 'customer_email' => '',
            'customer_phone' => '', 'customer_note' => '', 'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => 11, 'enforce_hours' => false, 'public' => false, 'notify' => false,
        ];
    }

    private function book(int $service, int $profile, string $startsUtc): int
    {
        return (int) $this->service->book(1, ['service_id' => $service] + $this->bookingRequest($profile, $startsUtc))['id'];
    }

    private function handler(bool $manages): CalendarApiHandler
    {
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn($manages);
        TenantContext::setTenantId(1);

        return new CalendarApiHandler($this->calendar, $this->services, $this->bookings, $this->service, $this->settings, $roles);
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $body */
    private static function request(string $method, array $body): Request
    {
        $r = new Request($method, '/x', [], (string) json_encode($body));
        $r->user = (object) ['profile_id' => 11];

        return $r;
    }

    /** @return array<string, mixed> */
    private static function data(Response $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['data']);

        return $body['data'];
    }
}
