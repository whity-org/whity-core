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
use Whity\Core\Calendar\CalendarItems;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\EventPlace;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\RoomRepository;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * One calendar for everything with a time (ADR 0018, WHIT-1109): events have
 * a kind and invited people, the items list joins bookings and events in one
 * shape, and free/busy counts people invited and rooms held.
 *
 * Example Bakery (tenant 1): bakers 11 and 12 and Amro (13), working Sunday to
 * Thursday 09:00-17:00 in Amman. Tenant 2 has its own baker, 21.
 */
final class OneCalendarRealEngineTest extends TestCase
{
    /** Sunday 15 November 2026, 07:00 in Amman. */
    private const NOW = '2026-11-15 04:00:00';

    private PDO $pdo;
    private BookingService $service;
    private CalendarRepository $calendar;
    private ServiceRepository $services;
    private BookingRepository $bookings;
    private RoomRepository $rooms;
    private int $cake;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 12 => 1, 13 => 1, 21 => 2] as $profile => $tenant) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$profile}, 'p{$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $this->pdo->exec(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
                 VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)"
            );
        }

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        foreach ([1, 2] as $tenant) {
            $settings->setTenant($tenant, 'timezone', 'Asia/Amman');
            $settings->setTenant($tenant, 'calendar.min_notice_minutes', '60');
            $settings->setTenant($tenant, 'calendar.slot_step_minutes', '30');
        }
        $this->calendar = new CalendarRepository($this->pdo);
        $this->services = new ServiceRepository($this->pdo);
        $this->bookings = new BookingRepository($this->pdo);
        $this->rooms = new RoomRepository($this->pdo);
        $this->service = new BookingService(
            $this->pdo,
            $this->calendar,
            $this->services,
            $this->bookings,
            $settings,
            null,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );
        $this->cake = $this->services->insert(1, [
            'name' => ['en' => 'Cake tasting', 'ar' => 'تذوق الكعك'], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 12500, 'currency' => 'USD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->services->replaceStaff(1, $this->cake, [11, 12]);
        foreach ([11, 12, 13] as $person) {
            $this->calendar->ensureStaff(1, $person);
            $this->calendar->replaceHours(1, $person, array_map(
                static fn (int $day): array => ['weekday' => $day, 'start_time' => '09:00', 'end_time' => '17:00'],
                [7, 1, 2, 3, 4],
            ));
        }
        $this->calendar->ensureStaff(2, 21);
    }

    public function testExistingEventsKeepTheirMeaningAsKinds(): void
    {
        $team = $this->event(11, 'Oven service', '2026-11-16T09:00:00+03:00', '2026-11-16T10:00:00+03:00');
        $synced = $this->calendar->insertEvent(1, 11, 'Dentist', '', $this->when('2026-11-16T13:00:00+03:00', '2026-11-16T14:00:00+03:00'), false, true, 'google:abc', 11);

        self::assertSame('event', $this->calendar->findEvent(1, $team)['kind'] ?? null);
        self::assertSame('external', $this->calendar->findEvent(1, $synced)['kind'] ?? null);
    }

    public function testTheMigrationCanRunAgain(): void
    {
        $this->event(11, 'Oven service', '2026-11-16T09:00:00+03:00', '2026-11-16T10:00:00+03:00');
        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();
        require_once dirname(__DIR__, 3) . '/database/migrations/284_extend_calendar_events.php';
        \Database\Migrations\ExtendCalendarEvents::up($db);

        $count = $this->pdo->query('SELECT COUNT(*) FROM calendar_events');
        self::assertNotFalse($count);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testAnInvitedPersonIsBusyUnlessTheyDecline(): void
    {
        // Amro organises; both bakers are invited, Monday 10:00-11:00 in Amman.
        $id = $this->event(13, 'Menu tasting', '2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00', people: [11, 12]);
        self::assertSame([], $this->free(11), 'baker 11 is invited');
        self::assertSame([], $this->free(12), 'baker 12 is invited');

        self::assertTrue($this->calendar->answerEvent(1, $id, 12, 'declined'));
        self::assertSame(['2026-11-16T07:00:00Z', '2026-11-16T07:30:00Z'], $this->free(12), 'baker 12 declined');
        self::assertSame([], $this->free(11));
        self::assertFalse($this->calendar->answerEvent(1, $id, 21, 'accepted'), 'only people invited answer');
    }

    public function testChangingWhoIsInvitedKeepsAnswersAndNeverListsTheOrganiser(): void
    {
        $id = $this->event(13, 'Menu tasting', '2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00', people: [11, 12, 13]);
        $this->calendar->answerEvent(1, $id, 11, 'accepted');

        $this->service->updateEvent(1, $id, 'Menu tasting', '', $this->when('2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00'), false, true, people: [11]);

        self::assertSame(
            [['profile_id' => 11, 'role' => 'required', 'response' => 'accepted']],
            $this->calendar->findEvent(1, $id)['people'] ?? null,
        );
    }

    public function testAnEventHeldInARoomBlocksTheRoom(): void
    {
        $room = $this->rooms->insert(1, ['name' => ['en' => 'Tasting room'], 'unit_id' => null, 'active' => true, 'sort_order' => 0]);
        $this->service->createEvent(1, 13, 'Supplier visit', '', $this->when('2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00'), false, true, 13, place: new EventPlace($room, 'Tasting room'));

        $busy = $this->rooms->busy(1, $room, $this->at('2026-11-16T00:00:00+03:00'), $this->at('2026-11-17T00:00:00+03:00'), $this->at('2026-11-15T07:00:00+03:00'));
        self::assertCount(1, $busy);
        self::assertSame('2026-11-16T07:00:00Z', Interval::toApi($busy[0]->start));
    }

    public function testBookingsAndEventsComeAsOneListWithOneShape(): void
    {
        $booking = $this->book(11, '2026-11-16T12:00:00+03:00');
        $event = $this->event(13, 'Menu tasting', '2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00', people: [11]);

        $items = $this->items(viewer: 11);
        self::assertSame(['event:' . $event, 'booking:' . $booking], array_column($items, 'id'), 'oldest start first');
        self::assertSame(['event', 'booking'], array_column($items, 'kind'));
        self::assertSame('Cake tasting', $items[1]['title']);
        self::assertSame(11, $items[1]['organiser_id']);
        self::assertNotNull($items[1]['booking']);
        self::assertSame([['profile_id' => 11, 'role' => 'required', 'response' => 'invited']], $items[0]['people']);

        // Baker 11's calendar includes the event they are invited to.
        self::assertSame(['event:' . $event, 'booking:' . $booking], array_column($this->items(viewer: 11, people: [11]), 'id'));
        self::assertSame(['booking:' . $booking], array_column($this->items(viewer: 11, kinds: ['booking']), 'id'));
    }

    public function testColleaguesSeeAwayAndBusyButNoDetails(): void
    {
        $this->calendar->insertEvent(1, 12, 'Hospital appointment', 'Back by noon', $this->when('2026-11-16T09:00:00+03:00', '2026-11-16T10:00:00+03:00'), false, true, null, 12, kind: CalendarRepository::KIND_TIME_OFF);
        $this->calendar->insertEvent(1, 12, 'Private lunch', 'Somewhere nice', $this->when('2026-11-16T13:00:00+03:00', '2026-11-16T14:00:00+03:00'), false, true, 'google:xyz', 12);

        $theirs = $this->items(viewer: 11);
        self::assertSame([CalendarItems::AWAY, CalendarItems::BUSY], array_column($theirs, 'title'));
        self::assertSame(['', ''], array_column(array_column($theirs, 'event'), 'notes'));
        self::assertSame([false, false], array_column(array_column($theirs, 'can'), 'open'));

        $mine = $this->items(viewer: 12);
        self::assertSame(['Hospital appointment', 'Private lunch'], array_column($mine, 'title'));
        self::assertSame('Back by noon', $mine[0]['event']['notes']);
    }

    /**
     * WHIT-1285 (#2237): GET /api/calendar/events and /exceptions keep the
     * grid's privacy. A colleague's synced event is Busy with no notes, place
     * or source; why someone is away is theirs and their managers'.
     */
    public function testTheEventAndExceptionListsKeepColleaguesPrivate(): void
    {
        $this->calendar->insertEvent(1, 12, 'Private lunch', 'Somewhere nice', $this->when('2026-11-16T13:00:00+03:00', '2026-11-16T14:00:00+03:00'), false, true, 'google:xyz', 12, kind: CalendarRepository::KIND_EXTERNAL);
        $this->calendar->addException(1, 12, 'off', $this->when('2026-11-17T09:00:00+03:00', '2026-11-17T17:00:00+03:00'), 'Hospital appointment', 12);
        $managers = new class () {
            /** @var list<int> */
            public array $ids = [];
        };
        $roles = $this->createStub(\Whity\Auth\RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(static function (int $profileId) use ($managers): bool {
            return in_array($profileId, $managers->ids, true);
        });
        $handler = new \Whity\Api\CalendarApiHandler(
            $this->calendar, $this->services, $this->bookings, $this->service,
            new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)),
            $roles,
        );
        $list = static function (string $what, int $viewer) use ($handler): array {
            $request = new \Whity\Core\Request('GET', '/api/calendar/' . $what . '?from=' . rawurlencode('2026-11-15T00:00:00+03:00') . '&to=' . rawurlencode('2026-11-20T00:00:00+03:00'));
            $request->user = (object) ['profile_id' => $viewer];
            $method = $what === 'events' ? 'listEvents' : 'listExceptions';
            $response = $handler->{$method}($request);
            self::assertSame(200, $response->getStatusCode(), $response->getBody());

            return json_decode($response->getBody(), true)['data'];
        };

        \Whity\Core\Tenant\TenantContext::reset();
        \Whity\Core\Tenant\TenantContext::setTenantId(1);
        try {
            $seen = $list('events', 11);
            self::assertSame([CalendarItems::BUSY, '', '', null], [$seen[0]['title'], $seen[0]['notes'], $seen[0]['location'], $seen[0]['external_ref']]);
            self::assertStringNotContainsString('lunch', (string) json_encode($seen));
            self::assertSame('Private lunch', $list('events', 12)[0]['title'], 'their own');

            self::assertSame('', $list('exceptions', 11)[0]['note']);
            self::assertSame('Hospital appointment', $list('exceptions', 12)[0]['note'], 'their own');
            $managers->ids = [11];
            self::assertSame('Hospital appointment', $list('exceptions', 11)[0]['note'], 'someone who manages the calendar');
        } finally {
            \Whity\Core\Tenant\TenantContext::reset();
        }
    }

    public function testWhatTheViewerMayChange(): void
    {
        $team = $this->event(12, 'Oven service', '2026-11-16T09:00:00+03:00', '2026-11-16T10:00:00+03:00');
        $this->calendar->insertEvent(1, 11, 'Dentist', '', $this->when('2026-11-16T13:00:00+03:00', '2026-11-16T14:00:00+03:00'), false, true, 'google:abc', 11);
        $byId = static fn (array $items): array => array_column($items, 'can', 'id');

        $asBaker11 = $byId($this->items(viewer: 11));
        self::assertFalse($asBaker11['event:' . $team]['move'], 'someone else\'s event');
        self::assertFalse(array_values(array_filter($asBaker11, static fn (string $k): bool => str_starts_with($k, 'external:'), ARRAY_FILTER_USE_KEY))[0]['move'], 'a synced event changes where it came from');

        $asManager = $byId($this->items(viewer: 11, manages: true));
        self::assertTrue($asManager['event:' . $team]['move']);
    }

    public function testTimeOffIsAnEventThatBlocksAndTheTimeOffApiStillReadsIt(): void
    {
        $id = $this->calendar->addException(1, 11, 'off', $this->when('2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00'), 'Doctor', 11);

        self::assertSame('time_off', $this->calendar->findEvent(1, $id)['kind'] ?? null);
        self::assertSame([], $this->free(11), 'time off blocks bookings');
        $listed = $this->calendar->exceptions(1, 11, $this->at('2026-11-16T00:00:00+03:00'), $this->at('2026-11-17T00:00:00+03:00'));
        self::assertSame([['id' => $id, 'kind' => 'off', 'note' => 'Doctor']], array_map(static fn (array $r): array => ['id' => $r['id'], 'kind' => $r['kind'], 'note' => $r['note']], $listed));
        self::assertSame(['time_off:' . $id], array_column($this->items(viewer: 11), 'id'));

        // The events list keeps its old meaning: no time off there.
        self::assertSame([], $this->calendar->listEvents(1, [11], $this->at('2026-11-16T00:00:00+03:00'), $this->at('2026-11-17T00:00:00+03:00'), [CalendarRepository::KIND_EVENT, CalendarRepository::KIND_EXTERNAL, CalendarRepository::KIND_MEETING]));

        $this->calendar->deleteException(1, $id);
        self::assertSame(['2026-11-16T07:00:00Z', '2026-11-16T07:30:00Z'], $this->free(11), 'removing it frees the time');
        self::assertNull($this->calendar->findException(1, $id));
    }

    public function testExtraHoursWidenTheDayAndNeverShowOnTheCalendar(): void
    {
        // Friday 20 November is not a working day.
        $friday = fn (): array => array_column($this->service->availability(1, $this->cake, 11, $this->at('2026-11-20T00:00:00+03:00'), $this->at('2026-11-21T00:00:00+03:00')), 'starts_at');
        self::assertSame([], $friday());

        $this->calendar->addException(1, 11, 'open', $this->when('2026-11-20T10:00:00+03:00', '2026-11-20T11:00:00+03:00'), '', 11);

        self::assertSame(['2026-11-20T07:00:00Z', '2026-11-20T07:30:00Z'], $friday());
        self::assertSame([], (new CalendarItems($this->bookings, $this->calendar))->between(1, 11, $this->at('2026-11-20T00:00:00+03:00'), $this->at('2026-11-21T00:00:00+03:00')));
    }

    public function testAScheduledMeetingIsACalendarEventForEveryoneInvited(): void
    {
        $meeting = $this->meeting(created: 13, invited: [11 => 'accepted', 12 => 'invited']);
        $eventId = (new \Whity\Core\Convening\MeetingCalendar($this->pdo))->sync(1, $meeting);
        self::assertNotNull($eventId);

        $event = $this->calendar->findEvent(1, $eventId);
        self::assertSame('meeting', $event['kind'] ?? null);
        self::assertSame('Board meeting', $event['title'] ?? null);
        self::assertSame('2026-11-16T08:00:00Z', $event['ends_at'] ?? null, '60 minutes when only a start is set');
        self::assertSame('Meeting room 2', $event['location'] ?? null);
        self::assertSame([[11, 'accepted'], [12, 'invited']], array_map(static fn (array $p): array => [$p['profile_id'], $p['response']], $event['people'] ?? []));

        self::assertSame([], $this->free(11), 'invited and coming');
        self::assertSame([], $this->free(12), 'invited, no answer yet');
        self::assertSame(['meeting:' . $eventId], array_column($this->items(viewer: 12, people: [12]), 'id'));
        self::assertFalse($this->items(viewer: 13)[0]['can']['move'], 'a meeting moves on the meeting');
    }

    public function testAnAnswerOnTheCalendarIsTheAnswerToTheInvitation(): void
    {
        $meeting = $this->meeting(created: 13, invited: [11 => 'invited']);
        $eventId = (int) (new \Whity\Core\Convening\MeetingCalendar($this->pdo))->sync(1, $meeting);

        self::assertTrue($this->calendar->answerEvent(1, $eventId, 11, 'declined'));

        $status = $this->pdo->query("SELECT status FROM meeting_invitations WHERE meeting_id = {$meeting} AND profile_id = 11");
        self::assertNotFalse($status);
        self::assertSame('declined', $status->fetchColumn());
        self::assertSame(['2026-11-16T07:00:00Z', '2026-11-16T07:30:00Z'], $this->free(11));
    }

    public function testRescheduledAndCancelledMeetingsKeepOneEvent(): void
    {
        $meeting = $this->meeting(created: 13, invited: [11 => 'accepted']);
        $sync = new \Whity\Core\Convening\MeetingCalendar($this->pdo);
        $first = $sync->sync(1, $meeting);

        $this->pdo->exec("UPDATE meetings SET scheduled_at = '2026-11-17 07:00:00' WHERE id = {$meeting}");
        self::assertSame($first, $sync->sync(1, $meeting));
        self::assertSame('2026-11-17T07:00:00Z', $this->calendar->findEvent(1, (int) $first)['starts_at'] ?? null);

        $this->pdo->exec("UPDATE meetings SET status = 'cancelled' WHERE id = {$meeting}");
        $sync->sync(1, $meeting);
        self::assertSame('cancelled', $this->calendar->findEvent(1, (int) $first)['status'] ?? null);
        $count = $this->pdo->query('SELECT COUNT(*) FROM calendar_events WHERE meeting_id = ' . $meeting);
        self::assertNotFalse($count);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testExistingMeetingsArePutOnTheCalendarOnce(): void
    {
        $scheduled = $this->meeting(created: null, invited: [11 => 'accepted', 12 => 'declined'], chair: 12);
        $this->pdo->exec("INSERT INTO meetings (id, tenant_id, body_id, meeting_number, title, status) VALUES (899, 1, 501, 9, 'Draft', 'draft')");

        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();
        require_once dirname(__DIR__, 3) . '/database/migrations/286_schedule_meetings_as_calendar_events.php';
        \Database\Migrations\ScheduleMeetingsAsCalendarEvents::up($db);
        \Database\Migrations\ScheduleMeetingsAsCalendarEvents::up($db);

        $rows = $this->pdo->query('SELECT meeting_id, profile_id FROM calendar_events WHERE meeting_id IS NOT NULL');
        self::assertNotFalse($rows);
        self::assertSame([[(string) $scheduled, '12']], array_map(static fn (array $r): array => [(string) $r['meeting_id'], (string) $r['profile_id']], $rows->fetchAll(PDO::FETCH_ASSOC)), 'one event, organised by the chair; the draft has none');
        self::assertSame([], $this->free(11));
        self::assertSame(['2026-11-16T07:00:00Z', '2026-11-16T07:30:00Z'], $this->free(12), 'the chair declined, so is free');
    }

    public function testAnotherWorkspaceSeesNothing(): void
    {
        $this->event(13, 'Menu tasting', '2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00', people: [11]);
        $this->book(11, '2026-11-16T12:00:00+03:00');

        self::assertSame([], (new CalendarItems($this->bookings, $this->calendar))->between(2, 21, $this->at('2026-11-16T00:00:00+03:00'), $this->at('2026-11-17T00:00:00+03:00')));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * @param list<int>    $people
     */
    private function event(int $organiser, string $title, string $from, string $to, array $people = []): int
    {
        return (int) $this->service->createEvent(1, $organiser, $title, '', $this->when($from, $to), false, true, $organiser, people: $people)['event']['id'];
    }

    /**
     * A board meeting, Monday 16 November 10:00 in Amman (07:00Z), in meeting
     * room 2, with these invitations.
     *
     * @param array<int, string> $invited profile id => answer
     */
    private function meeting(?int $created, array $invited, ?int $chair = null): int
    {
        $this->pdo->exec("INSERT INTO convening_bodies (id, tenant_id, body_key, name) VALUES (501, 1, 'board', 'Board')");
        if ($chair !== null) {
            $this->pdo->exec("INSERT INTO convening_body_members (tenant_id, body_id, profile_id, member_role) VALUES (1, 501, {$chair}, 'chair')");
        }
        $by = $created ?? 'NULL';
        $this->pdo->exec(
            "INSERT INTO meetings (id, tenant_id, body_id, meeting_number, title, scheduled_at, location, status, created_by_profile_id)
             VALUES (601, 1, 501, 1, '{\"en\":\"Board meeting\",\"ar\":\"اجتماع المجلس\"}', '2026-11-16 07:00:00', 'Meeting room 2', 'scheduled', {$by})"
        );
        foreach ($invited as $profile => $status) {
            $this->pdo->exec("INSERT INTO meeting_invitations (tenant_id, meeting_id, profile_id, status) VALUES (1, 601, {$profile}, '{$status}')");
        }

        return 601;
    }

    private function book(int $profileId, string $startsAt): int
    {
        $result = $this->service->book(1, [
            'service_id' => $this->cake,
            'profile_id' => $profileId,
            'starts_at' => $this->at($startsAt),
            'customer_profile_id' => null,
            'customer_name' => 'Lina',
            'customer_email' => 'lina@example.com',
            'customer_phone' => '',
            'customer_note' => '',
            'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => $profileId,
            'enforce_hours' => false,
            'public' => false,
        ]);

        return (int) $result['id'];
    }

    /**
     * @param list<int>    $people
     * @param list<string> $kinds
     * @return list<array<string, mixed>>
     */
    private function items(int $viewer, array $people = [], array $kinds = [], bool $manages = false): array
    {
        return (new CalendarItems($this->bookings, $this->calendar))->between(
            1,
            $viewer,
            $this->at('2026-11-16T00:00:00+03:00'),
            $this->at('2026-11-17T00:00:00+03:00'),
            $people,
            $kinds,
            false,
            $manages,
        );
    }

    /** @return list<string> free 30-minute starts for the cake tasting, Monday 10:00-11:00 in Amman */
    private function free(int $who): array
    {
        return array_column($this->service->availability(1, $this->cake, $who, $this->at('2026-11-16T10:00:00+03:00'), $this->at('2026-11-16T11:00:00+03:00')), 'starts_at');
    }

    private function when(string $from, string $to): Interval
    {
        return new Interval($this->at($from), $this->at($to));
    }

    private function at(string $iso): DateTimeImmutable
    {
        return Interval::fromApi($iso) ?? throw new \LogicException('bad instant ' . $iso);
    }
}
