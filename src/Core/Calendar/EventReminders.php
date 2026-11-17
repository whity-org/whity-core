<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Queue\QueueService;

/**
 * Staff reminders before events and meetings (ADR 0018, WHIT-1113).
 *
 * Fifteen minutes before an event or a meeting starts, the organiser and
 * everyone invited who hasn't declined get a reminder. It is an in-app notice
 * that push follows to their devices. Email is off unless the person turned it
 * on for reminders (owner, 16 Nov 2026). Time off, extra hours and synced
 * events never remind.
 *
 * Like booking reminders ({@see BookingReminderJob}), each reminder is a queued
 * job delayed until it is due, keyed by the event and its start. When it runs
 * it re-reads the event and does nothing if it was cancelled, moved (the move
 * queued its own) or already reminded.
 */
final class EventReminders
{
    public const TYPE = 'calendar.event.reminder';
    public const LEAD_MINUTES = 15;

    /** The queue's longest delay (HostJobQueue::MAX_DELAY_SECONDS). */
    private const MAX_DELAY_SECONDS = 2_592_000;

    private const KINDS = [CalendarRepository::KIND_EVENT, CalendarRepository::KIND_MEETING];

    public function __construct(
        private readonly PDO $db,
        private readonly CalendarRepository $calendar,
        private readonly QueueService $queue,
        private readonly ?NotificationDispatcher $notifications = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** Set (or move) the event's reminder and queue it; nothing for kinds that never remind or times already past. */
    public function schedule(int $tenantId, int $eventId): void
    {
        $event = $this->calendar->findEvent($tenantId, $eventId);
        if ($event === null || !in_array($event['kind'], self::KINDS, true) || $event['status'] !== 'confirmed' || $event['all_day']) {
            return;
        }
        $start = Interval::fromApi($event['starts_at']);
        if ($start === null) {
            return;
        }
        $due = $start->modify('-' . self::LEAD_MINUTES . ' minutes');
        $now = $this->now();
        if ($due <= $now) {
            return;
        }
        $this->db->prepare('UPDATE calendar_events SET reminder_due_at = :due, reminder_sent_at = NULL WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':due' => Interval::toDb($due), ':tenant_id' => $tenantId, ':id' => $eventId]);
        $this->queueJob($tenantId, $eventId, (string) $event['starts_at'], $due, $now);
    }

    /**
     * Send the reminder if it is still the right one. Returns what happened;
     * `['due' => …]` when it is not due yet and must be queued again.
     *
     * @return string|array{due: DateTimeImmutable}
     */
    public function send(int $tenantId, int $eventId, string $startsAt): string|array
    {
        $event = $this->calendar->findEvent($tenantId, $eventId);
        if ($event === null || $event['status'] !== 'confirmed') {
            return 'cancelled';
        }
        if ($event['starts_at'] !== $startsAt) {
            return 'moved';
        }
        $stmt = $this->db->prepare('SELECT reminder_due_at, reminder_sent_at FROM calendar_events WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['reminder_sent_at'] !== null || $row['reminder_due_at'] === null) {
            return 'already';
        }
        $due = Interval::fromDb((string) $row['reminder_due_at']);
        if ($due > $this->now()) {
            return ['due' => $due];
        }
        // Claim it first, so two workers never remind twice.
        $claim = $this->db->prepare('UPDATE calendar_events SET reminder_sent_at = :now WHERE tenant_id = :tenant_id AND id = :id AND reminder_sent_at IS NULL');
        $claim->execute([':now' => Interval::toDb($this->now()), ':tenant_id' => $tenantId, ':id' => $eventId]);
        if ($claim->rowCount() === 0) {
            return 'already';
        }

        $recipients = array_map(static fn (array $p): int => (int) $p['profile_id'], array_filter($event['people'], static fn (array $p): bool => $p['response'] !== 'declined'));
        if ($event['kind'] !== CalendarRepository::KIND_MEETING) {
            $recipients[] = (int) $event['profile_id'];
        }
        foreach (array_values(array_unique($recipients)) as $profileId) {
            try {
                $this->notifications?->dispatch($tenantId, $profileId, self::TYPE, [
                    'channels' => $this->wantsEmail($tenantId, $profileId) ? ['in_app', 'email'] : ['in_app'],
                    'subject' => sprintf('In %d minutes: %s', self::LEAD_MINUTES, (string) $event['title']),
                    'body' => trim((string) $event['location']) !== '' ? 'Where: ' . trim((string) $event['location']) : '',
                    'data' => ['event_id' => $eventId, 'meeting_id' => $event['meeting_id'], 'starts_at' => $event['starts_at']],
                ]);
            } catch (Throwable $e) {
                $this->logger?->warning('[calendar] could not send an event reminder', ['event' => $eventId, 'error' => $e->getMessage()]);
            }
        }

        return 'sent';
    }

    public function queueJob(int $tenantId, int $eventId, string $startsAt, DateTimeImmutable $due, DateTimeImmutable $now): void
    {
        $delay = max(0, min(self::MAX_DELAY_SECONDS, $due->getTimestamp() - $now->getTimestamp()));
        $this->queue->dispatch(
            EventReminderJob::NAME,
            ['tenant_id' => $tenantId, 'event_id' => $eventId, 'starts_at' => $startsAt],
            [
                'tenant_id' => $tenantId,
                'delay' => $delay,
                'idempotency_key' => 'event-reminder:' . $eventId . ':' . $startsAt . ':' . intdiv($delay, self::MAX_DELAY_SECONDS),
                'max_attempts' => 3,
            ],
        );
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Email reminders are off unless the person turned them on. */
    private function wantsEmail(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT enabled FROM user_notification_preferences
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND type = :type AND channel = 'email'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':type' => self::TYPE]);
        $enabled = $stmt->fetchColumn();

        return $enabled !== false && in_array($enabled, [true, 1, '1', 't', 'true'], true);
    }
}
