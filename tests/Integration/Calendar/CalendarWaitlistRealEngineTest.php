<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\CreateCalendarWaitlist;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\CalendarApiHandler;
use Whity\Api\PublicBookingApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\BookingVisitor;
use Whity\Core\Calendar\CalendarFeed;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\EmailBookingMessenger;
use Whity\Core\Calendar\ManageLinks;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Calendar\Waitlist;
use Whity\Core\Http\HttpFetcher;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * The waitlist (WHIT-1442, design section 39 §8, AP.23): joining it, a freed
 * time offered to everyone who matches at once by email, and the first to
 * take it gets it. Who gets it is decided by the booking the database accepts
 * (M1, `calendar_holds`); the others are told "Someone took this time first"
 * and stay on the list. Real engine (SQLite, or Postgres when PHPUNIT_PG_DSN
 * is set; the race between processes needs Postgres).
 */
final class CalendarWaitlistRealEngineTest extends TestCase
{
    /** Sunday 15 November 2026, 07:00 in Amman. */
    private const NOW = '2026-11-15 04:00:00';

    private PDO $pdo;
    private BookingService $service;
    private SettingsService $settings;
    private PublicBookingApiHandler $handler;
    /** @var list<array{to: string, subject: string, text: string}> */
    private array $sent = [];
    private int $cake;
    private int $tart;
    private string $now = self::NOW;

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
        $globals = new GlobalSettingsRepository($this->pdo);
        $globals->set(BotCheck::SETTING_ALLOW_NONE, '1');
        $this->settings = new SettingsService($globals, new TenantSettingsRepository($this->pdo));
        $this->settings->setTenant(1, 'timezone', 'Asia/Amman');
        $this->settings->setTenant(1, 'calendar.min_notice_minutes', '60');
        $this->settings->setTenant(1, 'calendar.booking_page_enabled', 'true');

        $calendar = new CalendarRepository($this->pdo);
        $services = new ServiceRepository($this->pdo);
        $bookings = new BookingRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $calendar, $services, $bookings, $this->settings, null,
            clock: fn (): DateTimeImmutable => new DateTimeImmutable($this->now, new DateTimeZone('UTC')));
        $this->service->setLinks(new ManageLinks($this->pdo));
        $sent = &$this->sent;
        $mailer = new class (static function (string $to, string $subject, string $text) use (&$sent): void {
            $sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
        }) implements Mailer {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                ($this->record)($toEmail, $subject, $textBody);
            }
        };
        $tenants = new TenantHostRepository($this->pdo);
        $this->service->setMessenger(new EmailBookingMessenger(new \Whity\Core\Mail\BrandedEmailSender($mailer, new EmailLayout(), $this->settings), $this->settings, $tenants, 'https://app.example.com/booking'));
        $this->service->setManageUrlBase('https://app.example.com/booking');
        $this->handler = new PublicBookingApiHandler(
            $this->service,
            $calendar,
            $services,
            $bookings,
            new ManageLinks($this->pdo),
            new CalendarFeed($this->pdo, $calendar, $bookings),
            $tenants,
            new HostResolver($tenants, ''),
            new BotCheck($globals, new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'), new HttpFetcher()),
            new DatabaseSharedStore($this->pdo),
            new BrandingService($this->settings),
            new BookingVisitor(fn (): ?array => null, $this->pdo),
        );

        $make = fn (string $en, string $ar): int => $services->insert(1, [
            'name' => ['en' => $en, 'ar' => $ar], 'description' => [], 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 12500, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->cake = $make('Cake tasting', 'تذوق الكعك');
        $this->tart = $make('Tart tasting', 'تذوق التارت');
        foreach ([11, 12] as $person) {
            $calendar->ensureStaff(1, $person);
            $calendar->replaceHours(1, $person, array_map(static fn (int $d): array => ['weekday' => $d, 'start_time' => '09:00', 'end_time' => '17:00'], range(1, 7)));
        }
        $services->replaceStaff(1, $this->cake, [11, 12]);
        $services->replaceStaff(1, $this->tart, [11]);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testJoiningOnThePageAndAtTheDeskWithWhatItNeeds(): void
    {
        $joined = $this->handler->joinWaitlist(self::request('POST', '/api/v1/public/booking/example-bakery/waitlist', $this->joinBody('Lina Haddad', 'lina@example.com')), ['workspace' => 'example-bakery']);
        self::assertSame(201, $joined->getStatusCode(), (string) $joined->getBody());
        self::assertSame(['2026-11-16', '2026-11-20', ['morning']], [self::data($joined)['data']['date_from'], self::data($joined)['data']['date_to'], self::data($joined)['data']['times']]);
        $spam = $this->handler->joinWaitlist(self::request('POST', '/x', ['website' => 'http://spam.example'] + $this->joinBody('Bot', 'bot@example.com')), ['workspace' => 'example-bakery']);
        self::assertSame(422, $spam->getStatusCode(), 'the hidden field');

        $waitlist = $this->service->waitlist();
        $entry = $waitlist->add(1, ['service_id' => $this->cake, 'profile_id' => 12, 'name' => 'Emad Nasser', 'phone' => '+962790000000', 'times' => ['morning', 'afternoon', 'evening']], 11, 'staff');
        self::assertSame([[], 'staff', '2026-11-15', '2026-11-15'], [$entry['times'], $entry['source'], $entry['date_from'], $entry['date_to']], 'every time of day is any; no days is today');
        self::assertSame(2, $waitlist->count(1));
        self::assertSame(['Lina Haddad', 'Emad Nasser'], array_map(static fn (array $e): string => $e['customer']['name'], $waitlist->list(1)));

        foreach ([['name' => 'No contact'], ['name' => 'Bad', 'email' => 'not-an-email'], ['name' => 'Late', 'email' => 'a@example.com', 'date_from' => '2026-11-20', 'date_to' => '2026-11-18'], ['name' => 'Odd', 'email' => 'a@example.com', 'times' => ['night']]] as $bad) {
            try {
                $waitlist->add(1, ['service_id' => $this->cake] + $bad, 11, 'staff');
                self::fail('Refused: ' . json_encode($bad));
            } catch (CalendarRejectedException $e) {
                self::assertSame(422, $e->status);
            }
        }
        $waitlist->remove(1, (int) $entry['id']);
        self::assertSame(1, $waitlist->count(1));
    }

    public function testAFreedTimeIsOfferedToEveryoneWhoMatchesAndTheFirstToTakeItGetsIt(): void
    {
        $booked = $this->book($this->cake, 11, '2026-11-16 07:00:00', 'Rana Haddad');
        $waitlist = $this->service->waitlist();
        $lina = $waitlist->add(1, ['service_id' => $this->cake, 'name' => 'Lina Haddad', 'email' => 'lina@example.com', 'date_from' => '2026-11-16', 'date_to' => '2026-11-17', 'times' => ['morning']], null, 'public');
        $ahmad = $waitlist->add(1, ['service_id' => $this->cake, 'profile_id' => 11, 'name' => 'Ahmad Khalil', 'email' => 'ahmad@example.com', 'date_from' => '2026-11-16', 'date_to' => '2026-11-16'], null, 'public');
        $evening = $waitlist->add(1, ['service_id' => $this->cake, 'name' => 'Evening Only', 'email' => 'evening@example.com', 'date_from' => '2026-11-16', 'date_to' => '2026-11-16', 'times' => ['evening']], null, 'public');
        $tart = $waitlist->add(1, ['service_id' => $this->tart, 'name' => 'Tart Only', 'email' => 'tart@example.com', 'date_from' => '2026-11-16', 'date_to' => '2026-11-16'], null, 'public');
        $awss = $waitlist->add(1, ['service_id' => $this->cake, 'profile_id' => 12, 'name' => 'Awss Only', 'email' => 'awss@example.com', 'date_from' => '2026-11-16', 'date_to' => '2026-11-16'], null, 'public');
        $this->sent = [];

        // 10:00 in Amman is freed: the two who want it then, with Amro or anyone, are offered it.
        $this->service->cancel(1, $booked, 'customer', '');
        $offers = $this->offerMails();
        self::assertSame(['lina@example.com', 'ahmad@example.com'], array_column($offers, 'to'), 'oldest first; not the evening, the tart or Awss');
        self::assertStringContainsString('the first to take it gets it', $offers[0]['text']);
        self::assertSame([Waitlist::OFFERED, Waitlist::OFFERED, Waitlist::WAITING, Waitlist::WAITING, Waitlist::WAITING], array_map(fn (array $e): string => $this->statusOf((int) $e['id']), [$lina, $ahmad, $evening, $tart, $awss]));
        [$linaToken, $ahmadToken] = array_map([self::class, 'tokenIn'], array_column($offers, 'text'));

        $page = self::data($this->handler->showOffer(self::request('GET', '/x'), ['token' => $ahmadToken]))['data'];
        self::assertSame(['open', '2026-11-16T07:00:00Z', 'Amro Saleh', 'Example Bakery'], [$page['state'], $page['starts_at'], $page['staff_name'], $page['workspace']['name']]);

        // Ahmad takes it first; Lina gets the plain message and stays on the list.
        $won = $this->handler->acceptOffer(self::request('POST', '/x'), ['token' => $ahmadToken]);
        self::assertSame(201, $won->getStatusCode(), (string) $won->getBody());
        self::assertSame('2026-11-16T07:00:00Z', self::data($won)['data']['starts_at']);
        $lost = $this->handler->acceptOffer(self::request('POST', '/x'), ['token' => $linaToken]);
        self::assertSame(409, $lost->getStatusCode());
        self::assertSame('Someone took this time first. You\'re still on the waitlist.', self::data($lost)['error'] ?? self::data($lost)['message'] ?? null);
        self::assertSame('taken', self::data($this->handler->showOffer(self::request('GET', '/x'), ['token' => $linaToken]))['data']['state']);
        self::assertSame([Waitlist::WAITING, Waitlist::BOOKED], [$this->statusOf((int) $lina['id']), $this->statusOf((int) $ahmad['id'])]);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM calendar_bookings WHERE tenant_id = 1 AND status = 'confirmed' AND starts_at = '2026-11-16 07:00:00'"));

        // The same link pressed again shows the booking, not a second one.
        self::assertSame(201, $this->handler->acceptOffer(self::request('POST', '/x'), ['token' => $ahmadToken])->getStatusCode());
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM calendar_bookings WHERE tenant_id = 1 AND status = 'confirmed' AND starts_at = '2026-11-16 07:00:00'"));
    }

    public function testAMoveFreesTheOldTimeAndAnOfferEndsBeforeTheMinimumNotice(): void
    {
        $booked = $this->book($this->cake, 11, '2026-11-16 07:00:00', 'Rana Haddad');
        $this->service->waitlist()->add(1, ['service_id' => $this->cake, 'name' => 'Lina Haddad', 'email' => 'lina@example.com', 'date_from' => '2026-11-16', 'date_to' => '2026-11-16'], null, 'public');
        $this->sent = [];
        $this->service->move(1, $booked, new DateTimeImmutable('2026-11-16 09:00:00', new DateTimeZone('UTC')), null, false, false);
        $offer = $this->offerMails();
        self::assertCount(1, $offer);
        $token = self::tokenIn($offer[0]['text']);

        // 07:00 UTC with 60 minutes' notice: the offer ends at 06:00.
        $this->now = '2026-11-16 06:00:00';
        self::assertSame('expired', self::data($this->handler->showOffer(self::request('GET', '/x'), ['token' => $token]))['data']['state']);
        $late = $this->handler->acceptOffer(self::request('POST', '/x'), ['token' => $token]);
        self::assertSame(409, $late->getStatusCode());
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM calendar_bookings WHERE tenant_id = 1 AND status = 'confirmed' AND starts_at = '2026-11-16 07:00:00'"));
    }

    public function testStaffOfferATimeByHandOnlyWhenItIsFree(): void
    {
        $entry = $this->service->waitlist()->add(1, ['service_id' => $this->cake, 'name' => 'Lina Haddad', 'email' => 'lina@example.com'], 11, 'staff');
        $this->book($this->cake, 11, '2026-11-16 07:00:00', 'Rana Haddad');
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        TenantContext::setTenantId(1);
        $desk = new CalendarApiHandler(new CalendarRepository($this->pdo), new ServiceRepository($this->pdo), new BookingRepository($this->pdo), $this->service, $this->settings, $roles);
        $offer = fn (string $at): Response => $desk->offerWaitlist(self::staffRequest(['profile_id' => 11, 'starts_at' => $at]), ['id' => (string) $entry['id']]);

        self::assertSame(409, $offer('2026-11-16T07:00:00Z')->getStatusCode(), 'that time is taken');
        $this->sent = [];
        $made = $offer('2026-11-16T08:00:00Z');
        self::assertSame(201, $made->getStatusCode(), (string) $made->getBody());
        self::assertArrayNotHasKey('token', self::data($made)['data'], 'the link goes only to the customer');
        self::assertSame(['lina@example.com'], array_column($this->sent, 'to'));
        $list = json_decode((string) $desk->listWaitlist(new Request('GET', '/x'))->getBody(), true);
        self::assertSame([1, 'offered'], [$list['count'], $list['data'][0]['status']]);
    }

    public function testTwoPeopleTakingTheSameTimeAtTheSameMomentMakeOneBooking(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('Needs separate Postgres connections.');
        }
        $booked = $this->book($this->cake, 11, '2026-11-16 07:00:00', 'Rana Haddad');
        foreach (range(1, 6) as $i) {
            $this->service->waitlist()->add(1, ['service_id' => $this->cake, 'name' => "Racer {$i}", 'email' => "racer{$i}@example.com", 'date_from' => '2026-11-16', 'date_to' => '2026-11-16'], null, 'public');
        }
        $this->sent = [];
        $this->service->cancel(1, $booked, 'customer', '');
        $tokens = array_map([self::class, 'tokenIn'], array_column($this->offerMails(), 'text'));
        self::assertCount(6, $tokens);

        [$dsn, $user, $password, $schema] = $this->connectionParts();
        $procs = [];
        $pipes = [];
        foreach ($tokens as $i => $token) {
            $procs[] = proc_open(
                [PHP_BINARY, __DIR__ . '/fixtures/take-offer.php', $dsn, $user, $password, $schema, $token, self::NOW],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
            );
        }
        $outcomes = [];
        foreach ($procs as $i => $proc) {
            $outcomes[] = trim((string) stream_get_contents($pipes[$i][1])) . trim((string) stream_get_contents($pipes[$i][2]));
            if (is_resource($proc)) {
                proc_close($proc);
            }
        }
        self::assertSame(1, count(array_filter($outcomes, static fn (string $o): bool => $o === 'booked')), implode(' | ', $outcomes));
        self::assertSame(5, count(array_filter($outcomes, static fn (string $o): bool => $o === 'taken_first')), implode(' | ', $outcomes));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM calendar_bookings WHERE tenant_id = 1 AND status = 'confirmed' AND starts_at = '2026-11-16 07:00:00'"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM calendar_holds WHERE tenant_id = 1 AND holder_kind = 'person' AND holder_id = 11 AND holds_from = '2026-11-16 07:00:00'"), 'the database holds the time once');
        $statuses = array_count_values(array_map(fn (array $e): string => (string) $e['status'], $this->service->waitlist()->list(1, [Waitlist::WAITING, Waitlist::OFFERED, Waitlist::BOOKED])));
        ksort($statuses);
        self::assertSame(['booked' => 1, 'waiting' => 5], $statuses, 'the others stay on the list');
    }

    /**
     * The database decides, not the app's check: another writer holds the
     * time in a transaction it hasn't committed, so the check inside the lock
     * can't see it. Taking the offer waits on the database's hold, and when
     * that writer commits, the database refuses this booking: "taken first".
     */
    public function testWhenTheAppCannotSeeTheOtherBookingTheDatabaseStillRefusesTheSecond(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('Needs separate Postgres connections.');
        }
        $booked = $this->book($this->cake, 11, '2026-11-16 07:00:00', 'Rana Haddad');
        $this->service->waitlist()->add(1, ['service_id' => $this->cake, 'name' => 'Lina Haddad', 'email' => 'lina@example.com', 'date_from' => '2026-11-16', 'date_to' => '2026-11-16'], null, 'public');
        $this->sent = [];
        $this->service->cancel(1, $booked, 'customer', '');
        $offers = $this->offerMails();
        self::assertCount(1, $offers);
        $token = self::tokenIn($offers[0]['text']);

        [$dsn, $user, $password, $schema] = $this->connectionParts();
        $other = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET search_path TO ' . $schema . ', public');
        $other->beginTransaction();
        // A writer that skips the app: straight into the table, not committed yet.
        $other->exec("INSERT INTO calendar_bookings (tenant_id, service_id, profile_id, customer_name, customer_email, customer_phone, customer_note,
                starts_at, ends_at, blocks_from, blocks_until, status, source, currency, created_at, updated_at)
            VALUES (1, {$this->cake}, 11, 'Another writer', '', '', '', '2026-11-16 07:00:00', '2026-11-16 07:30:00',
                '2026-11-16 07:00:00', '2026-11-16 07:30:00', 'confirmed', 'staff', 'JOD', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/take-offer.php', $dsn, $user, $password, $schema, $token, self::NOW],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        usleep(1_500_000);
        $other->commit();
        $outcome = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
        proc_close($proc);

        self::assertSame('taken_first', $outcome);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM calendar_bookings WHERE tenant_id = 1 AND status = 'confirmed' AND starts_at = '2026-11-16 07:00:00'"), 'only the other writer’s');
        self::assertSame('taken', self::data($this->handler->showOffer(self::request('GET', '/x'), ['token' => $token]))['data']['state']);
    }

    public function testTheMigrationRunsTwiceAndRollsBack(): void
    {
        require_once dirname(__DIR__, 3) . '/database/migrations/369_create_calendar_waitlist.php';
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $this->service->waitlist()->add(1, ['service_id' => $this->cake, 'name' => 'Lina Haddad', 'email' => 'lina@example.com'], 11, 'staff');
        CreateCalendarWaitlist::up($db);
        CreateCalendarWaitlist::up($db);
        self::assertSame(1, $this->service->waitlist()->count(1));
        CreateCalendarWaitlist::down($db);
        CreateCalendarWaitlist::down($db);
        CreateCalendarWaitlist::up($db);
        self::assertSame(0, $this->service->waitlist()->count(1));
    }

    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function joinBody(string $name, string $email): array
    {
        return [
            'service_id' => $this->cake, 'name' => $name, 'email' => $email,
            'date_from' => '2026-11-16', 'date_to' => '2026-11-20', 'times' => ['morning'], 'language' => 'en',
            'elapsed_ms' => 25000, 'website' => '',
        ];
    }

    private function book(int $service, int $profile, string $startsUtc, string $name): int
    {
        return (int) $this->service->book(1, [
            'service_id' => $service, 'profile_id' => $profile, 'starts_at' => new DateTimeImmutable($startsUtc, new DateTimeZone('UTC')),
            'customer_profile_id' => null, 'customer_name' => $name, 'customer_email' => '',
            'customer_phone' => '', 'customer_note' => '', 'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => 11, 'enforce_hours' => false, 'public' => false, 'notify' => false,
        ])['id'];
    }

    /** @return list<array{to: string, subject: string, text: string}> the offer emails sent */
    private function offerMails(): array
    {
        return array_values(array_filter($this->sent, static fn (array $m): bool => str_contains($m['subject'], 'A time is free')));
    }

    private function statusOf(int $entryId): string
    {
        return (string) ($this->service->waitlist()->find(1, $entryId)['status'] ?? '');
    }

    private static function tokenIn(string $text): string
    {
        self::assertSame(1, preg_match('#/booking/offer/([a-f0-9]{64})#', $text, $m), $text);

        return $m[1] ?? '';
    }

    /** @param array<string, mixed> $body */
    private static function request(string $method, string $path, array $body = []): Request
    {
        return new Request($method, $path, ['X-Whity-Client-Ip' => '203.0.113.7', 'Host' => 'app.example.com'], $body === [] ? '' : (string) json_encode($body));
    }

    /** @param array<string, mixed> $body */
    private static function staffRequest(array $body): Request
    {
        $r = new Request('POST', '/x', [], (string) json_encode($body));
        $r->user = (object) ['profile_id' => 11];

        return $r;
    }

    /** @return array<string, mixed> */
    private static function data(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, (string) $response->getBody());

        return $decoded;
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    /** @return array{string, string, string, string} */
    private function connectionParts(): array
    {
        $base = (string) ($_ENV['PHPUNIT_PG_DSN'] ?? getenv('PHPUNIT_PG_DSN'));
        $db = (string) $this->scalar('SELECT current_database()');
        $schema = (string) $this->scalar('SELECT current_schema()');
        $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $db, $base) ?? $base;
        $user = (string) ($_ENV['PHPUNIT_PG_USER'] ?? getenv('PHPUNIT_PG_USER') ?: 'whity');
        $password = (string) ($_ENV['PHPUNIT_PG_PASSWORD'] ?? getenv('PHPUNIT_PG_PASSWORD') ?: 'whity_dev');

        return [$dsn, $user, $password, $schema];
    }
}
