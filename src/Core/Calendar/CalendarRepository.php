<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Whity\Core\Db\DbBool;

/**
 * Staff calendars, weekly hours, exceptions and events (migration 247).
 *
 * Every method takes the tenant first and binds it (tenant-predicate guard).
 * Stateless apart from the PDO handle — worker-safe.
 */
final class CalendarRepository
{
    /** What an event is (ADR 0018). Bookings are kind `booking` in the read model only. */
    public const KIND_EVENT = 'event';
    public const KIND_MEETING = 'meeting';
    public const KIND_TIME_OFF = 'time_off';
    public const KIND_EXTERNAL = 'external';
    public const KINDS = [self::KIND_EVENT, self::KIND_MEETING, self::KIND_TIME_OFF, self::KIND_EXTERNAL];

    /** An invited person's answer. */
    public const RESPONSES = ['invited', 'accepted', 'declined', 'tentative'];

    private const EVENT_COLUMNS = 'id, profile_id, title, notes, starts_at, ends_at, all_day, busy, status, external_ref, kind, room_id, location, meeting_id';

    public function __construct(private readonly PDO $db)
    {
    }

    public function db(): PDO
    {
        return $this->db;
    }

    /** Is this person staff (a `member` membership) of the workspace? */
    public function isStaff(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM memberships
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND kind = 'member' AND status = 'active'
              LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }

    /** A person's display name ('' when unknown). Profiles are global, not tenant-owned. */
    public function displayName(int $profileId): string
    {
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $name = $stmt->fetchColumn();

        return is_string($name) ? $name : '';
    }

    /** The membership kind this person holds here, or null. */
    public function membershipKind(int $tenantId, int $profileId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT kind FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $kind = $stmt->fetchColumn();

        return is_string($kind) ? $kind : null;
    }

    /**
     * Staff who have a calendar here, with their names.
     *
     * @return list<array{profile_id: int, display_name: string, bookable: bool, sort_order: int, feed_enabled: bool, unit_id: ?int}>
     */
    public function listStaff(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT cs.profile_id, p.display_name, cs.bookable, cs.sort_order, cs.feed_token_hash, cs.unit_id
               FROM calendar_staff cs
               JOIN profiles p ON p.id = cs.profile_id
              WHERE cs.tenant_id = :tenant_id
              ORDER BY cs.sort_order, p.display_name, cs.profile_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return array_values(array_map(static fn (array $r): array => [
            'profile_id' => (int) $r['profile_id'],
            'display_name' => (string) $r['display_name'],
            'bookable' => DbBool::of($r['bookable']),
            'sort_order' => (int) $r['sort_order'],
            'feed_enabled' => $r['feed_token_hash'] !== null,
            'unit_id' => $r['unit_id'] !== null ? (int) $r['unit_id'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** Make sure a calendar row exists for this person (idempotent). */
    public function ensureStaff(int $tenantId, int $profileId): void
    {
        $this->db->prepare(
            'INSERT INTO calendar_staff (tenant_id, profile_id) VALUES (:tenant_id, :profile_id)
             ON CONFLICT (tenant_id, profile_id) DO NOTHING'
        )->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
    }

    public function updateStaff(int $tenantId, int $profileId, ?bool $bookable, ?int $sortOrder): void
    {
        $this->ensureStaff($tenantId, $profileId);
        $stmt = $this->db->prepare(
            'UPDATE calendar_staff
                SET bookable = COALESCE(:bookable, bookable),
                    sort_order = COALESCE(:sort_order, sort_order),
                    updated_at = :now
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $stmt->bindValue(':bookable', $bookable, $bookable === null ? PDO::PARAM_NULL : PDO::PARAM_BOOL);
        $stmt->bindValue(':sort_order', $sortOrder, $sortOrder === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':now', Interval::toDb(new DateTimeImmutable()));
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** The branch (organisational unit) this person works in, or none. */
    public function setStaffUnit(int $tenantId, int $profileId, ?int $unitId): void
    {
        $this->ensureStaff($tenantId, $profileId);
        $stmt = $this->db->prepare(
            'UPDATE calendar_staff SET unit_id = :unit_id, updated_at = :now
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $stmt->bindValue(':unit_id', $unitId, $unitId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':now', Interval::toDb(new DateTimeImmutable()));
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Take this person's calendar lock for the rest of the open transaction
     * (ADR 0017, section 3). Every write that can take time on their calendar
     * calls this first. SQLite serialises writers already, so there it is a
     * plain read.
     */
    public function lockStaff(int $tenantId, int $profileId): void
    {
        $this->ensureStaff($tenantId, $profileId);
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare(
            'SELECT id FROM calendar_staff WHERE tenant_id = :tenant_id AND profile_id = :profile_id' . $lock
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string}> */
    public function hours(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT weekday, start_time, end_time FROM calendar_working_hours
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id
              ORDER BY weekday, start_time'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return array_values(array_map(static fn (array $r): array => [
            'weekday' => (int) $r['weekday'],
            'start_time' => trim((string) $r['start_time']),
            'end_time' => trim((string) $r['end_time']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @param list<array{weekday: int, start_time: string, end_time: string}> $rows */
    public function replaceHours(int $tenantId, int $profileId, array $rows): void
    {
        $this->db->prepare('DELETE FROM calendar_working_hours WHERE tenant_id = :tenant_id AND profile_id = :profile_id')
            ->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $insert = $this->db->prepare(
            'INSERT INTO calendar_working_hours (tenant_id, profile_id, weekday, start_time, end_time)
             VALUES (:tenant_id, :profile_id, :weekday, :start_time, :end_time)'
        );
        foreach ($rows as $row) {
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':profile_id' => $profileId,
                ':weekday' => $row['weekday'],
                ':start_time' => $row['start_time'],
                ':end_time' => $row['end_time'],
            ]);
        }
    }

    /**
     * Exceptions touching `[from, to)`, for one person or (null) everyone.
     *
     * @return list<array{id: int, profile_id: int, kind: string, interval: Interval, note: string}>
     */
    public function exceptions(int $tenantId, ?int $profileId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $sql = 'SELECT id, profile_id, kind, starts_at, ends_at, note FROM calendar_availability_exceptions
                 WHERE tenant_id = :tenant_id AND starts_at < :to AND ends_at > :from';
        $params = [':tenant_id' => $tenantId, ':from' => Interval::toDb($from), ':to' => Interval::toDb($to)];
        if ($profileId !== null) {
            $sql .= ' AND profile_id = :profile_id';
            $params[':profile_id'] = $profileId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY starts_at');
        $stmt->execute($params);

        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'profile_id' => (int) $r['profile_id'],
            'kind' => (string) $r['kind'],
            'interval' => new Interval(Interval::fromDb((string) $r['starts_at']), Interval::fromDb((string) $r['ends_at'])),
            'note' => (string) $r['note'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function addException(int $tenantId, int $profileId, string $kind, Interval $interval, string $note, ?int $actor): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_availability_exceptions (tenant_id, profile_id, kind, starts_at, ends_at, note, created_by_profile_id)
             VALUES (:tenant_id, :profile_id, :kind, :starts_at, :ends_at, :note, :actor)
             RETURNING id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':kind' => $kind,
            ':starts_at' => Interval::toDb($interval->start),
            ':ends_at' => Interval::toDb($interval->end),
            ':note' => $note,
            ':actor' => $actor,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{id: int, profile_id: int}|null */
    public function findException(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, profile_id FROM calendar_availability_exceptions WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['id' => (int) $row['id'], 'profile_id' => (int) $row['profile_id']] : null;
    }

    public function deleteException(int $tenantId, int $id): void
    {
        $this->db->prepare('DELETE FROM calendar_availability_exceptions WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':tenant_id' => $tenantId, ':id' => $id]);
    }

    /**
     * Everything that blocks this person in `[from, to)`: live bookings and
     * requests with their buffers, pending holds that have not lapsed, and
     * busy events.
     *
     * @return list<Interval>
     */
    public function busy(int $tenantId, int $profileId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now, ?int $exceptBookingId = null): array
    {
        $stmt = $this->db->prepare(
            "SELECT blocks_from AS s, blocks_until AS e FROM calendar_bookings
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id
                AND blocks_from < :to AND blocks_until > :from
                AND id <> :except
                AND (status IN ('confirmed', 'requested') OR (status = 'pending_payment' AND hold_expires_at > :now))"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':from' => Interval::toDb($from),
            ':to' => Interval::toDb($to),
            ':except' => $exceptBookingId ?? 0,
            ':now' => Interval::toDb($now),
        ]);
        $busy = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $busy[] = new Interval(Interval::fromDb((string) $r['s']), Interval::fromDb((string) $r['e']));
        }

        $events = $this->db->prepare(
            "SELECT e.starts_at AS s, e.ends_at AS e FROM calendar_events e
              WHERE e.tenant_id = :tenant_id
                AND e.starts_at < :to AND e.ends_at > :from
                AND e.busy = :busy AND e.status = 'confirmed'
                AND (e.profile_id = :profile_id OR EXISTS (
                      SELECT 1 FROM calendar_event_people p
                       WHERE p.event_id = e.id AND p.tenant_id = e.tenant_id
                         AND p.profile_id = :profile_id AND p.response <> 'declined'))"
        );
        $events->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $events->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $events->bindValue(':from', Interval::toDb($from));
        $events->bindValue(':to', Interval::toDb($to));
        $events->bindValue(':busy', true, PDO::PARAM_BOOL);
        $events->execute();
        foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $busy[] = new Interval(Interval::fromDb((string) $r['s']), Interval::fromDb((string) $r['e']));
        }

        return $busy;
    }

    /**
     * Events in `[from, to)` for the given people (empty = everyone).
     *
     * @param list<int> $profileIds
     * @return list<array<string, mixed>>
     */
    public function listEvents(int $tenantId, array $profileIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $sql = 'SELECT ' . self::EVENT_COLUMNS . "
                  FROM calendar_events
                 WHERE tenant_id = :tenant_id AND starts_at < :to AND ends_at > :from AND status = 'confirmed'";
        $params = [':tenant_id' => $tenantId, ':from' => Interval::toDb($from), ':to' => Interval::toDb($to)];
        if ($profileIds !== []) {
            $in = [];
            foreach ($profileIds as $i => $id) {
                $in[] = ':p' . $i;
                $params[':p' . $i] = $id;
            }
            // The organiser, or anyone invited (ADR 0018).
            $list = implode(', ', $in);
            $sql .= " AND (profile_id IN ({$list}) OR id IN (
                          SELECT event_id FROM calendar_event_people WHERE tenant_id = :tenant_id AND profile_id IN ({$list})))";
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY starts_at, id');
        $stmt->execute($params);

        return $this->withPeople($tenantId, array_values(array_map([self::class, 'eventToApi'], $stmt->fetchAll(PDO::FETCH_ASSOC))));
    }

    /** The event a sync plugin wrote under its own reference, or null. */
    public function findEventByExternalRef(int $tenantId, int $profileId, string $externalRef): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM calendar_events
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND external_ref = :ref AND status = 'confirmed'
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':ref' => $externalRef]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array<string, mixed>|null */
    public function findEvent(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::EVENT_COLUMNS . ' FROM calendar_events WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->withPeople($tenantId, [self::eventToApi($row)])[0] : null;
    }

    public function insertEvent(int $tenantId, int $profileId, string $title, string $notes, Interval $when, bool $allDay, bool $busy, ?string $externalRef, ?int $actor, ?EventPlace $place = null, ?string $kind = null): int
    {
        $place ??= new EventPlace();
        $kind ??= $externalRef !== null ? self::KIND_EXTERNAL : self::KIND_EVENT;
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_events (tenant_id, profile_id, title, notes, starts_at, ends_at, all_day, busy, external_ref, created_by_profile_id, kind, room_id, location)
             VALUES (:tenant_id, :profile_id, :title, :notes, :starts_at, :ends_at, :all_day, :busy, :external_ref, :actor, :kind, :room_id, :location)
             RETURNING id'
        );
        $stmt->bindValue(':kind', $kind);
        $stmt->bindValue(':room_id', $place->roomId, $place->roomId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':location', $place->location);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':notes', $notes);
        $stmt->bindValue(':starts_at', Interval::toDb($when->start));
        $stmt->bindValue(':ends_at', Interval::toDb($when->end));
        $stmt->bindValue(':all_day', $allDay, PDO::PARAM_BOOL);
        $stmt->bindValue(':busy', $busy, PDO::PARAM_BOOL);
        $stmt->bindValue(':external_ref', $externalRef, $externalRef === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':actor', $actor, $actor === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function updateEvent(int $tenantId, int $id, string $title, string $notes, Interval $when, bool $allDay, bool $busy, ?EventPlace $place = null): void
    {
        $setPlace = $place !== null ? ', room_id = :room_id, location = :location' : '';
        $stmt = $this->db->prepare(
            "UPDATE calendar_events
                SET title = :title, notes = :notes, starts_at = :starts_at, ends_at = :ends_at,
                    all_day = :all_day, busy = :busy, updated_at = :now{$setPlace}
              WHERE tenant_id = :tenant_id AND id = :id"
        );
        if ($place !== null) {
            $stmt->bindValue(':room_id', $place->roomId, $place->roomId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':location', $place->location);
        }
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':notes', $notes);
        $stmt->bindValue(':starts_at', Interval::toDb($when->start));
        $stmt->bindValue(':ends_at', Interval::toDb($when->end));
        $stmt->bindValue(':all_day', $allDay, PDO::PARAM_BOOL);
        $stmt->bindValue(':busy', $busy, PDO::PARAM_BOOL);
        $stmt->bindValue(':now', Interval::toDb(new DateTimeImmutable()));
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function cancelEvent(int $tenantId, int $id): void
    {
        $this->db->prepare(
            "UPDATE calendar_events SET status = 'cancelled', updated_at = :now WHERE tenant_id = :tenant_id AND id = :id"
        )->execute([':now' => Interval::toDb(new DateTimeImmutable()), ':tenant_id' => $tenantId, ':id' => $id]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function eventToApi(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'profile_id' => (int) $r['profile_id'],
            'title' => (string) $r['title'],
            'notes' => (string) $r['notes'],
            'starts_at' => Interval::toApi(Interval::fromDb((string) $r['starts_at'])),
            'ends_at' => Interval::toApi(Interval::fromDb((string) $r['ends_at'])),
            'all_day' => DbBool::of($r['all_day']),
            'busy' => DbBool::of($r['busy']),
            'status' => (string) $r['status'],
            'external_ref' => $r['external_ref'] !== null ? (string) $r['external_ref'] : null,
            'kind' => (string) ($r['kind'] ?? self::KIND_EVENT),
            'room_id' => isset($r['room_id']) ? (int) $r['room_id'] : null,
            'location' => (string) ($r['location'] ?? ''),
            'meeting_id' => isset($r['meeting_id']) ? (int) $r['meeting_id'] : null,
            'people' => [],
        ];
    }

    /**
     * Who is invited to an event, replacing the list (ADR 0018). The organiser
     * is never listed among them. Answers already given are kept for people
     * who stay invited.
     *
     * @param list<int> $profileIds
     */
    public function setEventPeople(int $tenantId, int $eventId, array $profileIds, int $organiserId): void
    {
        $wanted = array_values(array_unique(array_filter($profileIds, static fn (int $id): bool => $id > 0 && $id !== $organiserId)));
        $current = array_map('intval', array_column($this->peopleOf($tenantId, [$eventId])[$eventId] ?? [], 'profile_id'));

        $remove = array_values(array_diff($current, $wanted));
        if ($remove !== []) {
            $params = [':tenant_id' => $tenantId, ':event_id' => $eventId];
            $in = [];
            foreach ($remove as $i => $id) {
                $in[] = ':r' . $i;
                $params[':r' . $i] = $id;
            }
            $this->db->prepare('DELETE FROM calendar_event_people WHERE tenant_id = :tenant_id AND event_id = :event_id AND profile_id IN (' . implode(', ', $in) . ')')
                ->execute($params);
        }
        $insert = $this->db->prepare(
            'INSERT INTO calendar_event_people (tenant_id, event_id, profile_id) VALUES (:tenant_id, :event_id, :profile_id)'
        );
        foreach (array_diff($wanted, $current) as $id) {
            $insert->execute([':tenant_id' => $tenantId, ':event_id' => $eventId, ':profile_id' => $id]);
        }
    }

    /** An invited person's answer. False when they are not invited. */
    public function answerEvent(int $tenantId, int $eventId, int $profileId, string $response): bool
    {
        if (!in_array($response, self::RESPONSES, true)) {
            throw new \InvalidArgumentException('Unknown answer: ' . $response);
        }
        $stmt = $this->db->prepare(
            'UPDATE calendar_event_people SET response = :response, responded_at = :now
              WHERE tenant_id = :tenant_id AND event_id = :event_id AND profile_id = :profile_id'
        );
        $stmt->execute([
            ':response' => $response,
            ':now' => Interval::toDb(new DateTimeImmutable()),
            ':tenant_id' => $tenantId,
            ':event_id' => $eventId,
            ':profile_id' => $profileId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * People invited to each event.
     *
     * @param list<int> $eventIds
     * @return array<int, list<array{profile_id: int, role: string, response: string}>>
     */
    public function peopleOf(int $tenantId, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $params = [':tenant_id' => $tenantId];
        $in = [];
        foreach ($eventIds as $i => $id) {
            $in[] = ':e' . $i;
            $params[':e' . $i] = $id;
        }
        $stmt = $this->db->prepare(
            'SELECT event_id, profile_id, role, response FROM calendar_event_people
              WHERE tenant_id = :tenant_id AND event_id IN (' . implode(', ', $in) . ')
              ORDER BY event_id, id'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['event_id']][] = ['profile_id' => (int) $r['profile_id'], 'role' => (string) $r['role'], 'response' => (string) $r['response']];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $events as {@see eventToApi()} gives them
     * @return list<array<string, mixed>>
     */
    private function withPeople(int $tenantId, array $events): array
    {
        $people = $this->peopleOf($tenantId, array_map(static fn (array $e): int => (int) $e['id'], $events));
        foreach ($events as $i => $event) {
            $events[$i]['people'] = $people[(int) $event['id']] ?? [];
        }

        return $events;
    }
}
