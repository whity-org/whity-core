<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use Whity\Core\Queue\QueueService;
use Whity\Sdk\JobInterface;

/**
 * One reminder for one booking (ADR 0017, section 5).
 *
 * Queued when a booking is confirmed or moved, delayed until its
 * `reminder_due_at`, with an idempotency key of booking id plus start time.
 * When it runs it re-reads the booking and does nothing if it was cancelled,
 * moved (the move queued its own job) or already reminded. A reminder more
 * than 30 days out (the queue's longest delay) re-queues itself at the limit.
 */
final class BookingReminderJob implements JobInterface
{
    public const NAME = 'core.calendar.booking_reminder';

    /** The queue's longest delay (HostJobQueue::MAX_DELAY_SECONDS). */
    private const MAX_DELAY_SECONDS = 2_592_000;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly QueueService $queue,
    ) {
    }

    /** Queue the reminder for a booking; a no-op when none is due. */
    public static function schedule(QueueService $queue, int $tenantId, int $bookingId, string $startsAt, ?DateTimeImmutable $dueAt, DateTimeImmutable $now): void
    {
        if ($dueAt === null) {
            return;
        }
        $delay = max(0, min(self::MAX_DELAY_SECONDS, $dueAt->getTimestamp() - $now->getTimestamp()));
        $queue->dispatch(
            self::NAME,
            ['tenant_id' => $tenantId, 'booking_id' => $bookingId, 'starts_at' => $startsAt],
            [
                'tenant_id' => $tenantId,
                'delay' => $delay,
                'idempotency_key' => 'calendar-reminder:' . $bookingId . ':' . $startsAt . ':' . $dueAt->getTimestamp() . ':' . intdiv($delay, self::MAX_DELAY_SECONDS),
                'max_attempts' => 3,
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $bookingId = (int) ($payload['booking_id'] ?? 0);
        $startsAt = is_string($payload['starts_at'] ?? null) ? $payload['starts_at'] : '';
        if ($tenantId <= 0 || $bookingId <= 0) {
            return ['skipped' => 'bad payload'];
        }

        $outcome = $this->bookings->sendReminder($tenantId, $bookingId, $startsAt);
        if (is_array($outcome)) {
            // Not due yet: more than 30 days out when it was queued.
            self::schedule($this->queue, $tenantId, $bookingId, $startsAt, $outcome['due'], $this->bookings->now());

            return ['requeued' => true];
        }

        return ['result' => $outcome];
    }
}
