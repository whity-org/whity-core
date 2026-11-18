<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\HostCalendarEvents;
use Whity\Core\Calendar\HostTimeOff;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Delegation\AwayService;
use Whity\Core\Delegation\HostAway;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Calendar\TimeOff;
use Whity\Sdk\Calendar\TimeOffRejected;
use Whity\Sdk\Delegation\Away;

/**
 * SDK 1.120 against the real schema (WHIT-1210, issue #2132): a leave plugin
 * puts approved leave on a person's calendar as real time off, the away lookup
 * sees it, and withdrawing the leave removes it.
 */
final class TimeOffSdkRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const AMRO = 11;      // asks for leave
    private const AWSS = 12;      // HR: calendar:manage
    private const AHMAD = 13;     // a colleague: calendar:write only
    private const IBRAHIM = 14;   // a customer, not staff
    private const EMAD = 15;      // another workspace's staff

    private PDO $pdo;
    private CalendarRepository $calendar;
    private BookingService $bookings;
    private TimeOff $timeOff;
    private Away $away;
    private AwayService $awayService;
    /** @var array<int, list<string>> */
    private array $grants = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        $people = [
            self::AMRO => ['Amro Saleh', self::TENANT, 'member'],
            self::AWSS => ['Awss Haddad', self::TENANT, 'member'],
            self::AHMAD => ['Ahmad Khalil', self::TENANT, 'member'],
            self::IBRAHIM => ['Ibrahim Mansour', self::TENANT, 'customer'],
            self::EMAD => ['Emad Nasser', self::OTHER_TENANT, 'member'],
        ];
        foreach ($people as $id => [$name, $tenant, $kind]) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
            $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES (?, ?, 901, 'active', ?, CURRENT_TIMESTAMP)")
                ->execute([$id, $tenant, $kind]);
        }
        $this->grants = [
            self::AMRO => [CorePermissions::CALENDAR_WRITE],
            self::AWSS => [CorePermissions::CALENDAR_WRITE, CorePermissions::CALENDAR_MANAGE],
            self::AHMAD => [CorePermissions::CALENDAR_WRITE],
        ];

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->calendar = new CalendarRepository($this->pdo);
        $this->bookings = new BookingService($this->pdo, $this->calendar, new ServiceRepository($this->pdo), new BookingRepository($this->pdo), $settings, null);

        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            fn (int $profileId, string $permission, int $tenantId): bool => $tenantId === self::TENANT && in_array($permission, $this->grants[$profileId] ?? [], true)
        );
        $this->timeOff = new HostTimeOff($this->bookings, $this->calendar, $roles);
        $this->awayService = new AwayService($this->pdo);
        $this->away = new HostAway($this->awayService, $this->calendar);
    }

    public function testApprovedLeaveIsTimeOffTheAwayLookupSeesAndWithdrawingRemovesIt(): void
    {
        // HR approves Amro's leave in the plugin: 22 to 24 November, whole days in Amman.
        $id = $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00+03:00', '2026-11-25T00:00:00+03:00', 'Annual leave', self::AWSS, 'keyhub.leave:123');

        // Real time off: kind time_off, busy, made by HR, carrying the reference.
        $stmt = $this->pdo->prepare('SELECT kind, busy, external_ref, created_by_profile_id, title, notes FROM calendar_events WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(CalendarRepository::KIND_TIME_OFF, $row['kind']);
        self::assertTrue((bool) (int) $row['busy']);
        self::assertSame('keyhub.leave:123', $row['external_ref']);
        self::assertSame(self::AWSS, (int) $row['created_by_profile_id']);
        self::assertSame('Annual leave', $row['notes']);

        // "Who's in" and the time-off API read it as time off ("off"), bookings as busy.
        $from = new DateTimeImmutable('2026-11-23T00:00:00Z');
        $to = new DateTimeImmutable('2026-11-24T00:00:00Z');
        $exceptions = $this->calendar->exceptions(self::TENANT, self::AMRO, $from, $to);
        self::assertCount(1, $exceptions);
        self::assertSame('off', $exceptions[0]['kind']);
        self::assertNotSame([], $this->calendar->busy(self::TENANT, self::AMRO, $from, $to, new DateTimeImmutable('2026-11-18T00:00:00Z')));

        self::assertSame([[
            'id' => $id,
            'profileId' => self::AMRO,
            'startUtc' => '2026-11-21T21:00:00Z',
            'endUtc' => '2026-11-24T21:00:00Z',
            'reason' => 'Annual leave',
            'externalRef' => 'keyhub.leave:123',
        ]], $this->timeOff->forPerson(self::TENANT, self::AMRO, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'));

        // The away lookup sees it; there is no stand-in without "I'm away".
        self::assertSame([
            'from' => '2026-11-21T21:00:00Z',
            'until' => '2026-11-24T21:00:00Z',
            'standInProfileId' => null,
            'covers' => [],
            'source' => 'time_off',
            'timeOffId' => $id,
        ], $this->away->at(self::TENANT, self::AMRO, '2026-11-23T10:00:00+03:00'));
        self::assertNull($this->away->at(self::TENANT, self::AMRO, '2026-11-25T09:00:00+03:00'), 'back on the 25th');

        // The request is withdrawn: the plugin finds it by its reference and cancels it.
        $found = $this->timeOff->byExternalRef(self::TENANT, 'keyhub.leave:123');
        self::assertNotNull($found);
        self::assertTrue($this->timeOff->cancel(self::TENANT, $found['id'], self::AWSS));

        self::assertNull($this->timeOff->byExternalRef(self::TENANT, 'keyhub.leave:123'));
        self::assertSame([], $this->timeOff->forPerson(self::TENANT, self::AMRO, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'));
        self::assertSame([], $this->calendar->exceptions(self::TENANT, self::AMRO, $from, $to));
        self::assertNull($this->away->at(self::TENANT, self::AMRO, '2026-11-23T10:00:00+03:00'));
        self::assertFalse($this->timeOff->cancel(self::TENANT, $id, self::AWSS), 'cancelling twice is a safe no');
    }

    public function testARetryNeverDoublesTheTimeOffAndAChangeMovesIt(): void
    {
        $first = $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', 'Leave', self::AWSS, 'keyhub.leave:7');
        $again = $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', 'Leave', self::AWSS, 'keyhub.leave:7');
        self::assertSame($first, $again);

        $moved = $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-24T00:00:00Z', 'Leave, two days', self::AWSS, 'keyhub.leave:7');
        self::assertSame($first, $moved);
        self::assertSame(1, $this->rowsWhere("SELECT COUNT(*) FROM calendar_events WHERE external_ref = 'keyhub.leave:7'"));
        $row = $this->timeOff->byExternalRef(self::TENANT, 'keyhub.leave:7');
        self::assertNotNull($row);
        self::assertSame('2026-11-24T00:00:00Z', $row['endUtc']);
        self::assertSame('Leave, two days', $row['reason']);

        // Once cancelled, the reference is free again.
        $this->timeOff->cancel(self::TENANT, $first, self::AWSS);
        self::assertNotSame($first, $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', 'Leave', self::AWSS, 'keyhub.leave:7'));
    }

    public function testTheDatabaseRefusesASecondLiveTimeOffWithTheSameReference(): void
    {
        $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', 'Leave', self::AWSS, 'keyhub.leave:8');

        $this->expectException(\PDOException::class);
        $this->calendar->insertEvent(self::TENANT, self::AHMAD, 'Leave', '', new \Whity\Core\Calendar\Interval(
            new DateTimeImmutable('2026-11-22T00:00:00Z'),
            new DateTimeImmutable('2026-11-23T00:00:00Z'),
        ), false, true, 'keyhub.leave:8', null, kind: CalendarRepository::KIND_TIME_OFF);
    }

    public function testASyncPluginsReferenceIsNotTimeOff(): void
    {
        // A busy event from CalendarEvents is not time off, even with the same reference.
        $sync = new HostCalendarEvents($this->bookings, $this->calendar);
        $eventId = $sync->upsertExternal(self::TENANT, self::AMRO, 'keyhub.leave:9', 'Busy', new DateTimeImmutable('2026-11-22T00:00:00Z'), new DateTimeImmutable('2026-11-23T00:00:00Z'));

        self::assertNull($this->timeOff->byExternalRef(self::TENANT, 'keyhub.leave:9'));
        self::assertNull($this->away->at(self::TENANT, self::AMRO, '2026-11-22T10:00:00Z'));
        self::assertFalse($this->timeOff->cancel(self::TENANT, $eventId, self::AWSS), 'not time off');
        self::assertNotSame($eventId, $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', 'Leave', self::AWSS, 'keyhub.leave:9'));
    }

    public function testWritesUseTheTimeOffRoutesChecks(): void
    {
        // One's own time off needs calendar:write.
        self::assertGreaterThan(0, $this->timeOff->create(self::TENANT, self::AHMAD, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AHMAD));

        // Somebody else's needs calendar:manage too.
        self::assertSame(TimeOffRejected::FORBIDDEN, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AHMAD)));
        $id = $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AWSS);
        self::assertSame(TimeOffRejected::FORBIDDEN, $this->reason(fn () => $this->timeOff->cancel(self::TENANT, $id, self::AHMAD)));
        self::assertSame(TimeOffRejected::FORBIDDEN, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', 0)));
        self::assertTrue($this->timeOff->cancel(self::TENANT, $id, self::AMRO), 'Amro may remove his own');

        // Only staff of this workspace; another workspace's time off is not here.
        self::assertSame(TimeOffRejected::NOT_STAFF, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::IBRAHIM, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AWSS)));
        self::assertSame(TimeOffRejected::NOT_STAFF, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::EMAD, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AWSS)));
        $mine = $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AWSS, 'keyhub.leave:10');
        self::assertFalse($this->timeOff->cancel(self::OTHER_TENANT, $mine, self::AWSS));
        self::assertNull($this->timeOff->byExternalRef(self::OTHER_TENANT, 'keyhub.leave:10'));
        self::assertSame(1, $this->rowsWhere("SELECT COUNT(*) FROM calendar_events WHERE id = {$mine} AND status = 'confirmed'"));
    }

    public function testRefusalsWriteNothing(): void
    {
        $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AWSS, 'keyhub.leave:11');
        $before = $this->rowsWhere('SELECT COUNT(*) FROM calendar_events');

        self::assertSame(TimeOffRejected::REF_CONFLICT, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::AHMAD, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AWSS, 'keyhub.leave:11')));
        self::assertSame(TimeOffRejected::INVALID_TIMES, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00', '2026-11-23T00:00:00Z', '', self::AWSS)), 'no offset');
        self::assertSame(TimeOffRejected::INVALID_TIMES, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-23T00:00:00Z', '2026-11-22T00:00:00Z', '', self::AWSS)), 'out of order');
        self::assertSame(TimeOffRejected::INVALID_TIMES, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::AMRO, '2026-01-01T00:00:00Z', '2027-01-03T00:00:00Z', '', self::AWSS)), 'over a year');
        self::assertSame(TimeOffRejected::INVALID_REF, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AWSS, '')));
        self::assertSame(TimeOffRejected::INVALID_REF, $this->reason(fn () => $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-23T00:00:00Z', '', self::AWSS, str_repeat('x', 256))));

        self::assertSame($before, $this->rowsWhere('SELECT COUNT(*) FROM calendar_events'));
    }

    public function testAnAwayPeriodNamesTheStandInAndWhetherPluginApprovalsGoToThem(): void
    {
        $today = '2026-11-18';
        $period = $this->awayService->start(self::TENANT, self::AMRO, self::AWSS, '2026-11-22', '2026-11-26', [AwayService::FLOWS, AwayService::PLUGINS], null, $today);
        self::assertIsArray($period);
        self::assertSame(['flows', 'plugins'], $period['covers']);
        $timeOffId = $this->timeOff->create(self::TENANT, self::AMRO, '2026-11-22T00:00:00Z', '2026-11-24T00:00:00Z', 'Leave', self::AWSS, 'keyhub.leave:12');

        self::assertSame([
            'from' => '2026-11-22T00:00:00Z',
            'until' => '2026-11-26T00:00:00Z',
            'standInProfileId' => self::AWSS,
            'covers' => ['flows', 'plugins'],
            'source' => 'away',
            'timeOffId' => $timeOffId,
        ], $this->away->at(self::TENANT, self::AMRO, '2026-11-23T12:00:00Z'));
        self::assertSame(null, $this->away->at(self::TENANT, self::AMRO, '2026-11-25T12:00:00Z')['timeOffId'] ?? null, 'away without time off on the 25th');
        self::assertSame([self::AMRO], $this->awayService->coveredBy(self::TENANT, self::AWSS, AwayService::PLUGINS, '2026-11-23'));
        self::assertNull($this->away->at(self::TENANT, self::AMRO, '2026-11-21T23:59:59Z'), 'before it starts');
        self::assertNull($this->away->at(self::TENANT, self::AMRO, '2026-11-26T00:00:00Z'), 'back on the 26th');

        // Coming back early ends it; the time off still says away, without a stand-in.
        $this->awayService->end(self::TENANT, self::AMRO);
        self::assertSame('time_off', $this->away->at(self::TENANT, self::AMRO, '2026-11-23T12:00:00Z')['source'] ?? null);
        self::assertNull($this->away->at(self::OTHER_TENANT, self::AMRO, '2026-11-23T12:00:00Z'), 'another workspace knows nothing');
    }

    public function testAwayAtNeedsAnInstantWithAnOffset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->away->at(self::TENANT, self::AMRO, '2026-11-23');
    }

    private function rowsWhere(string $sql): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function reason(callable $call): string
    {
        try {
            $call();
        } catch (TimeOffRejected $e) {
            return $e->reason;
        }

        return 'not refused';
    }
}
