<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PublicBookingApiHandler;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Calendar\BookingReminderJob;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarFeed;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\EmailBookingMessenger;
use Whity\Core\Calendar\ManageLinks;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Http\HttpFetcher;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;

/**
 * The public booking page, the customer's link, the spam checks, the ICS
 * file and feed, and the reminder job (WHIT-942, ADR 0017 sections 5 and 6).
 *
 * Example Bakery (tenant 1, slug `example-bakery`) takes bookings online for
 * a cake tasting with baker 11. Tenant 2 has its page off.
 */
final class PublicBookingRealEngineTest extends TestCase
{
    private const NOW = '2026-11-15 04:00:00';

    private PDO $pdo;
    private PublicBookingApiHandler $handler;
    private BookingService $service;
    private SettingsService $settings;
    private CalendarFeed $feed;
    private QueueService $queue;
    /** @var list<array{to: string, subject: string, text: string}> */
    private array $sent = [];
    private int $cake;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 21 => 2] as $profile => $tenant) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, 'Baker {$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }

        $globals = new GlobalSettingsRepository($this->pdo);
        $this->settings = new SettingsService($globals, new TenantSettingsRepository($this->pdo));
        foreach ([1, 2] as $tenant) {
            $this->settings->setTenant($tenant, 'timezone', 'Asia/Amman');
            $this->settings->setTenant($tenant, 'calendar.slot_step_minutes', '30');
            $this->settings->setTenant($tenant, 'calendar.min_notice_minutes', '60');
        }
        $this->settings->setTenant(1, 'calendar.booking_page_enabled', 'true');

        $calendar = new CalendarRepository($this->pdo);
        $services = new ServiceRepository($this->pdo);
        $bookings = new BookingRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $calendar, $services, $bookings, $this->settings, null, clock: static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
        $this->service->setLinks(new ManageLinks($this->pdo));
        $this->queue = new QueueService(new JobRepository($this->pdo));
        $this->service->setQueue($this->queue);
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
        $messenger = new EmailBookingMessenger(new \Whity\Core\Mail\BrandedEmailSender($mailer, new EmailLayout(), $this->settings), $this->settings, $tenants, 'https://app.example.com/booking');
        $messenger->setClock(static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
        $this->service->setMessenger($messenger);
        $this->feed = new CalendarFeed($this->pdo, $calendar, $bookings);

        $this->cake = $services->insert(1, [
            'name' => ['en' => 'Cake tasting', 'ar' => 'تذوق الكعك'], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 12500, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $services->replaceStaff(1, $this->cake, [11]);
        $calendar->ensureStaff(1, 11);
        $calendar->replaceHours(1, 11, [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']]);

        $this->handler = new PublicBookingApiHandler(
            $this->service,
            $calendar,
            $services,
            $bookings,
            new ManageLinks($this->pdo),
            $this->feed,
            $tenants,
            new HostResolver($tenants, ''),
            new BotCheck($globals, new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'), new HttpFetcher()),
            new DatabaseSharedStore($this->pdo),
            new BrandingService($this->settings),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @param array<string, mixed> $body */
    private static function request(string $method, string $path, array $body = [], string $ip = '203.0.113.7'): Request
    {
        return new Request($method, $path, ['X-Whity-Client-Ip' => $ip, 'Host' => 'app.example.com'], $body === [] ? '' : (string) json_encode($body));
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private static function data(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());

        return $decoded;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function bookingBody(array $overrides = []): array
    {
        return $overrides + [
            'service_id' => $this->cake,
            'starts_at' => '2026-11-16T10:00:00+03:00',
            'name' => 'Lina Haddad',
            'email' => 'lina@example.com',
            'elapsed_ms' => 25000,
            'website' => '',
        ];
    }

    public function testThePageShowsOnlyWhenTurnedOnAndForItsOwnHost(): void
    {
        $page = $this->handler->page(self::request('GET', '/api/v1/public/booking/example-bakery'), ['workspace' => 'example-bakery']);
        self::assertSame(200, $page->getStatusCode());
        $data = self::data($page)['data'];
        self::assertSame('Asia/Amman', $data['settings']['time_zone']);
        self::assertSame([$this->cake], array_column($data['services'], 'id'));
        self::assertSame([['profile_id' => 11, 'display_name' => 'Baker 11']], $data['staff']);

        $off = $this->handler->page(self::request('GET', '/api/v1/public/booking/other-bakery'), ['workspace' => 'other-bakery']);
        $unknown = $this->handler->page(self::request('GET', '/api/v1/public/booking/nobody'), ['workspace' => 'nobody']);
        self::assertSame(404, $off->getStatusCode());
        self::assertSame($off->getBody(), $unknown->getBody(), 'a page that is off and one that does not exist look the same');
    }

    public function testThePageCarriesTheWorkspacesOwnBrandAndNothingElse(): void
    {
        // WHIT-1014: the instance has its own name and colour; the bakery sets its own colour and logo.
        $this->settings->setGlobal('site_name', 'Platform');
        $this->settings->setGlobal('branding_main_color', '#c53829');
        $this->settings->setTenant(1, 'branding_main_color', '#b74c05');
        $this->settings->setTenantAsset(1, 'branding_logo_square', 'tenants/1/branding/logo_square-abc.png');
        $this->settings->setTenant(1, 'support_email', 'owner@example.com');

        $brand = self::data($this->handler->page(self::request('GET', '/x'), ['workspace' => 'example-bakery']))['data']['brand'];
        self::assertSame(['name', 'logo_wide_url', 'logo_square_url', 'main_color', 'theme'], array_keys($brand), 'only public brand fields');
        self::assertSame('Example Bakery', $brand['name'], 'the workspace name, not the instance name');
        self::assertSame('#b74c05', $brand['main_color']);
        self::assertSame('/api/v1/branding/asset/1/logo_square-abc.png', $brand['logo_square_url']);
        self::assertNull($brand['logo_wide_url']);
        self::assertSame('#b74c05', $brand['theme']['light']['primary']);
        self::assertArrayHasKey('primary-foreground', $brand['theme']['light'], 'the label colour derived for contrast comes too');
        self::assertStringNotContainsString('owner@example.com', (string) json_encode($brand));

        $this->settings->setTenant(1, 'site_name', 'Example Bakery & Cafe');
        $named = self::data($this->handler->page(self::request('GET', '/x'), ['workspace' => 'example-bakery']))['data']['brand'];
        self::assertSame('Example Bakery & Cafe', $named['name'], 'its own site name when it set one');
    }

    public function testWithoutItsOwnColourThePageKeepsTheDefault(): void
    {
        $brand = self::data($this->handler->page(self::request('GET', '/x'), ['workspace' => 'example-bakery']))['data']['brand'];
        self::assertNull($brand['main_color']);
        self::assertNull($brand['theme']);
        self::assertSame('Example Bakery', $brand['name']);
    }

    public function testTheManageLinkCarriesTheWorkspacesBrand(): void
    {
        $this->settings->setTenant(1, 'branding_main_color', '#00792f');
        $made = self::data($this->handler->book(self::request('POST', '/x', $this->bookingBody()), ['workspace' => 'example-bakery']))['data'];
        self::assertArrayNotHasKey('brand', $made, 'booking answers only the manage link adds it to');

        $shown = self::data($this->handler->showBooking(self::request('GET', '/x', [], '198.51.100.1'), ['token' => (string) $made['manage_token']]))['data'];
        self::assertSame('#00792f', $shown['brand']['main_color']);
        self::assertSame('Example Bakery', $shown['brand']['name']);
    }

    public function testABookingIsMadeConfirmedAndEmailedWithItsLink(): void
    {
        $response = $this->handler->book(self::request('POST', '/api/v1/public/booking/example-bakery/bookings', $this->bookingBody()), ['workspace' => 'example-bakery']);
        self::assertSame(201, $response->getStatusCode(), $response->getBody());
        $booking = self::data($response)['data'];
        self::assertSame('confirmed', $booking['status']);
        self::assertSame('2026-11-16T07:00:00Z', $booking['starts_at']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $booking['manage_token']);

        self::assertCount(1, $this->sent);
        self::assertSame('lina@example.com', $this->sent[0]['to']);
        self::assertStringContainsString('Booked: Cake tasting', $this->sent[0]['subject']);
        // 3.4 `booking-confirmed`: the short date in the workspace's zone (10:00 in Amman).
        self::assertMatchesRegularExpression('/When: Mon 16 Nov( 2026)?, 10:00/', $this->sent[0]['text']);
        self::assertStringContainsString('Hello Lina Haddad, see you soon.', $this->sent[0]['text']);
        self::assertStringContainsString('https://app.example.com/booking/' . $booking['manage_token'], $this->sent[0]['text']);
    }

    public function testTheHiddenFieldAndAHastyFormAreRefused(): void
    {
        foreach ([['website' => 'http://spam.example'], ['elapsed_ms' => 800], ['elapsed_ms' => null]] as $spam) {
            $response = $this->handler->book(self::request('POST', '/x', $this->bookingBody($spam)), ['workspace' => 'example-bakery']);
            self::assertSame(422, $response->getStatusCode());
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM calendar_bookings'));
    }

    public function testOneAddressHoldsAtMostThreeOpenBookingsAndOneIpTenAttempts(): void
    {
        foreach (['10:00', '11:00', '12:00'] as $time) {
            $ok = $this->handler->book(self::request('POST', '/x', $this->bookingBody(['starts_at' => '2026-11-16T' . $time . ':00+03:00'])), ['workspace' => 'example-bakery']);
            self::assertSame(201, $ok->getStatusCode(), $ok->getBody());
        }
        $fourth = $this->handler->book(self::request('POST', '/x', $this->bookingBody(['starts_at' => '2026-11-16T13:00:00+03:00'])), ['workspace' => 'example-bakery']);
        self::assertSame(422, $fourth->getStatusCode());

        for ($i = 0; $i < 6; $i++) {
            $this->handler->book(self::request('POST', '/x', $this->bookingBody(['website' => 'x'])), ['workspace' => 'example-bakery']);
        }
        $limited = $this->handler->book(self::request('POST', '/x', $this->bookingBody()), ['workspace' => 'example-bakery']);
        self::assertSame(429, $limited->getStatusCode());
        self::assertContains('retry-after', array_map('strtolower', array_keys($limited->getHeaders())));
    }

    public function testTheLinkShowsMovesAndCancelsAndAnOldLinkStopsWorking(): void
    {
        $made = self::data($this->handler->book(self::request('POST', '/x', $this->bookingBody()), ['workspace' => 'example-bakery']))['data'];
        $token = (string) $made['manage_token'];

        $shown = $this->handler->showBooking(self::request('GET', '/x', [], '198.51.100.1'), ['token' => $token]);
        self::assertSame(200, $shown->getStatusCode());
        self::assertSame('Example Bakery', self::data($shown)['data']['workspace']['name']);
        self::assertSame(1, TenantContext::getTenantId(), 'the token names its own workspace');

        $moved = $this->handler->move(self::request('POST', '/x', ['starts_at' => '2026-11-16T14:00:00+03:00'], '198.51.100.1'), ['token' => $token]);
        self::assertSame(200, $moved->getStatusCode(), $moved->getBody());
        $newToken = (string) self::data($moved)['data']['manage_token'];
        self::assertNotSame($token, $newToken);
        self::assertSame(404, $this->handler->showBooking(self::request('GET', '/x', [], '198.51.100.1'), ['token' => $token])->getStatusCode());

        $ics = $this->handler->ics(self::request('GET', '/x', [], '198.51.100.1'), ['token' => $newToken]);
        self::assertSame(200, $ics->getStatusCode());
        self::assertStringContainsString("DTSTART:20261116T110000Z\r\n", $ics->getBody());

        $cancelled = $this->handler->cancel(self::request('POST', '/x', ['reason' => 'Travelling'], '198.51.100.1'), ['token' => $newToken]);
        self::assertSame('cancelled', self::data($cancelled)['data']['status']);
        self::assertSame(404, $this->handler->showBooking(self::request('GET', '/x', [], '198.51.100.1'), ['token' => $newToken])->getStatusCode());
        self::assertStringContainsString('Cancelled: Cake tasting', $this->sent[count($this->sent) - 1]['subject']);
    }

    public function testTheFeedListsBookingsAndAReplacedAddressStopsWorking(): void
    {
        $this->handler->book(self::request('POST', '/x', $this->bookingBody()), ['workspace' => 'example-bakery']);
        $first = $this->feed->issue(1, 11);
        $feed = $this->handler->feed(self::request('GET', '/x'), ['token' => $first . '.ics']);
        self::assertSame(200, $feed->getStatusCode());
        self::assertStringContainsString('SUMMARY:Cake tasting · Lina Haddad', $feed->getBody());

        $second = $this->feed->issue(1, 11);
        self::assertSame(404, $this->handler->feed(self::request('GET', '/x'), ['token' => $first . '.ics'])->getStatusCode());
        self::assertSame(200, $this->handler->feed(self::request('GET', '/x'), ['token' => $second . '.ics'])->getStatusCode());
    }

    public function testTheConfirmationCarriesTheBookingAsAnIcsAttachment(): void
    {
        $captured = [];
        $mailer = new class (static function (OutgoingEmail $email) use (&$captured): void {
            $captured[] = $email;
        }) implements Mailer, ThreadedMailer {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                ($this->record)(new OutgoingEmail($toEmail, $subject, $textBody, $htmlBody));
            }

            public function sendThreaded(OutgoingEmail $email): void
            {
                ($this->record)($email);
            }
        };
        $this->service->setMessenger(new EmailBookingMessenger(new \Whity\Core\Mail\BrandedEmailSender($mailer, new EmailLayout(), $this->settings), $this->settings, new TenantHostRepository($this->pdo), 'https://app.example.com/booking'));

        $made = self::data($this->handler->book(self::request('POST', '/x', $this->bookingBody()), ['workspace' => 'example-bakery']))['data'];
        self::assertCount(1, $captured);
        self::assertCount(1, $captured[0]->attachments);
        $ics = $captured[0]->attachments[0];
        self::assertSame('booking.ics', $ics->filename);
        self::assertStringContainsString('DTSTART:20261116T070000Z', $ics->content);
        self::assertStringContainsString('UID:booking-' . $made['id'] . '-1@whity', $ics->content);

        self::assertStringContainsString('METHOD:REQUEST', $ics->content);
        self::assertStringContainsString('method=REQUEST', $ics->contentType);
        self::assertStringContainsString('SEQUENCE:0', $ics->content);
        self::assertStringContainsString('ATTENDEE;CN="Lina Haddad"', $ics->content);

        // A move updates the same entry: same UID, a higher SEQUENCE.
        $this->service->move(1, (int) $made['id'], new DateTimeImmutable('2026-11-16 08:00:00', new DateTimeZone('UTC')), null, false);
        $moved = $captured[1]->attachments[0];
        self::assertStringContainsString('METHOD:REQUEST', $moved->content);
        self::assertStringContainsString('UID:booking-' . $made['id'] . '-1@whity', $moved->content);
        self::assertStringContainsString('SEQUENCE:1', $moved->content);
        self::assertStringContainsString('DTSTART:20261116T080000Z', $moved->content);

        // The cancel removes it: METHOD:CANCEL, same UID, higher again.
        $this->service->cancel(1, (int) $made['id'], 'staff', '');
        $cancel = $captured[2]->attachments[0];
        self::assertStringContainsString('METHOD:CANCEL', $cancel->content);
        self::assertStringContainsString('method=CANCEL', $cancel->contentType);
        self::assertStringContainsString('STATUS:CANCELLED', $cancel->content);
        self::assertStringContainsString('UID:booking-' . $made['id'] . '-1@whity', $cancel->content);
        self::assertStringContainsString('SEQUENCE:2', $cancel->content);
    }

    public function testTheReminderIsQueuedSentOnceAndSkippedAfterAMove(): void
    {
        $made = self::data($this->handler->book(self::request('POST', '/x', $this->bookingBody()), ['workspace' => 'example-bakery']))['data'];
        $job = $this->scalar("SELECT payload FROM jobs WHERE name = '" . BookingReminderJob::NAME . "'");
        self::assertIsString($job, 'a reminder was queued');
        $payload = json_decode($job, true);
        self::assertIsArray($payload);

        $reminders = new BookingReminderJob($this->service, $this->queue);
        // Due 24 hours before 10:00 Amman, which is three hours after "now".
        self::assertSame(['requeued' => true], $reminders->handle($payload), 'not due yet: queued again');

        $this->pdo->exec("UPDATE calendar_bookings SET reminder_due_at = '2026-11-15 03:00:00' WHERE tenant_id = 1");
        $before = count($this->sent);
        self::assertSame(['result' => 'sent'], $reminders->handle($payload));
        self::assertStringContainsString('Tomorrow: Cake tasting', $this->sent[$before]['subject']);
        self::assertSame(['result' => 'nothing due'], $reminders->handle($payload), 'a second run sends nothing');
        self::assertCount($before + 1, $this->sent);

        $this->service->move(1, (int) $made['id'], new DateTimeImmutable('2026-11-16 12:00:00', new DateTimeZone('UTC')), null, false);
        self::assertSame(['result' => 'moved'], $reminders->handle($payload));
    }
}
