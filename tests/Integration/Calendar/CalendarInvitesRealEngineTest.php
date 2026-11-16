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
use Whity\Core\Calendar\CalendarInvites;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailGate;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * One invite path (ADR 0018, WHIT-1113): people invited to an event get a
 * notice and an email with an `.ics` that keeps one UID and raises its
 * SEQUENCE with every change; a cancellation sends METHOD:CANCEL.
 *
 * Example Bakery (tenant 1): Amro (13) organises; bakers 11 and 12 are invited.
 */
final class CalendarInvitesRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private CalendarRepository $calendar;

    /** @var list<OutgoingEmail> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 'Awss Haddad', 12 => 'Ahmad Khalil', 13 => 'Amro Saleh'] as $id => $name) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $this->pdo->exec("INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES ({$id}, 'p{$id}@examplebakery.test', true, true, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setTenant(1, 'timezone', 'Asia/Amman');
        $mailer = new class (function (OutgoingEmail $e): void {
            $this->sent[] = $e;
        }) implements Mailer, ThreadedMailer {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                ($this->sink)(new OutgoingEmail($toEmail, $subject, $textBody, $htmlBody));
            }

            public function sendThreaded(OutgoingEmail $email): void
            {
                ($this->sink)($email);
            }
        };
        $sender = new BrandedEmailSender($mailer, new EmailLayout(), $settings);
        $sender->useGate(new EmailGate($settings, $this->pdo));
        $dispatcher = new NotificationDispatcher(
            new NotificationRepository($this->pdo),
            CoreTransports::make(),
            new QueueService(new JobRepository($this->pdo)),
            null,
            null,
            new NotificationPreferenceResolver(new NotificationPreferenceRepository($this->pdo)),
            null,
        );

        $this->calendar = new CalendarRepository($this->pdo);
        foreach ([11, 12, 13] as $id) {
            $this->calendar->ensureStaff(1, $id);
        }
        $this->service = new BookingService(
            $this->pdo,
            $this->calendar,
            new ServiceRepository($this->pdo),
            new BookingRepository($this->pdo),
            $settings,
            null,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-15 04:00:00', new DateTimeZone('UTC')),
        );
        $this->service->setInvites(new CalendarInvites($this->pdo, $this->calendar, $dispatcher, $sender, $settings, 'https://app.example.test/calendar'));
    }

    public function testEveryoneInvitedGetsANoticeAndAnInvitationTheirCalendarAccepts(): void
    {
        $id = $this->create([11, 12]);

        self::assertSame([[11, 'calendar.event.invited'], [12, 'calendar.event.invited']], $this->notices());
        self::assertSame(['p11@examplebakery.test', 'p12@examplebakery.test'], $this->recipients());
        $email = $this->email(0);
        self::assertSame('You’re invited: Menu tasting', $email->subject);
        self::assertCount(1, $email->attachments);
        // Long lines are folded (RFC 5545); unfold to read them.
        $ics = str_replace("\r\n ", '', $email->attachments[0]->content);
        self::assertStringContainsString('METHOD:REQUEST', $ics);
        self::assertStringContainsString('UID:' . CalendarInvites::uid(1, $id), $ics);
        self::assertStringContainsString('SEQUENCE:0', $ics);
        self::assertStringContainsString('p11@examplebakery.test', $ics);
    }

    public function testAMoveTellsThoseStillComingWithTheSameUidAndAHigherSequence(): void
    {
        $id = $this->create([11, 12]);
        $this->calendar->answerEvent(1, $id, 12, 'declined');
        $this->sent = [];

        $this->service->updateEvent(1, $id, 'Menu tasting', '', $this->when('2026-11-16T11:00:00+03:00', '2026-11-16T12:00:00+03:00'), false, true);

        self::assertSame(['p11@examplebakery.test'], $this->recipients(), 'baker 12 declined');
        $ics = $this->email(0)->attachments[0]->content;
        self::assertStringContainsString('UID:' . CalendarInvites::uid(1, $id), $ics);
        self::assertStringContainsString('SEQUENCE:1', $ics);
        self::assertSame('Changed: Menu tasting', $this->email(0)->subject);
    }

    public function testAddingSomeoneInvitesOnlyThem(): void
    {
        $id = $this->create([11]);
        $this->sent = [];

        $this->service->updateEvent(1, $id, 'Menu tasting', '', $this->when('2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00'), false, true, people: [11, 12]);

        self::assertSame(['p12@examplebakery.test'], $this->recipients());
    }

    public function testCancellingSendsACancelTheirCalendarRemoves(): void
    {
        $id = $this->create([11]);
        $this->sent = [];

        $this->service->cancelEvent(1, $id);

        self::assertCount(1, $this->recipients());
        self::assertSame('Cancelled: Menu tasting', $this->email(0)->subject);
        $ics = $this->email(0)->attachments[0]->content;
        self::assertStringContainsString('METHOD:CANCEL', $ics);
        self::assertStringContainsString('STATUS:CANCELLED', $ics);
        self::assertStringContainsString('SEQUENCE:1', $ics);
    }

    public function testAnEventWithNobodyInvitedTellsNobody(): void
    {
        $this->create([]);

        self::assertSame([], $this->recipients());
        self::assertSame([], $this->notices());
    }

    public function testAPersonWhoTurnedOffTheseEmailsStillGetsTheNotice(): void
    {
        $this->pdo->exec("INSERT INTO user_notification_preferences (tenant_id, profile_id, type, channel, enabled) VALUES (1, 11, 'calendar.event.invited', 'email', false)");

        $this->create([11]);

        self::assertSame([], $this->recipients());
        self::assertSame([[11, 'calendar.event.invited']], $this->notices());
    }

    /** @param list<int> $people */
    private function create(array $people): int
    {
        return (int) $this->service->createEvent(1, 13, 'Menu tasting', '', $this->when('2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00'), false, true, 13, people: $people)['event']['id'];
    }

    /** @return list<array{0: int, 1: string}> */
    private function notices(): array
    {
        $rows = $this->pdo->query('SELECT recipient_profile_id, type FROM notifications ORDER BY id');
        self::assertNotFalse($rows);

        return array_values(array_map(static fn (array $r): array => [(int) $r['recipient_profile_id'], (string) $r['type']], $rows->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return list<string> who the emails went to, in order */
    private function recipients(): array
    {
        return array_map(static fn (OutgoingEmail $e): string => $e->to, $this->sentEmails());
    }

    private function email(int $index): OutgoingEmail
    {
        return $this->sentEmails()[$index] ?? throw new \LogicException('no email ' . $index);
    }

    /** @return list<OutgoingEmail> */
    private function sentEmails(): array
    {
        return $this->sent;
    }

    private function when(string $from, string $to): Interval
    {
        return new Interval(Interval::fromApi($from) ?? throw new \LogicException(), Interval::fromApi($to) ?? throw new \LogicException());
    }
}
