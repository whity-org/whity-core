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
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarFeed;
use Whity\Core\Calendar\CalendarItems;
use Whity\Core\Calendar\CalendarPlugins;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\HostBookings;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ServiceChoices;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Calendar\BookingPaymentStep;
use Whity\Sdk\Calendar\BookingQuote;
use Whity\Sdk\Calendar\PaymentHandoff;
use Whity\Sdk\Calendar\PaymentRequirement;
use Whity\Sdk\Calendar\PendingBooking;

/**
 * One booking, several services back to back (WHIT-1173), against the real
 * schema: the setting, who does which, each person busy only for their own
 * part, moving and cancelling the whole booking, the grid, payment, and the
 * staff API.
 *
 * Example Salon (tenant 1) has Ahmad Khalil (11) and Awss Haddad (12),
 * Sunday to Thursday 09:00-17:00 in Amman. Both cut; only Awss colours; both
 * blow-dry. Monday 16 November 10:00 in Amman is 07:00Z.
 */
final class MultiServiceBookingRealEngineTest extends TestCase
{
    /** Sunday 15 November 2026, 07:00 in Amman. */
    private const NOW = '2026-11-15 04:00:00';

    private PDO $pdo;
    private SettingsService $settings;
    private BookingService $service;
    private CalendarRepository $calendar;
    private ServiceRepository $services;
    private BookingRepository $bookings;
    private CalendarPlugins $plugins;
    private int $cut;
    private int $colour;
    private int $blowDry;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Salon', 'example-salon'), (2, 'Other Salon', 'other-salon')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => [1, 'Ahmad Khalil'], 12 => [1, 'Awss Haddad'], 21 => [2, 'Emad Nasser']] as $profile => [$tenant, $name]) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        foreach ([1, 2] as $tenant) {
            $this->settings->setTenant($tenant, 'timezone', 'Asia/Amman');
            $this->settings->setTenant($tenant, 'calendar.min_notice_minutes', '60');
            $this->settings->setTenant($tenant, 'calendar.slot_step_minutes', '15');
        }
        $this->settings->setTenant(1, 'calendar.multi_service_booking', 'true');

        $this->calendar = new CalendarRepository($this->pdo);
        $this->services = new ServiceRepository($this->pdo);
        $this->bookings = new BookingRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $this->calendar, $this->services, $this->bookings, $this->settings, null, clock: static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
        $this->plugins = new CalendarPlugins();
        $this->service->setPlugins($this->plugins);

        $this->cut = $this->offer('Cut', 'قص', 45, 0, 15000, [11, 12]);
        $this->colour = $this->offer('Colour', 'صبغة', 60, 15, 30000, [12]);
        $this->blowDry = $this->offer('Blow-dry', 'تجفيف', 30, 0, 10000, [11, 12]);
        foreach ([11, 12] as $person) {
            $this->calendar->ensureStaff(1, $person);
            $this->calendar->replaceHours(1, $person, array_map(
                static fn (int $day): array => ['weekday' => $day, 'start_time' => '09:00', 'end_time' => '17:00'],
                [7, 1, 2, 3, 4],
            ));
        }
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @param list<int> $staff */
    private function offer(string $en, string $ar, int $minutes, int $bufferAfter, int $priceMinor, array $staff, string $currency = 'JOD'): int
    {
        $id = $this->services->insert(1, [
            'name' => ['en' => $en, 'ar' => $ar], 'description' => [],
            'duration_minutes' => $minutes, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => $bufferAfter,
            'price_minor' => $priceMinor, 'currency' => $currency, 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->services->replaceStaff(1, $id, $staff);

        return $id;
    }

    private static function at(string $iso): DateTimeImmutable
    {
        return Interval::fromApi($iso) ?? throw new \LogicException('bad instant ' . $iso);
    }

    /**
     * @param list<array{service_id: int, profile_id: ?int}> $items
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function book(array $items, string $startsAt, array $overrides = []): array
    {
        /** @var array{service_id: int, profile_id: ?int, starts_at: DateTimeImmutable, customer_profile_id: ?int, customer_name: string, customer_email: string, customer_phone: string, customer_note: string, source: string, created_by_profile_id: ?int, enforce_hours: bool, public: bool, items: list<array{service_id: int, profile_id: ?int}>} $request */
        $request = $overrides + [
            'items' => $items,
            'service_id' => $items[0]['service_id'],
            'profile_id' => null,
            'starts_at' => self::at($startsAt),
            'customer_profile_id' => null,
            'customer_name' => 'Lina Haddad',
            'customer_email' => 'lina@example.com',
            'customer_phone' => '',
            'customer_note' => '',
            'source' => BookingRepository::SOURCE_PUBLIC,
            'created_by_profile_id' => null,
            'enforce_hours' => true,
            'public' => true,
        ];

        return $this->service->book(1, $request);
    }

    /** @return list<array{service_id: int, profile_id: ?int}> */
    private function anyone(int ...$serviceIds): array
    {
        return array_values(array_map(static fn (int $id): array => ['service_id' => $id, 'profile_id' => null], $serviceIds));
    }

    /** @return list<string> */
    private function busy(int $person): array
    {
        $busy = array_map(
            static fn (Interval $i): string => Interval::toApi($i->start) . '/' . Interval::toApi($i->end),
            $this->calendar->busy(1, $person, self::at('2026-11-16T00:00:00+03:00'), self::at('2026-11-17T00:00:00+03:00'), self::at('2026-11-15T07:00:00+03:00')),
        );
        sort($busy);

        return $busy;
    }

    public function testTheSettingIsOffByDefaultAndThenSeveralServicesAreRefused(): void
    {
        $this->settings->setTenant(1, 'calendar.multi_service_booking', 'false');
        self::assertFalse($this->service->settingsFor(1)->multiServiceBooking);
        try {
            $this->book($this->anyone($this->cut, $this->blowDry), '2026-11-16T10:00:00+03:00');
            self::fail('several services must be refused while the setting is off');
        } catch (CalendarRejectedException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(['code' => 'multi_service_off'], $e->extra);
        }
        // One service in the list is the one-service booking, setting or not.
        $one = $this->book($this->anyone($this->cut), '2026-11-16T10:00:00+03:00');
        self::assertSame([], $one['items']);
        self::assertSame($this->cut, $one['service_id']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM calendar_booking_services'));
    }

    public function testOneBookingSpansTheServicesAtTheTotalPriceAndEachPersonIsBusyForTheirOwnPart(): void
    {
        $booking = $this->book($this->anyone($this->cut, $this->colour, $this->blowDry), '2026-11-16T10:00:00+03:00');

        self::assertSame('confirmed', $booking['status']);
        self::assertSame('2026-11-16T07:00:00Z', $booking['starts_at']);
        // 45 + 60 + 30 minutes.
        self::assertSame('2026-11-16T09:15:00Z', $booking['ends_at']);
        self::assertSame(55000, $booking['price_minor'], 'JOD 15.000 + 30.000 + 10.000');
        self::assertSame($this->cut, $booking['service_id'], 'the booking row is the first service');
        self::assertSame(11, $booking['profile_id']);

        self::assertSame([[$this->cut, 11, '2026-11-16T07:00:00Z'], [$this->colour, 12, '2026-11-16T07:45:00Z'], [$this->blowDry, 12, '2026-11-16T08:45:00Z']], array_map(
            static fn (array $i): array => [$i['service_id'], $i['profile_id'], $i['starts_at']],
            $booking['items'],
        ), 'only Awss colours, and he keeps the blow-dry that follows');
        self::assertSame([45, 60, 30], array_column($booking['items'], 'duration_minutes'));

        // Everything that shows one service shows them all.
        self::assertSame(['ar' => 'قص + صبغة + تجفيف', 'en' => 'Cut + Colour + Blow-dry'], $booking['service']['name']);
        self::assertSame(135, $booking['service']['duration_minutes']);
        self::assertSame('Ahmad Khalil, Awss Haddad', $booking['staff_name']);

        // Ahmad is busy for the cut only; Awss for the colour (with its
        // 15-minute buffer after) and the blow-dry.
        self::assertSame(['2026-11-16T07:00:00Z/2026-11-16T07:45:00Z'], $this->busy(11));
        self::assertSame(['2026-11-16T07:45:00Z/2026-11-16T09:00:00Z', '2026-11-16T08:45:00Z/2026-11-16T09:15:00Z'], $this->busy(12));

        // So Ahmad is free from 10:45, and Awss until 10:45.
        $awss = $this->book([['service_id' => $this->cut, 'profile_id' => 11]], '2026-11-16T10:45:00+03:00', ['customer_email' => 'rana@example.com']);
        self::assertSame(11, $awss['profile_id']);
        $ahmad = $this->book([['service_id' => $this->cut, 'profile_id' => 12]], '2026-11-16T10:00:00+03:00', ['customer_email' => 'sara@example.com']);
        self::assertSame(12, $ahmad['profile_id']);
    }

    public function testSamePersonTakesOneWhoDoesThemAll(): void
    {
        $two = $this->book($this->anyone($this->cut, $this->blowDry), '2026-11-16T10:00:00+03:00', ['same_person' => true]);
        self::assertSame([11, 11], array_column($two['items'], 'profile_id'));

        $three = $this->book($this->anyone($this->cut, $this->colour, $this->blowDry), '2026-11-16T12:00:00+03:00', ['same_person' => true, 'customer_email' => 'rana@example.com']);
        self::assertSame([12, 12, 12], array_column($three['items'], 'profile_id'), 'only Awss does all three');

        $this->services->replaceStaff(1, $this->blowDry, [11]);
        $this->expectExceptionObject(new CalendarRejectedException('Nobody does all of these services. Choose someone for each, or anyone.', 422));
        $this->book($this->anyone($this->colour, $this->blowDry), '2026-11-16T14:00:00+03:00', ['same_person' => true, 'customer_email' => 'sara@example.com']);
    }

    public function testFreeTimesNeedEveryPartFreeAndInsideTheHours(): void
    {
        // Awss cuts from 10:45 to 11:30.
        $this->book([['service_id' => $this->cut, 'profile_id' => 12]], '2026-11-16T10:45:00+03:00');

        $slots = $this->service->availabilityForMany(1, $this->anyone($this->cut, $this->colour), false, self::at('2026-11-16T10:00:00+03:00'), self::at('2026-11-16T11:00:00+03:00'));
        // 10:00 to 10:30 would put the colour inside Awss's cut; 10:45 puts it at 11:30.
        self::assertSame(['2026-11-16T07:45:00Z'], array_column($slots, 'starts_at'));
        self::assertSame('2026-11-16T09:30:00Z', $slots[0]['ends_at']);
        self::assertSame([11, 12], $slots[0]['profile_ids']);
        self::assertSame([
            ['service_id' => $this->cut, 'profile_id' => 11, 'starts_at' => '2026-11-16T07:45:00Z', 'ends_at' => '2026-11-16T08:30:00Z'],
            ['service_id' => $this->colour, 'profile_id' => 12, 'starts_at' => '2026-11-16T08:30:00Z', 'ends_at' => '2026-11-16T09:30:00Z'],
        ], $slots[0]['items']);

        // Cut and colour take 105 minutes: the last start before 17:00 is 15:15.
        $late = $this->service->availabilityForMany(1, $this->anyone($this->cut, $this->colour), false, self::at('2026-11-16T15:00:00+03:00'), self::at('2026-11-16T17:00:00+03:00'));
        self::assertSame(['2026-11-16T12:00:00Z', '2026-11-16T12:15:00Z'], array_column($late, 'starts_at'));

        // A person named for a service is the only one tried for it.
        $awssOnly = $this->service->availabilityForMany(1, [['service_id' => $this->cut, 'profile_id' => 11], ['service_id' => $this->blowDry, 'profile_id' => 11]], false, self::at('2026-11-16T10:00:00+03:00'), self::at('2026-11-16T10:30:00+03:00'));
        self::assertSame([[11], [11]], array_column($awssOnly, 'profile_ids'));
    }

    public function testMovingShiftsEveryPartTogetherAndCancellingFreesThemAll(): void
    {
        $desk = ['public' => false, 'enforce_hours' => false, 'source' => 'staff'];
        $booking = $this->book([['service_id' => $this->cut, 'profile_id' => 11], ['service_id' => $this->colour, 'profile_id' => 12]], '2026-11-16T10:00:00+03:00', $desk);
        $id = (int) $booking['id'];

        $moved = $this->service->move(1, $id, self::at('2026-11-16T13:00:00+03:00'), null, false);
        self::assertSame('2026-11-16T10:00:00Z', $moved['starts_at']);
        self::assertSame('2026-11-16T11:45:00Z', $moved['ends_at']);
        self::assertSame([[11, '2026-11-16T10:00:00Z'], [12, '2026-11-16T10:45:00Z']], array_map(static fn (array $i): array => [$i['profile_id'], $i['starts_at']], $moved['items']));
        self::assertSame(['2026-11-16T10:00:00Z/2026-11-16T10:45:00Z'], $this->busy(11), 'the old time is free');

        // One person for them all, only someone who does every one.
        try {
            $this->service->move(1, $id, self::at('2026-11-16T13:00:00+03:00'), 11, false);
            self::fail('Ahmad does not colour');
        } catch (CalendarRejectedException $e) {
            self::assertSame(422, $e->status);
        }
        $ahmad = $this->service->move(1, $id, self::at('2026-11-16T13:00:00+03:00'), 12, false);
        self::assertSame([12, 12], array_column($ahmad['items'], 'profile_id'));
        self::assertSame(12, $ahmad['profile_id']);
        self::assertSame([], $this->busy(11));

        // Moving onto someone's busy time says why.
        $this->book([['service_id' => $this->cut, 'profile_id' => 12]], '2026-11-16T10:00:00+03:00', $desk);
        try {
            $this->service->move(1, $id, self::at('2026-11-16T09:30:00+03:00'), null, false);
            self::fail('Awss is busy then');
        } catch (CalendarRejectedException $e) {
            self::assertSame(['reason' => CalendarRejectedException::PERSON_BUSY], $e->extra);
        }

        $this->service->cancel(1, $id, 'staff', '');
        self::assertSame(['2026-11-16T07:00:00Z/2026-11-16T07:45:00Z'], $this->busy(12), 'only the one-service booking is left');
    }

    public function testStaffAreToldWhyAndMayBookAnyway(): void
    {
        $desk = ['public' => false, 'enforce_hours' => false, 'source' => 'staff'];
        $this->book([['service_id' => $this->colour, 'profile_id' => 12]], '2026-11-16T10:45:00+03:00', $desk);
        $items = [['service_id' => $this->cut, 'profile_id' => 11], ['service_id' => $this->colour, 'profile_id' => 12]];
        try {
            $this->book($items, '2026-11-16T10:00:00+03:00', $desk);
            self::fail('Awss is colouring then');
        } catch (CalendarRejectedException $e) {
            self::assertSame(['reason' => CalendarRejectedException::PERSON_BUSY], $e->extra);
        }
        $anyway = $this->book($items, '2026-11-16T10:00:00+03:00', $desk + ['book_anyway' => true]);
        self::assertSame('confirmed', $anyway['status']);

        // A customer only hears that the time was taken.
        try {
            $this->book($this->anyone($this->cut, $this->colour), '2026-11-16T10:00:00+03:00', ['customer_email' => 'sara@example.com']);
            self::fail('the colour is taken');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testTheGridShowsEachPersonTheirOwnPart(): void
    {
        $booking = $this->book($this->anyone($this->cut, $this->colour, $this->blowDry), '2026-11-16T10:00:00+03:00');
        $id = (int) $booking['id'];
        $items = new CalendarItems($this->bookings, $this->calendar);
        $day = [self::at('2026-11-16T00:00:00+03:00'), self::at('2026-11-17T00:00:00+03:00')];

        $all = $items->between(1, 11, $day[0], $day[1], [], ['booking'], false, true);
        self::assertSame([
            ['booking:' . $id, 11, 'Cut', '2026-11-16T07:00:00Z', '2026-11-16T07:45:00Z', false],
            ['booking:' . $id . ':1', 12, 'Colour + Blow-dry', '2026-11-16T07:45:00Z', '2026-11-16T09:15:00Z', false],
        ], array_map(static fn (array $i): array => [$i['id'], $i['organiser_id'], $i['title'], $i['starts_at'], $i['ends_at'], $i['can']['move']], $all));
        self::assertSame($id, $all[1]['booking']['id']);
        self::assertSame(11, $all[1]['booking']['profile_id'], 'the booking itself is unchanged');

        $ahmads = $items->between(1, 12, $day[0], $day[1], [12], ['booking'], false, false);
        self::assertSame(['booking:' . $id . ':1'], array_column($ahmads, 'id'), 'Awss sees his part only');
        self::assertTrue($ahmads[0]['can']['cancel'], 'his own part of the booking');

        // The list for one person finds a booking where they do any part.
        self::assertSame([$id], array_map(static fn (array $r): int => (int) $r['id'], $this->bookings->list(1, [12], $day[0], $day[1])));

        // His calendar feed has his own part.
        $feed = new CalendarFeed($this->pdo, $this->calendar, $this->bookings);
        $ics = $feed->render(1, 12, $this->service->settingsFor(1), self::at('2026-11-15T07:00:00+03:00'), 'Awss');
        self::assertStringContainsString("DTSTART:20261116T074500Z\r\n", $ics);
        self::assertStringContainsString("DTEND:20261116T091500Z\r\n", $ics);
    }

    public function testDifferentCurrenciesAndOtherWorkspacesAreRefused(): void
    {
        $usd = $this->offer('Nails', 'أظافر', 30, 0, 2000, [11], 'USD');
        try {
            $this->book($this->anyone($this->cut, $usd), '2026-11-16T10:00:00+03:00');
            self::fail('one total needs one currency');
        } catch (CalendarRejectedException $e) {
            self::assertSame(422, $e->status);
        }

        $this->settings->setTenant(2, 'calendar.multi_service_booking', 'true');
        $this->expectExceptionObject(CalendarRejectedException::notFound('Service'));
        $this->service->book(2, [
            'items' => $this->anyone($this->cut, $this->blowDry),
            'service_id' => $this->cut,
            'profile_id' => null,
            'starts_at' => self::at('2026-11-16T10:00:00+03:00'),
            'customer_profile_id' => null,
            'customer_name' => 'Lina',
            'customer_email' => 'lina@example.com',
            'customer_phone' => '',
            'customer_note' => '',
            'source' => 'public',
            'created_by_profile_id' => null,
            'enforce_hours' => true,
            'public' => true,
        ]);
    }

    public function testAPaymentStepHoldsEveryPartAndConfirmsThemTogether(): void
    {
        $this->plugins->offerPaymentStep('WhityPay', new class implements BookingPaymentStep {
            public ?BookingQuote $seen = null;

            public function requirement(BookingQuote $quote): ?PaymentRequirement
            {
                $this->seen = $quote;

                return $quote->priceMinor !== null ? new PaymentRequirement(intdiv($quote->priceMinor, 5), $quote->currency, 'Deposit') : null;
            }

            public function start(PendingBooking $booking, string $returnUrl): PaymentHandoff
            {
                return new PaymentHandoff('https://pay.example.com/checkout/' . $booking->bookingId);
            }
        });
        $held = $this->book($this->anyone($this->cut, $this->colour), '2026-11-16T10:00:00+03:00');
        self::assertSame('pending_payment', $held['status']);
        self::assertSame(9000, $held['payment']['amount_minor'], 'a fifth of JOD 45.000');
        self::assertSame(['2026-11-16T07:45:00Z/2026-11-16T09:00:00Z'], $this->busy(12), 'the hold keeps the colour');

        self::assertTrue((new HostBookings($this->service))->confirmPaid(1, (int) $held['id'], 'pay_1'));
        self::assertSame('confirmed', $this->service->bookingOrFail(1, (int) $held['id'])['status']);
    }

    /**
     * #2256: on the HTTP routes too, a member of staff puts time off on their
     * own calendar with no calendar permission; their own extra hours, or
     * anybody else's time off, still need the calendar permissions.
     */
    public function testAMemberOfStaffAddsAndRemovesTheirOwnTimeOffWithNoGrant(): void
    {
        TenantContext::setTenantId(1);
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(false);
        $roles->method('isActiveStaff')->willReturnCallback(static fn (int $p, int $t): bool => $t === 1 && in_array($p, [11, 12], true));
        $handler = new CalendarApiHandler($this->calendar, $this->services, $this->bookings, $this->service, $this->settings, $roles);
        $as = static function (int $profileId, string $method, string $path, array $body = []): Request {
            $r = new Request($method, $path, [], $body === [] ? '' : (string) json_encode($body));
            $r->user = (object) ['profile_id' => $profileId];

            return $r;
        };
        $when = ['starts_at' => '2026-11-22T00:00:00+03:00', 'ends_at' => '2026-11-23T00:00:00+03:00'];

        $own = $handler->createException($as(11, 'POST', '/api/calendar/time-off', $when + ['kind' => 'off']));
        self::assertSame(201, $own->getStatusCode(), $own->getBody());
        $id = (int) self::json($own)['data']['id'];

        self::assertSame(403, $handler->createException($as(11, 'POST', '/x', $when + ['kind' => 'open']))->getStatusCode(), 'own extra hours need calendar:write');
        self::assertSame(403, $handler->createException($as(11, 'POST', '/x', $when + ['kind' => 'off', 'profile_id' => 12]))->getStatusCode(), 'somebody else needs the calendar permissions');
        self::assertSame(403, $handler->deleteException($as(12, 'DELETE', '/x'), ['id' => (string) $id])->getStatusCode());
        self::assertSame(200, $handler->deleteException($as(11, 'DELETE', '/x'), ['id' => (string) $id])->getStatusCode());
    }

    public function testTheStaffApiTakesTheSettingAndServices(): void
    {
        TenantContext::setTenantId(1);
        $handler = new CalendarApiHandler($this->calendar, $this->services, $this->bookings, $this->service, $this->settings, $this->createStub(RoleChecker::class));

        $this->settings->setTenant(1, 'calendar.multi_service_booking', 'false');
        $put = $handler->putSettings(new Request('PUT', '/api/v1/calendar/settings', [], (string) json_encode(['multi_service_booking' => true])));
        self::assertSame(200, $put->getStatusCode(), $put->getBody());
        self::assertTrue(self::json($put)['data']['multi_service_booking']);
        self::assertSame('true', $this->settings->effective(1)['calendar.multi_service_booking']);
        self::assertSame(422, $handler->putSettings(new Request('PUT', '/x', [], (string) json_encode(['multi_service_booking' => 'perhaps'])))->getStatusCode());

        $request = new Request('POST', '/api/v1/calendar/bookings', [], (string) json_encode([
            'services' => [['service_id' => $this->cut, 'profile_id' => 11], ['service_id' => $this->colour]],
            'starts_at' => '2026-11-16T10:00:00+03:00',
            'customer' => ['name' => 'Lina Haddad', 'phone' => '+962 79 555 0142'],
        ]));
        $request->user = (object) ['profile_id' => 11];
        $created = $handler->createBooking($request);
        self::assertSame(201, $created->getStatusCode(), $created->getBody());
        $booking = self::json($created)['data'];
        self::assertSame([11, 12], array_column($booking['items'], 'profile_id'));
        self::assertSame(45000, $booking['price_minor']);

        $_GET = ['service_ids' => $this->cut . ',' . $this->blowDry, 'profile_ids' => '11,0', 'from' => '2026-11-16T12:00:00+03:00', 'to' => '2026-11-16T13:00:00+03:00'];
        try {
            $slots = self::json($handler->availability(new Request('GET', '/api/v1/calendar/availability', [], '')))['data'];
        } finally {
            $_GET = [];
        }
        self::assertSame('2026-11-16T09:00:00Z', $slots[0]['starts_at']);
        self::assertSame('2026-11-16T10:15:00Z', $slots[0]['ends_at']);
        self::assertSame([11, 11], array_column($slots[0]['items'], 'profile_id'));
    }

    public function testServiceChoicesParseBodiesAndQueries(): void
    {
        self::assertNull(ServiceChoices::fromBody(['service_id' => 4]));
        self::assertSame(
            [['service_id' => 4, 'profile_id' => 11], ['service_id' => 7, 'profile_id' => 12]],
            ServiceChoices::fromBody(['profile_id' => 11, 'services' => [['service_id' => 4], ['service_id' => 7, 'profile_id' => 12]]]),
        );
        self::assertSame(
            [['service_id' => 4, 'profile_id' => 11], ['service_id' => 7, 'profile_id' => null]],
            ServiceChoices::fromQuery(['service_ids' => '4,7', 'profile_ids' => '11,0']),
        );
        self::assertNull(ServiceChoices::fromQuery([]));
        foreach ([['services' => []], ['services' => [['service_id' => 'x']]], ['services' => array_fill(0, 9, ['service_id' => 1])]] as $bad) {
            try {
                ServiceChoices::fromBody($bad);
                self::fail('refused: ' . json_encode($bad));
            } catch (CalendarRejectedException $e) {
                self::assertSame(422, $e->status);
            }
        }
        $this->expectException(CalendarRejectedException::class);
        ServiceChoices::fromQuery(['service_ids' => '4,7', 'profile_ids' => '11']);
    }

    /** @return array<string, mixed> */
    private static function json(\Whity\Core\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());

        return $decoded;
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }
}
