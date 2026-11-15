<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;

/**
 * Meetings block calendar time (issue #1933, WHIT-1051).
 *
 * Convening meetings and calendar bookings are two time models; this makes a
 * scheduled meeting count as busy time for everyone invited who has not
 * declined, so a booking is never offered or made over it. Read-only: it
 * never changes a meeting.
 *
 * A meeting has a start but no end, so it blocks `LENGTH_MINUTES` from its
 * start. `scheduled_at` is stored in UTC like the calendar's own times.
 *
 * Fails open like every busy source (ADR 0017, section 4): BookingService
 * logs a failure and books as if the meeting were not there.
 */
final class MeetingBusySource implements ExternalBusySource
{
    /** How long a meeting with no end time blocks. */
    public const LENGTH_MINUTES = 60;

    public function __construct(private readonly PDO $db)
    {
    }

    public function busyTimes(int $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.scheduled_at
               FROM meetings m
               JOIN meeting_invitations i ON i.meeting_id = m.id AND i.tenant_id = m.tenant_id
              WHERE m.tenant_id = :tenant_id
                AND i.profile_id = :profile_id
                AND i.status <> 'declined'
                AND m.status = 'scheduled'
                AND m.scheduled_at IS NOT NULL
                AND m.scheduled_at < :to
                AND m.scheduled_at >= :from"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            // A meeting that started up to its length before the range still overlaps it.
            ':from' => Interval::toDb($fromUtc->modify('-' . self::LENGTH_MINUTES . ' minutes')),
            ':to' => Interval::toDb($toUtc),
        ]);
        $busy = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $at) {
            if (!is_string($at)) {
                continue;
            }
            $start = Interval::fromDb($at);
            $busy[] = new Interval($start, $start->modify('+' . self::LENGTH_MINUTES . ' minutes'));
        }

        return $busy;
    }
}
