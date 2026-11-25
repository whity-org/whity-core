<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Throwable;
use Whity\Core\Queue\QueueService;
use Whity\Sdk\JobInterface;

/**
 * The guarantee behind booking reminders (WHIT-1417, design section 39
 * §5.2, AP.14).
 *
 * Each reminder is first a delayed {@see BookingReminderJob}. If the queue
 * loses it (Redis flushed, a worker crash beyond its retries, a deploy that
 * cleared delayed jobs), nothing would send it. Every 5 minutes `schedule:run`
 * queues this job; it finds every confirmed booking whose reminder is due,
 * unsent and still ahead, in every workspace, and queues its reminder job
 * now. The reminder is claimed when it is sent, so a booking found here and
 * by its own delayed job is reminded once.
 */
final class BookingReminderSweepJob implements JobInterface
{
    public const NAME = 'core.calendar.booking_reminder_sweep';

    /** At most this many reminders a run; the next run takes the rest. */
    public const BATCH = 500;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly QueueService $queue,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $queued = 0;
        $failed = 0;
        foreach ($this->bookings->dueReminders(self::BATCH) as $due) {
            try {
                $this->queue->dispatch(
                    BookingReminderJob::NAME,
                    ['tenant_id' => $due['tenant_id'], 'booking_id' => $due['id'], 'starts_at' => $due['starts_at']],
                    [
                        'tenant_id' => $due['tenant_id'],
                        'idempotency_key' => 'calendar-reminder-sweep:' . $due['id'] . ':' . $due['starts_at'] . ':' . intdiv($this->bookings->now()->getTimestamp(), 300),
                        'max_attempts' => 3,
                    ],
                );
                $queued++;
            } catch (Throwable $e) {
                $failed++;
                \Whity\Core\Log\ScrubbedErrorLog::failure('[calendar] the reminder sweep could not queue booking ' . $due['id'], $e);
            }
        }

        return ['queued' => $queued, 'failed' => $failed];
    }
}
