<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailOptions;
use Whity\Core\Mail\MailAttachment;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * One way to tell people about an event they are invited to (ADR 0018,
 * WHIT-1113): an everyday meeting, a formal meeting, any event with people.
 *
 * Each person gets three things:
 *  - an in-app notice, which push follows to their devices;
 *  - an email with an `.ics` the calendar app adds, updates or removes.
 *
 * The `.ics` keeps one UID for the event's whole life, and its SEQUENCE goes up
 * with every change, so calendar apps replace the event rather than add a copy
 * (SU.8's rule for bookings, now for every event). Customer booking emails keep
 * their own templates and the same rule.
 *
 * Who hears what:
 *  - invited: the people just added;
 *  - changed (a new time or place): everyone invited who hasn't declined;
 *  - cancelled: the same, with METHOD:CANCEL.
 *
 * Formal meetings keep the notice kinds people already chose for
 * (`convening.meeting.*`); other events use `calendar.event.*`.
 *
 * Fail-soft per person, like every notice: one unreachable address never
 * stops the rest, and nothing here rolls back the event.
 */
final class CalendarInvites
{
    public const TYPE_INVITED = 'calendar.event.invited';
    public const TYPE_UPDATED = 'calendar.event.updated';
    public const TYPE_CANCELLED = 'calendar.event.cancelled';
    public const MEETING_INVITED = 'convening.meeting.invited';
    public const MEETING_UPDATED = 'convening.meeting.updated';
    public const MEETING_CANCELLED = 'convening.meeting.cancelled';

    private const EN = [
        'invited' => 'You’re invited: %s',
        'updated' => 'Changed: %s',
        'cancelled' => 'Cancelled: %s',
        'invited.lead' => '%s invited you. It’s on your calendar.',
        'updated.lead' => 'The time or place changed. Your calendar has the new one.',
        'cancelled.lead' => 'This is cancelled. It’s off your calendar.',
        'when' => 'When',
        'where' => 'Where',
        'open' => 'Open the calendar',
        'answer' => 'Say whether you’ll come on the calendar.',
    ];

    private const AR = [
        'invited' => 'دعوة: %s',
        'updated' => 'تغيير: %s',
        'cancelled' => 'أُلغي: %s',
        'invited.lead' => 'دعاك %s، وهو الآن في تقويمك.',
        'updated.lead' => 'تغيّر الموعد أو المكان، وفي تقويمك الموعد الجديد.',
        'cancelled.lead' => 'أُلغي هذا، وحُذف من تقويمك.',
        'when' => 'الموعد',
        'where' => 'المكان',
        'open' => 'افتح التقويم',
        'answer' => 'أخبرنا في التقويم إن كنت ستحضر.',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly CalendarRepository $calendar,
        private readonly NotificationDispatcher $notifications,
        private readonly ?BrandedEmailSender $sender,
        private readonly SettingsService $settings,
        private readonly string $calendarUrl,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @param list<int> $profileIds the people just invited */
    public function invited(int $tenantId, int $eventId, array $profileIds): void
    {
        $event = $this->calendar->findEvent($tenantId, $eventId);
        if ($event === null || $profileIds === [] || $event['status'] !== 'confirmed') {
            return;
        }
        $this->tell($tenantId, $event, 'invited', $profileIds);
    }

    /** A new time or place: everyone invited who hasn't declined. */
    public function changed(int $tenantId, int $eventId): void
    {
        $event = $this->bump($tenantId, $eventId);
        if ($event !== null) {
            $this->tell($tenantId, $event, 'updated', self::stillComing($event));
        }
    }

    public function cancelled(int $tenantId, int $eventId): void
    {
        $event = $this->bump($tenantId, $eventId);
        if ($event !== null) {
            $this->tell($tenantId, $event, 'cancelled', self::stillComing($event));
        }
    }

    /** The UID an event keeps for its whole life, in every file about it. */
    public static function uid(int $tenantId, int $eventId): string
    {
        return 'event-' . $eventId . '-' . $tenantId . '@whity';
    }

    /**
     * @param array<string, mixed> $event
     * @return list<int>
     */
    private static function stillComing(array $event): array
    {
        return array_values(array_map(
            static fn (array $p): int => (int) $p['profile_id'],
            array_filter($event['people'], static fn (array $p): bool => $p['response'] !== 'declined'),
        ));
    }

    /**
     * Raise the event's `.ics` revision, so calendar apps take the new one.
     *
     * @return array<string, mixed>|null
     */
    private function bump(int $tenantId, int $eventId): ?array
    {
        $this->db->prepare('UPDATE calendar_events SET ics_sequence = ics_sequence + 1 WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':tenant_id' => $tenantId, ':id' => $eventId]);

        return $this->calendar->findEvent($tenantId, $eventId);
    }

    /**
     * @param array<string, mixed> $event
     * @param list<int>            $profileIds
     */
    private function tell(int $tenantId, array $event, string $what, array $profileIds): void
    {
        $meeting = $event['kind'] === CalendarRepository::KIND_MEETING;
        $type = match ($what) {
            'invited' => $meeting ? self::MEETING_INVITED : self::TYPE_INVITED,
            'updated' => $meeting ? self::MEETING_UPDATED : self::TYPE_UPDATED,
            default => $meeting ? self::MEETING_CANCELLED : self::TYPE_CANCELLED,
        };
        $effective = $this->settings->effective($tenantId);
        $arabic = str_starts_with((string) ($effective[SettingsRegistry::LOCALE] ?? 'en'), 'ar');
        $t = $arabic ? self::AR : self::EN;
        $zone = CalendarSettings::fromSettings($effective)->zone;
        $start = Interval::fromApi($event['starts_at']) ?? new DateTimeImmutable();
        $end = Interval::fromApi($event['ends_at']) ?? $start;
        $when = EmailBookingMessenger::when($start->setTimezone($zone), $arabic);
        $where = trim((string) $event['location']);
        $organiser = $this->calendar->displayName((int) $event['profile_id']);
        $subject = sprintf($t[$what], (string) $event['title']);
        $url = $this->calendarUrl . '?date=' . $start->setTimezone($zone)->format('Y-m-d');
        $lead = $what === 'invited' ? sprintf($t['invited.lead'], $organiser) : $t[$what . '.lead'];

        foreach (array_values(array_unique($profileIds)) as $profileId) {
            try {
                $this->notifications->dispatch($tenantId, $profileId, $type, [
                    'subject' => $subject,
                    'body' => $lead . "\n" . $t['when'] . ': ' . $when . ($where !== '' ? "\n" . $t['where'] . ': ' . $where : ''),
                    'data' => [
                        'event_id' => (int) $event['id'],
                        'meeting_id' => $event['meeting_id'],
                        'starts_at' => $event['starts_at'],
                        'url' => $url,
                    ],
                ]);
            } catch (Throwable $e) {
                $this->logger?->warning('[calendar] could not post an invitation notice', ['event' => $event['id'], 'error' => $e->getMessage()]);
            }
            $this->email($tenantId, $profileId, $type, $event, $what, $t, $arabic, $subject, $lead, $when, $where, $url, $start, $end, $organiser);
        }
    }

    /**
     * @param array<string, mixed>  $event
     * @param array<string, string> $t
     */
    private function email(
        int $tenantId,
        int $profileId,
        string $type,
        array $event,
        string $what,
        array $t,
        bool $arabic,
        string $subject,
        string $lead,
        string $when,
        string $where,
        string $url,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $organiser,
    ): void {
        if ($this->sender === null || !$this->sender->gate()->allows($tenantId, $profileId, $type)) {
            return;
        }
        $address = $this->primaryEmail($profileId);
        if ($address === null) {
            return;
        }
        try {
            $method = $what === 'cancelled' ? 'CANCEL' : 'REQUEST';
            $ics = Ics::calendar([[
                'uid' => self::uid($tenantId, (int) $event['id']),
                'start' => $start,
                'end' => $end,
                'summary' => (string) $event['title'],
                'description' => $where,
                'status' => $method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED',
                'sequence' => (int) $this->sequence($tenantId, (int) $event['id']),
                'url' => $url,
                'attendee' => ['name' => $this->calendar->displayName($profileId), 'email' => $address],
            ]], '', $method);
            $rows = [[$t['when'], $when]];
            if ($where !== '') {
                $rows[] = [$t['where'], $where];
            }
            $this->sender->deliver(
                $tenantId,
                $address,
                $subject,
                new EmailContent(
                    heading: $subject,
                    paragraphs: $what === 'cancelled' ? [$lead] : [$lead, $t['answer']],
                    ctaLabel: $t['open'],
                    ctaUrl: $url,
                    rows: $rows,
                    language: $arabic ? 'ar' : 'en',
                ),
                new EmailOptions(attachments: [new MailAttachment('invite.ics', 'text/calendar; charset=utf-8; method=' . $method, $ics)]),
            );
        } catch (Throwable $e) {
            $this->logger?->warning('[calendar] could not email an invitation', ['event' => $event['id'], 'organiser' => $organiser, 'error' => $e->getMessage()]);
        }
    }

    private function sequence(int $tenantId, int $eventId): int
    {
        $stmt = $this->db->prepare('SELECT ics_sequence FROM calendar_events WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $eventId]);

        return (int) $stmt->fetchColumn();
    }

    private function primaryEmail(int $profileId): ?string
    {
        // profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT email FROM profile_emails WHERE profile_id = :id AND is_primary = TRUE LIMIT 1');
        $stmt->execute([':id' => $profileId]);
        $email = $stmt->fetchColumn();

        return is_string($email) && $email !== '' ? $email : null;
    }
}
