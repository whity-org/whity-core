<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Whity\Sdk\JobInterface;

/**
 * One reminder before one event or meeting ({@see EventReminders}). Queued
 * when the event is set or moved, delayed until it is due. A reminder more
 * than 30 days out (the queue's longest delay) re-queues itself.
 */
final class EventReminderJob implements JobInterface
{
    public const NAME = 'core.calendar.event_reminder';

    public function __construct(private readonly EventReminders $reminders)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $eventId = (int) ($payload['event_id'] ?? 0);
        $startsAt = is_string($payload['starts_at'] ?? null) ? $payload['starts_at'] : '';
        if ($tenantId <= 0 || $eventId <= 0 || $startsAt === '') {
            return ['skipped' => 'bad payload'];
        }

        $outcome = $this->reminders->send($tenantId, $eventId, $startsAt);
        if (is_array($outcome)) {
            $this->reminders->queueJob($tenantId, $eventId, $startsAt, $outcome['due'], $this->reminders->now());

            return ['requeued' => true];
        }

        return ['result' => $outcome];
    }
}
