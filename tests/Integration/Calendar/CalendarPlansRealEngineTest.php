<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\CreateCalendarPlans;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Calendar\TreatmentPlans;
use Whity\Core\Contacts\ContactRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Treatment plans (WHIT-1444, design section 39 §7.4, AP.22): a customer, the
 * person who treats them, and steps with a gap in days. The customer is read
 * from their contact and never copied onto the plan; deleting the contact
 * deletes the plan. Book the next visit suggests the first free time in the
 * gap with the same person; Book all leaves a step it can't place and says
 * so; progress is read from the bookings. Real engine.
 */
final class CalendarPlansRealEngineTest extends TestCase
{
    /** Monday 16 November 2026, 08:00 in Amman. */
    private const NOW = '2026-11-16 05:00:00';

    private PDO $pdo;
    private BookingService $service;
    private TreatmentPlans $plans;
    private ContactRepository $contacts;
    private int $laser;
    private int $check;
    private int $lina;

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
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setTenant(1, 'timezone', 'Asia/Amman');
        $settings->setTenant(1, 'calendar.min_notice_minutes', '0');
        $calendar = new CalendarRepository($this->pdo);
        $services = new ServiceRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $calendar, $services, new BookingRepository($this->pdo), $settings, null,
            clock: fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
        $this->plans = $this->service->plans();
        $this->contacts = new ContactRepository($this->pdo);

        $this->laser = $services->insert(1, [
            'name' => ['en' => 'Laser session', 'ar' => 'جلسة ليزر'], 'description' => [], 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => null, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->check = $services->insert(1, [
            'name' => ['en' => 'Check-up'], 'description' => [], 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => null, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 1,
        ]);
        $services->replaceStaff(1, $this->laser, [11]);
        $services->replaceStaff(1, $this->check, [11, 12]);
        foreach ([11, 12] as $person) {
            $calendar->ensureStaff(1, $person);
            $calendar->replaceHours(1, $person, array_map(
                static fn (int $day): array => ['weekday' => $day, 'start_time' => '09:00', 'end_time' => '17:00'],
                [1, 2, 3, 4, 5, 6, 7],
            ));
        }
        $this->lina = $this->contacts->insert(1, ['name' => 'Lina Haddad', 'email' => 'lina@example.com', 'is_customer' => true], null);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAPlanReadsTheCustomerFromTheirContactAndKeepsNoCopy(): void
    {
        $plan = $this->plans->create(1, $this->lina, 11, 'Laser, 3 sessions', $this->threeLaser(), 11);

        self::assertSame(['contact_id' => $this->lina, 'name' => 'Lina Haddad', 'email' => 'lina@example.com', 'phone' => ''], $plan['customer']);
        self::assertSame(['profile_id' => 11, 'name' => 'Amro Saleh'], $plan['staff']);
        self::assertSame(['done' => 0, 'missed' => 0, 'total' => 3], $plan['progress']);
        self::assertSame(['to_book', 'to_book', 'to_book'], array_column($plan['steps'], 'state'));
        self::assertSame('Laser session', $plan['steps'][0]['service_name']['en']);

        // The contact changes: the plan shows the change, as nothing was copied.
        $this->pdo->exec("UPDATE contacts SET name = 'Lina Haddad-Saleh', email = 'lina.hs@example.com' WHERE id = {$this->lina}");
        $again = $this->plans->findOrFail(1, $plan['id']);
        self::assertSame('Lina Haddad-Saleh', $again['customer']['name']);
        self::assertSame('lina.hs@example.com', $again['customer']['email']);

        $row = $this->row('SELECT * FROM calendar_plans WHERE id = ' . $plan['id']);
        foreach (array_keys($row) as $column) {
            self::assertDoesNotMatchRegularExpression('/name$|email|phone|mobile/', (string) $column === 'title' ? '' : (string) $column, 'calendar_plans keeps no customer details');
        }
        self::assertSame([$plan['id']], array_column($this->plans->forContact(1, $this->lina), 'id'));
    }

    public function testBookTheNextVisitSuggestsTheFirstFreeTimeInTheGapWithTheSamePerson(): void
    {
        $plan = $this->plans->create(1, $this->lina, 11, 'Laser, 3 sessions', $this->threeLaser(), 11);

        // The first step: today, from 09:00 in Amman.
        $next = $this->plans->nextSlot(1, $plan['id']);
        self::assertSame($plan['steps'][0]['id'], $next['step_id']);
        self::assertEquals(self::utc('2026-11-16 06:00'), Interval::fromApi($next['starts_at']));
        $plan = $this->plans->bookNext(1, $plan['id'], null, 11);
        self::assertSame('booked', $plan['steps'][0]['state']);

        // The booking is an ordinary one, for the plan's customer, with the same person.
        $booking = $this->booking((int) $plan['steps'][0]['booking']['id']);
        self::assertSame('Lina Haddad', $booking['customer_name']);
        self::assertSame('lina@example.com', $booking['customer_email']);
        self::assertSame($this->lina, (int) $booking['contact_id']);
        self::assertSame(11, (int) $booking['profile_id']);
        self::assertSame('confirmed', $booking['status']);

        // The second: 14 to 21 days after the first, the 30th's 09:00 taken.
        $this->other('2026-11-30 06:00');
        $next = $this->plans->nextSlot(1, $plan['id']);
        self::assertSame($plan['steps'][1]['id'], $next['step_id']);
        self::assertEquals(self::utc('2026-11-30 07:00'), Interval::fromApi($next['starts_at']));
        self::assertNotNull($next['window']);
        self::assertEquals(self::utc('2026-11-29 21:00'), Interval::fromApi($next['window']['from']));
        self::assertEquals(self::utc('2026-12-07 21:00'), Interval::fromApi($next['window']['to']));

        // Staff choose another time in the gap.
        $plan = $this->plans->bookNext(1, $plan['id'], self::utc('2026-12-02 10:00'), 11);
        self::assertEquals(self::utc('2026-12-02 10:00'), Interval::fromApi($plan['steps'][1]['booking']['starts_at']));

        // A time that's taken is refused like any booking.
        $this->other('2026-12-17 08:00');
        try {
            $this->plans->bookNext(1, $plan['id'], self::utc('2026-12-17 08:00'), 11);
            self::fail('A taken time is refused');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame('to_book', $this->plans->findOrFail(1, $plan['id'])['steps'][2]['state']);
    }

    public function testBookAllLeavesAStepItCannotPlaceAndSaysSo(): void
    {
        $plan = $this->plans->create(1, $this->lina, 11, 'Check-ups', [
            ['service_id' => $this->laser, 'gap_min_days' => 0, 'gap_max_days' => 0],
            ['service_id' => $this->laser, 'gap_min_days' => 1, 'gap_max_days' => 1],
            ['service_id' => $this->laser, 'gap_min_days' => 1, 'gap_max_days' => 1],
        ], 11);
        // Tuesday the 17th is full for Amro.
        for ($hour = 6; $hour < 14; $hour++) {
            $this->other(sprintf('2026-11-17 %02d:00', $hour));
        }

        $result = $this->plans->bookAll(1, $plan['id'], 11);

        self::assertSame([true, false, true], array_column($result['steps'], 'booked'));
        self::assertEquals(self::utc('2026-11-16 06:00'), Interval::fromApi($result['steps'][0]['starts_at']));
        self::assertNull($result['steps'][1]['starts_at']);
        // The third counts its gap from where the second would have been.
        self::assertEquals(self::utc('2026-11-18 06:00'), Interval::fromApi($result['steps'][2]['starts_at']));
        self::assertSame(['booked', 'to_book', 'booked'], array_column($result['plan']['steps'], 'state'));
        self::assertSame($plan['steps'][1]['id'], $this->plans->nextSlot(1, $plan['id'])['step_id']);

        // Nothing left to book once each is booked, and Book next says so.
        $this->pdo->exec("DELETE FROM calendar_bookings WHERE tenant_id = 1 AND customer_name = 'Other customer' AND starts_at >= '2026-11-17' AND starts_at < '2026-11-18'");
        $this->plans->bookAll(1, $plan['id'], 11);
        self::assertSame(['step_id' => null, 'starts_at' => null, 'window' => null], $this->plans->nextSlot(1, $plan['id']));
        $this->expectException(CalendarRejectedException::class);
        $this->plans->bookNext(1, $plan['id'], null, 11);
    }

    public function testProgressIsReadFromTheBookings(): void
    {
        $plan = $this->plans->create(1, $this->lina, 11, 'Laser, 3 sessions', [
            ['service_id' => $this->laser, 'gap_min_days' => 0, 'gap_max_days' => 0],
            ['service_id' => $this->laser, 'gap_min_days' => 1, 'gap_max_days' => 1],
            ['service_id' => $this->laser, 'gap_min_days' => 1, 'gap_max_days' => 1],
        ], 11);
        $steps = $this->plans->bookAll(1, $plan['id'], 11)['plan']['steps'];
        [$first, $second, $third] = array_map(static fn (array $s): int => (int) $s['booking']['id'], $steps);

        $this->pdo->exec("UPDATE calendar_bookings SET attendance = 'attended' WHERE id = {$first}");
        $this->pdo->exec("UPDATE calendar_bookings SET attendance = 'no_show' WHERE id = {$second}");
        $this->pdo->exec("UPDATE calendar_bookings SET status = 'cancelled' WHERE id = {$third}");

        $plan = $this->plans->findOrFail(1, $plan['id']);
        self::assertSame(['done', 'missed', 'to_book'], array_column($plan['steps'], 'state'));
        self::assertSame(['done' => 1, 'missed' => 1, 'total' => 3], $plan['progress']);
        self::assertSame(
            ['plan_id' => $plan['id'], 'title' => 'Laser, 3 sessions', 'step' => 2, 'total' => 3, 'done' => 1, 'status' => 'active', 'next_to_book' => true],
            $this->plans->forBooking(1, $second),
        );
        self::assertNull($this->plans->forBooking(1, $this->other('2026-11-20 06:00')));

        // Finished at the desk counts as done too.
        $this->pdo->exec("UPDATE calendar_bookings SET attendance = NULL, finished_at = '2026-11-16 07:00:00' WHERE id = {$first}");
        self::assertSame('done', $this->plans->findOrFail(1, $plan['id'])['steps'][0]['state']);

        $stopped = $this->plans->setStatus(1, $plan['id'], TreatmentPlans::STOPPED);
        self::assertSame('stopped', $stopped['status']);
        self::assertFalse($this->plans->forBooking(1, $second)['next_to_book'] ?? true);
        $this->expectException(CalendarRejectedException::class);
        $this->plans->bookAll(1, $plan['id'], 11);
    }

    public function testDeletingTheContactDeletesThePlanAndItsSteps(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $plan = $this->plans->create(1, $this->lina, 11, 'Laser, 3 sessions', $this->threeLaser(), 11);
        $plan = $this->plans->bookNext(1, $plan['id'], null, 11);
        $bookingId = (int) $plan['steps'][0]['booking']['id'];

        self::assertTrue($this->contacts->delete(1, $this->lina));

        self::assertNull($this->plans->find(1, $plan['id']));
        self::assertSame([], $this->plans->forContact(1, $this->lina));
        self::assertSame(0, (int) $this->row('SELECT COUNT(*) AS n FROM calendar_plan_steps WHERE plan_id = ' . $plan['id'])['n']);
        // The booking itself follows the contact's own rule (it is let go of, not deleted).
        self::assertNull($this->booking($bookingId)['contact_id']);
    }

    public function testTemplatesMakePlansAndStepsAreChecked(): void
    {
        $templates = $this->plans->replaceTemplates(1, $this->laser, [
            ['title' => 'Laser, 6 sessions', 'steps' => array_fill(0, 6, ['service_id' => $this->laser, 'gap_min_days' => 28, 'gap_max_days' => 35])],
            ['title' => 'Laser and check-up', 'steps' => [['service_id' => $this->laser], ['service_id' => $this->check, 'gap_min_days' => 7, 'gap_max_days' => 10]]],
        ]);
        self::assertSame(['Laser, 6 sessions', 'Laser and check-up'], array_column($templates, 'title'));
        self::assertSame(['service_id' => $this->laser, 'gap_min_days' => 0, 'gap_max_days' => 0], $templates[1]['steps'][0]);

        $plan = $this->plans->createFromTemplate(1, $templates[0]['id'], $this->lina, 11, 11);
        self::assertSame('Laser, 6 sessions', $plan['title']);
        self::assertSame(6, $plan['progress']['total']);
        self::assertSame([28, 35], [$plan['steps'][5]['gap_min_days'], $plan['steps'][5]['gap_max_days']]);

        // Replacing keeps plans already made.
        self::assertSame([], $this->plans->replaceTemplates(1, $this->laser, []));
        self::assertSame(6, $this->plans->findOrFail(1, $plan['id'])['progress']['total']);

        $refused = [
            'Awss does not offer laser' => fn () => $this->plans->create(1, $this->lina, 12, 'Laser', [['service_id' => $this->laser]], 11),
            'no steps' => fn () => $this->plans->create(1, $this->lina, 11, 'Laser', [], 11),
            'no title' => fn () => $this->plans->create(1, $this->lina, 11, '  ', [['service_id' => $this->laser]], 11),
            'gap the wrong way' => fn () => $this->plans->create(1, $this->lina, 11, 'Laser', [['service_id' => $this->laser, 'gap_min_days' => 9, 'gap_max_days' => 2]], 11),
            'gap too long' => fn () => $this->plans->create(1, $this->lina, 11, 'Laser', [['service_id' => $this->laser, 'gap_min_days' => 400, 'gap_max_days' => 400]], 11),
            'eleven templates' => fn () => $this->plans->replaceTemplates(1, $this->laser, array_fill(0, 11, ['title' => 'x', 'steps' => [['service_id' => $this->laser]]])),
            'a bad status' => fn () => $this->plans->setStatus(1, $plan['id'], 'paused'),
        ];
        foreach ($refused as $why => $call) {
            try {
                $call();
                self::fail('Refused: ' . $why);
            } catch (CalendarRejectedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAnotherWorkspaceCannotSeeOrUseThem(): void
    {
        $plan = $this->plans->create(1, $this->lina, 11, 'Laser', [['service_id' => $this->laser]], 11);
        $theirs = $this->contacts->insert(2, ['name' => 'Someone Else', 'email' => 'else@example.com', 'is_customer' => true], null);

        self::assertNull($this->plans->find(2, $plan['id']));
        self::assertSame([], $this->plans->forContact(2, $this->lina));
        self::assertSame([], $this->plans->templates(2, $this->laser));
        foreach ([
            fn () => $this->plans->create(1, $theirs, 11, 'Laser', [['service_id' => $this->laser]], 11),
            fn () => $this->plans->create(2, $theirs, 11, 'Laser', [['service_id' => $this->laser]], 11),
            fn () => $this->plans->bookNext(2, $plan['id'], null, 11),
        ] as $call) {
            try {
                $call();
                self::fail('Refused across workspaces');
            } catch (CalendarRejectedException $e) {
                self::assertSame(404, $e->status);
            }
        }
    }

    public function testTheMigrationRunsTwiceAndRollsBack(): void
    {
        require_once dirname(__DIR__, 3) . '/database/migrations/372_create_calendar_plans.php';
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $plan = $this->plans->create(1, $this->lina, 11, 'Laser', [['service_id' => $this->laser]], 11);
        CreateCalendarPlans::up($db);
        CreateCalendarPlans::up($db);
        self::assertNotNull($this->plans->find(1, $plan['id']));
        CreateCalendarPlans::down($db);
        CreateCalendarPlans::down($db);
        CreateCalendarPlans::up($db);
        self::assertNull($this->plans->find(1, $plan['id']));
    }

    // ---------------------------------------------------------------------

    /** @return list<array{service_id: int, gap_min_days: int, gap_max_days: int}> */
    private function threeLaser(): array
    {
        return [
            ['service_id' => $this->laser, 'gap_min_days' => 0, 'gap_max_days' => 7],
            ['service_id' => $this->laser, 'gap_min_days' => 14, 'gap_max_days' => 21],
            ['service_id' => $this->laser, 'gap_min_days' => 14, 'gap_max_days' => 21],
        ];
    }

    /** Another customer's hour with Amro. */
    private function other(string $startsUtc): int
    {
        return (int) $this->service->book(1, [
            'service_id' => $this->laser, 'profile_id' => 11, 'starts_at' => self::utc($startsUtc),
            'customer_profile_id' => null, 'customer_name' => 'Other customer', 'customer_email' => '',
            'customer_phone' => '', 'customer_note' => '', 'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => 11, 'enforce_hours' => false, 'public' => false, 'notify' => false,
        ])['id'];
    }

    /** @return array<string, mixed> */
    private function booking(int $id): array
    {
        return $this->row('SELECT * FROM calendar_bookings WHERE id = ' . $id);
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private static function utc(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }
}
