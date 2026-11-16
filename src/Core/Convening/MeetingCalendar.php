<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Interval;
use Whity\Core\i18n\LocalizedText;

/**
 * A meeting's place on the calendar (ADR 0018, WHIT-1112).
 *
 * A formal meeting keeps its record in Convening: body, number, agenda,
 * decisions, attendance. Its time, place and guests live on one calendar event
 * of kind `meeting` (`calendar_events.meeting_id`), so a meeting shows on the
 * calendar and blocks the time of everyone invited who has not declined, like
 * any other event.
 *
 * {@see self::sync()} writes that event from the meeting and its invitations.
 * The meeting stays the source:
 *  - scheduling, inviting, answering, holding and cancelling all call it;
 *  - an answer given on the calendar goes back to the invitation
 *    ({@see CalendarRepository::answerEvent()}).
 *
 * A meeting with only a start lasts {@see self::LENGTH_MINUTES} (owner,
 * 16 Nov 2026). A draft (no date) has no event.
 *
 * The organiser is whoever created the meeting, else the body's chair, else the
 * first person invited. For a meeting only the people invited are busy, so an
 * organiser who declined is free.
 */
final class MeetingCalendar
{
    public const LENGTH_MINUTES = 60;

    public function __construct(private readonly PDO $db)
    {
    }

    /** Bring the meeting's event in line with the meeting. Returns the event id, or null when it has none. */
    public function sync(int $tenantId, int $meetingId): ?int
    {
        $meeting = $this->meeting($tenantId, $meetingId);
        if ($meeting === null) {
            return null;
        }
        $eventId = $this->eventFor($tenantId, $meetingId);
        $scheduled = $meeting['scheduled_at'];
        if (!is_string($scheduled) || $scheduled === '' || $meeting['status'] === MeetingStatus::DRAFT) {
            if ($eventId !== null) {
                $this->setStatus($tenantId, $eventId, 'cancelled');
            }

            return $eventId;
        }

        $invitations = $this->invitations($tenantId, $meetingId);
        $organiser = $this->organiser($tenantId, $meeting, $invitations);
        if ($organiser === null) {
            return $eventId;
        }

        $start = Interval::fromDb($scheduled);
        $end = $start->modify('+' . self::LENGTH_MINUTES . ' minutes');
        $title = mb_substr(self::title((string) $meeting['title']), 0, 200);
        $location = mb_substr((string) ($meeting['location'] ?? ''), 0, 255);
        $status = $meeting['status'] === MeetingStatus::CANCELLED ? 'cancelled' : 'confirmed';
        $now = Interval::toDb(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        if ($eventId === null) {
            $stmt = $this->db->prepare(
                "INSERT INTO calendar_events
                    (tenant_id, profile_id, title, notes, starts_at, ends_at, all_day, busy, status, kind, location, meeting_id, legacy_ref, created_by_profile_id, created_at, updated_at)
                 VALUES (:tenant_id, :profile_id, :title, '', :starts_at, :ends_at, :all_day, :busy, :status, :kind, :location, :meeting_id, :legacy_ref, :actor, :now, :now2)
                 RETURNING id"
            );
            $stmt->bindValue(':all_day', false, PDO::PARAM_BOOL);
            $stmt->bindValue(':busy', true, PDO::PARAM_BOOL);
            $stmt->bindValue(':kind', CalendarRepository::KIND_MEETING);
            $stmt->bindValue(':meeting_id', $meetingId, PDO::PARAM_INT);
            $stmt->bindValue(':legacy_ref', 'meeting:' . $meetingId);
            $stmt->bindValue(':actor', $organiser, PDO::PARAM_INT);
            $stmt->bindValue(':now2', $now);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE calendar_events
                    SET profile_id = :profile_id, title = :title, starts_at = :starts_at, ends_at = :ends_at,
                        status = :status, location = :location, updated_at = :now
                  WHERE tenant_id = :tenant_id AND id = :id'
            );
            $stmt->bindValue(':id', $eventId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $organiser, PDO::PARAM_INT);
        $stmt->bindValue(':title', $title !== '' ? $title : 'Meeting');
        $stmt->bindValue(':starts_at', Interval::toDb($start));
        $stmt->bindValue(':ends_at', Interval::toDb($end));
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':location', $location);
        $stmt->bindValue(':now', $now);
        $stmt->execute();
        $eventId ??= (int) $stmt->fetchColumn();

        $this->syncPeople($tenantId, $eventId, $invitations);

        return $eventId;
    }

    /** The event that holds this meeting's time, or null. */
    public function eventFor(int $tenantId, int $meetingId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM calendar_events WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id');
        $stmt->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** A title stored as a `{language: text}` map, or plain text, as one line. */
    public static function title(string $stored): string
    {
        $decoded = LocalizedText::decode($stored, languages: LocalizedText::BILINGUAL);
        $text = $decoded['en'] ?? (string) (reset($decoded) ?: '');

        return trim($text !== '' ? $text : $stored);
    }

    /** @return array<string, mixed>|null */
    private function meeting(int $tenantId, int $meetingId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, body_id, title, scheduled_at, location, status, created_by_profile_id
               FROM meetings WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $meetingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array{profile_id: int, status: string}> */
    private function invitations(int $tenantId, int $meetingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT profile_id, status FROM meeting_invitations
              WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id ORDER BY id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);

        return array_values(array_map(
            static fn (array $r): array => ['profile_id' => (int) $r['profile_id'], 'status' => (string) $r['status']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /**
     * @param array<string, mixed> $meeting
     * @param list<array{profile_id: int, status: string}> $invitations
     */
    private function organiser(int $tenantId, array $meeting, array $invitations): ?int
    {
        if (isset($meeting['created_by_profile_id'])) {
            return (int) $meeting['created_by_profile_id'];
        }
        $stmt = $this->db->prepare(
            "SELECT profile_id FROM convening_body_members
              WHERE tenant_id = :tenant_id AND body_id = :body_id AND member_role = 'chair' AND left_at IS NULL
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':body_id' => (int) $meeting['body_id']]);
        $chair = $stmt->fetchColumn();
        if ($chair !== false) {
            return (int) $chair;
        }

        return $invitations[0]['profile_id'] ?? null;
    }

    /**
     * The event's people are exactly the meeting's invitations, with their
     * answers. The organiser is listed too when invited: for a meeting, only
     * people invited are busy.
     *
     * @param list<array{profile_id: int, status: string}> $invitations
     */
    private function syncPeople(int $tenantId, int $eventId, array $invitations): void
    {
        $this->db->prepare('DELETE FROM calendar_event_people WHERE tenant_id = :tenant_id AND event_id = :event_id')
            ->execute([':tenant_id' => $tenantId, ':event_id' => $eventId]);
        $insert = $this->db->prepare(
            'INSERT INTO calendar_event_people (tenant_id, event_id, profile_id, response) VALUES (:tenant_id, :event_id, :profile_id, :response)'
        );
        foreach ($invitations as $invitation) {
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':event_id' => $eventId,
                ':profile_id' => $invitation['profile_id'],
                ':response' => in_array($invitation['status'], CalendarRepository::RESPONSES, true) ? $invitation['status'] : 'invited',
            ]);
        }
    }

    private function setStatus(int $tenantId, int $eventId, string $status): void
    {
        $this->db->prepare('UPDATE calendar_events SET status = :status WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':status' => $status, ':tenant_id' => $tenantId, ':id' => $eventId]);
    }
}
