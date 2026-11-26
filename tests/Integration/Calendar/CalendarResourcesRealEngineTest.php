<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\CreateCalendarResources;
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
use Whity\Core\Calendar\RoomRepository;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Rooms and equipment (WHIT-1439, design section 39 §6, AP.18): any free one
 * from a group, several needs at once, moving keeps what it held, the desk
 * changes it, and the database guards every resource a booking takes. Real
 * engine (SQLite, or Postgres when PHPUNIT_PG_DSN is set).
 */
final class CalendarResourcesRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private BookingRepository $bookings;
    private CalendarRepository $calendar;
    private ServiceRepository $services;
    private RoomRepository $rooms;
    private SettingsService $settings;
    private string $now = '2026-11-15 05:00:00';
    private int $chair1;
    private int $chair2;
    private int $xray;
    private int $cleaning;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Dental', 'example-dental'), (2, 'Other Dental', 'other-dental')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11, 12, 13] as $profile) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, 'Dentist {$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setTenant(1, 'timezone', 'Asia/Amman');
        $this->settings->setTenant(1, 'calendar.min_notice_minutes', '0');
        $this->calendar = new CalendarRepository($this->pdo);
        $this->services = new ServiceRepository($this->pdo);
        $this->bookings = new BookingRepository($this->pdo);
        $this->rooms = new RoomRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $this->calendar, $this->services, $this->bookings, $this->settings, null,
            clock: fn (): DateTimeImmutable => new DateTimeImmutable($this->now, new DateTimeZone('UTC')));
        $resource = fn (string $en, string $kind, string $group, int $sort): int => $this->rooms->insert(1, [
            'name' => ['en' => $en], 'unit_id' => null, 'active' => true, 'sort_order' => $sort, 'kind' => $kind, 'group_key' => $group,
        ]);
        $this->chair1 = $resource('Chair 1', 'chair', 'Chairs', 1);
        $this->chair2 = $resource('Chair 2', 'chair', 'Chairs', 2);
        $this->xray = $resource('X-ray', 'equipment', '', 3);
        foreach ([11, 12, 13] as $dentist) {
            $this->calendar->ensureStaff(1, $dentist);
            $this->calendar->replaceHours(1, $dentist, array_map(static fn (int $d): array => ['weekday' => $d, 'start_time' => '08:00', 'end_time' => '18:00'], range(1, 7)));
        }
        $this->cleaning = $this->serviceNeeding('Cleaning', [['resource_id' => null, 'group_key' => 'Chairs']]);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAnyFreeChairIsTakenInOrderAndTheThirdIsRefused(): void
    {
        $a = $this->book($this->cleaning, 11, '2026-11-16 07:00:00');
        $b = $this->book($this->cleaning, 12, '2026-11-16 07:00:00');
        self::assertSame([$this->chair1], $this->held($a));
        self::assertSame([$this->chair2], $this->held($b), 'the next free chair');
        self::assertSame($this->chair2, $this->service->bookingOrFail(1, $b)['room_id'], 'room_id is the first resource');

        self::assertSame([], array_values(array_filter(
            $this->service->availability(1, $this->cleaning, 13, $this->at('2026-11-16 06:00:00'), $this->at('2026-11-16 09:00:00')),
            fn (array $slot): bool => $slot['starts_at'] === '2026-11-16T07:00:00Z',
        )), 'no chair free at 10:00 Amman: the time is not offered');

        try {
            $this->book($this->cleaning, 13, '2026-11-16 07:00:00');
            self::fail('Both chairs are taken');
        } catch (CalendarRejectedException $e) {
            self::assertSame(CalendarRejectedException::ROOM_TAKEN, $e->extra['reason'] ?? null);
        }
        $this->service->cancel(1, $a, 'staff', '');
        self::assertSame([$this->chair1], $this->held($this->book($this->cleaning, 13, '2026-11-16 07:00:00')), 'a freed chair is taken again');
    }

    public function testAServiceCanNeedSeveralAndAnInactiveChairIsNeverChosen(): void
    {
        $scan = $this->serviceNeeding('Scan and cleaning', [
            ['resource_id' => $this->xray, 'group_key' => null],
            ['resource_id' => null, 'group_key' => 'Chairs'],
        ]);
        $this->rooms->update(1, $this->chair1, ['name' => ['en' => 'Chair 1'], 'unit_id' => null, 'active' => false, 'sort_order' => 1, 'kind' => 'chair', 'group_key' => 'Chairs']);
        $id = $this->book($scan, 11, '2026-11-16 07:00:00');
        self::assertSame([$this->xray, $this->chair2], $this->held($id), 'the X-ray, then the active chair');
        self::assertSame(
            [['part' => 0, 'resource_id' => $this->xray], ['part' => 0, 'resource_id' => $this->chair2]],
            $this->service->bookingOrFail(1, $id)['resources'],
        );
        try {
            $this->book($this->cleaning, 12, '2026-11-16 07:15:00');
            self::fail('The only active chair is taken');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testAServiceWithOnlyARoomStillNeedsIt(): void
    {
        // Written before migration 365, or by something that knows only rooms.
        $old = $this->services->insert(1, [
            'name' => ['en' => 'X-ray only'], 'description' => [], 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => null, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0, 'room_id' => $this->xray,
        ]);
        $this->services->replaceStaff(1, $old, [11, 12]);
        $this->book($old, 11, '2026-11-16 07:00:00');
        $this->expectException(CalendarRejectedException::class);
        $this->book($old, 12, '2026-11-16 07:00:00');
    }

    public function testMovingKeepsTheChairWhenFreeAndTheDeskCanChangeIt(): void
    {
        $a = $this->book($this->cleaning, 11, '2026-11-16 07:00:00');
        $b = $this->book($this->cleaning, 12, '2026-11-16 07:00:00');
        self::assertSame([$this->chair2], $this->held($b));
        $this->service->move(1, $b, $this->at('2026-11-16 09:00:00'), null, false);
        self::assertSame([$this->chair2], $this->held($b), 'still chair 2, though chair 1 is free then');

        $this->service->changeResources(1, $b, [$this->chair1]);
        self::assertSame([$this->chair1], $this->held($b));
        $this->service->move(1, $b, $this->at('2026-11-16 07:00:00'), null, false);
        self::assertSame([$this->chair2], $this->held($b), 'chair 1 is taken at 07:00, so the other');

        try {
            $this->service->changeResources(1, $b, [$this->chair1]);
            self::fail('Chair 1 is taken then');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        try {
            $this->service->changeResources(1, $b, [$this->xray]);
            self::fail('The X-ray is not a chair');
        } catch (CalendarRejectedException $e) {
            self::assertSame(422, $e->status);
        }
        self::assertSame([$this->chair1], $this->held($a));
    }

    public function testTheServiceAndRoomApiSpeakNeedsKindsAndGroups(): void
    {
        $handler = new CalendarApiHandler($this->calendar, $this->services, $this->bookings, $this->service, $this->settings, $this->createStub(RoleChecker::class));
        TenantContext::setTenantId(1);
        $created = self::data($handler->createRoom(self::post(['name' => 'Chair 3', 'kind' => 'chair', 'group_key' => 'Chairs'])), 201);
        self::assertSame(['chair', 'Chairs'], [$created['kind'], $created['group_key']]);
        self::assertSame(422, $handler->createRoom(self::post(['name' => 'Bad', 'kind' => 'sofa']))->getStatusCode());

        $service = self::data($handler->createService(self::post([
            'name' => 'Whitening', 'duration_minutes' => 30, 'staff_profile_ids' => [11],
            'resources' => [['group_key' => 'Chairs'], ['resource_id' => $this->xray]],
        ])), 201);
        self::assertSame([['resource_id' => null, 'group_key' => 'Chairs'], ['resource_id' => $this->xray, 'group_key' => null]], $service['resources']);
        self::assertSame($this->xray, $service['room_id'], 'room_id: the first specific one');
        self::assertSame(422, $handler->createService(self::post(['name' => 'X', 'duration_minutes' => 30, 'staff_profile_ids' => [11], 'resources' => [['group_key' => 'Sofas']]]))->getStatusCode());

        $old = self::data($handler->createService(self::post(['name' => 'Old client', 'duration_minutes' => 30, 'staff_profile_ids' => [11], 'room_id' => $this->xray])), 201);
        self::assertSame([['resource_id' => $this->xray, 'group_key' => null]], $old['resources'], 'room_id from an older client is the one need');
    }

    public function testTheDatabaseGuardsEveryResourceABookingHolds(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('The holds and their constraint are PostgreSQL\'s.');
        }
        $scan = $this->serviceNeeding('Scan and cleaning', [
            ['resource_id' => $this->xray, 'group_key' => null],
            ['resource_id' => null, 'group_key' => 'Chairs'],
        ]);
        $a = $this->book($scan, 11, '2026-11-16 07:00:00');
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(DISTINCT holder_id) FROM calendar_holds WHERE booking_id = {$a} AND holder_kind = 'resource'"), 'the X-ray and the chair are held');

        // Written past the service: another booking, then chair 1 straight into its resources.
        $b = $this->book($this->cleaning, 12, '2026-11-16 09:00:00');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("INSERT INTO calendar_booking_resources (tenant_id, booking_id, resource_id, part, blocks_from, blocks_until)
                VALUES (1, {$b}, {$this->chair1}, 0, '2026-11-16 07:00:00', '2026-11-16 07:30:00')");
            $this->pdo->exec('SET CONSTRAINTS calendar_holds_no_overlap IMMEDIATE');
            self::fail('The database let two bookings hold chair 1 at once');
        } catch (\PDOException $e) {
            self::assertSame('23P01', $e->getCode());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testTheMigrationRenamesKeepsIdsAndReverses(): void
    {
        $old = $this->services->insert(1, [
            'name' => ['en' => 'X-ray only'], 'description' => [], 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => null, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0, 'room_id' => $this->xray,
        ]);
        require_once dirname(__DIR__, 3) . '/database/migrations/365_create_calendar_resources.php';
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        self::assertSame('X-ray', json_decode((string) $this->scalar("SELECT name FROM calendar_rooms WHERE id = {$this->xray}"), true)['en'] ?? null, 'the old name reads the same rows');
        CreateCalendarResources::up($db); // twice is fine
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM calendar_service_resources WHERE service_id = {$old} AND resource_id = {$this->xray}"), 'its room is its first need, once');

        // down() needs nothing written by 366 in the way; drop that first.
        $this->pdo->exec('DROP TABLE calendar_booking_resources');
        CreateCalendarResources::down($db);
        self::assertSame('Chair 1', json_decode((string) $this->scalar("SELECT name FROM calendar_rooms WHERE id = {$this->chair1}"), true)['en'] ?? null);
        CreateCalendarResources::up($db);
        self::assertSame('room', $this->scalar("SELECT kind FROM calendar_resources WHERE id = {$this->chair1}"), 'down() dropped the kind; up() starts every resource as a room');
    }

    // ---------------------------------------------------------------------

    /** @param list<array{resource_id: ?int, group_key: ?string}> $needs */
    private function serviceNeeding(string $name, array $needs): int
    {
        $id = $this->services->insert(1, [
            'name' => ['en' => $name], 'description' => [], 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => null, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->services->replaceStaff(1, $id, [11, 12, 13]);
        $this->rooms->replaceRequirements(1, $id, $needs);

        return $id;
    }

    private function book(int $service, int $profile, string $startsUtc): int
    {
        return (int) $this->service->book(1, [
            'service_id' => $service, 'profile_id' => $profile, 'starts_at' => $this->at($startsUtc),
            'customer_profile_id' => null, 'customer_name' => 'A patient', 'customer_email' => '',
            'customer_phone' => '', 'customer_note' => '', 'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => 11, 'enforce_hours' => false, 'public' => false, 'notify' => false,
        ])['id'];
    }

    /** @return list<int> */
    private function held(int $bookingId): array
    {
        return $this->bookings->resourcesOf(1, $bookingId)[0] ?? [];
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
    private static function post(array $body): Request
    {
        $r = new Request('POST', '/x', [], (string) json_encode($body));
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
