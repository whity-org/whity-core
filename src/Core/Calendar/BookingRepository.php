<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Whity\Core\i18n\LocalizedText;
use DateTimeImmutable;
use PDO;

/**
 * Bookings (migration 248). Tenant first on every method; the manage-token
 * lookup is the one global read and is reasoned where it lives (WHIT-942).
 */
final class BookingRepository
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    /**
     * A customer asked for this time and staff accept it first: the service
     * needs approval, or the workspace turned instant booking off. A request
     * holds its time (and room) like a booking, gets no reminder, and becomes
     * `confirmed` when accepted.
     */
    public const STATUS_REQUESTED = 'requested';

    /** Statuses that hold time on a calendar. */
    public const LIVE = [self::STATUS_CONFIRMED, self::STATUS_PENDING_PAYMENT, self::STATUS_REQUESTED];

    public const SOURCE_STAFF = 'staff';
    public const SOURCE_PUBLIC = 'public';
    public const SOURCE_AGENT = 'agent';
    /** WHIT-1334: handed over from the walk-in queue at the front desk. */
    public const SOURCE_WALKIN = 'walkin';

    private const COLUMNS = 'b.id, b.service_id, b.profile_id, b.customer_profile_id, b.customer_name, b.customer_email,
        b.customer_phone, b.customer_note, b.starts_at, b.ends_at, b.blocks_from, b.blocks_until, b.status,
        b.hold_expires_at, b.source, b.price_minor, b.currency, b.payment_ref, b.reminder_due_at, b.reminder_sent_at,
        b.created_by_profile_id, b.cancelled_by, b.cancel_reason, b.cancelled_at, b.created_at, b.tenant_id,
        b.room_id, b.checked_in_at, b.suggested_at, b.ics_sequence, b.confirmed_by_customer_at';

    /** WHIT-1417: what happened to one reminder on one channel. */
    public const REMINDER_SENT = 'sent';
    public const REMINDER_FAILED = 'failed';
    public const REMINDER_NO_ADDRESS = 'no_address';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * `overlap_ok`: staff chose Book anyway over a busy person, so the
     * database lets this booking's person overlap (migration 360; a room never).
     *
     * @param array<string, mixed> $v
     */
    public function insert(int $tenantId, array $v): int
    {
        // A literal, not a bound PHP bool: PDO sends false as '' to PostgreSQL.
        $overlapOk = ($v['overlap_ok'] ?? false) === true ? 'TRUE' : 'FALSE';
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_bookings (tenant_id, service_id, profile_id, customer_profile_id, customer_name,
                    customer_email, customer_phone, customer_note, starts_at, ends_at, blocks_from, blocks_until,
                    status, hold_expires_at, source, price_minor, currency, reminder_due_at, created_by_profile_id,
                    room_id, contact_id, overlap_ok, created_at, updated_at)
             VALUES (:tenant_id, :service_id, :profile_id, :customer_profile_id, :customer_name, :customer_email,
                    :customer_phone, :customer_note, :starts_at, :ends_at, :blocks_from, :blocks_until, :status,
                    :hold_expires_at, :source, :price_minor, :currency, :reminder_due_at, :created_by, :room_id, :contact_id, ' . $overlapOk . ', :now, :now)
             RETURNING id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':service_id' => $v['service_id'],
            ':profile_id' => $v['profile_id'],
            ':customer_profile_id' => $v['customer_profile_id'],
            ':customer_name' => $v['customer_name'],
            ':customer_email' => $v['customer_email'],
            ':customer_phone' => $v['customer_phone'],
            ':customer_note' => $v['customer_note'],
            ':starts_at' => Interval::toDb($v['starts_at']),
            ':ends_at' => Interval::toDb($v['ends_at']),
            ':blocks_from' => Interval::toDb($v['block']->start),
            ':blocks_until' => Interval::toDb($v['block']->end),
            ':status' => $v['status'],
            ':hold_expires_at' => $v['hold_expires_at'] !== null ? Interval::toDb($v['hold_expires_at']) : null,
            ':source' => $v['source'],
            ':price_minor' => $v['price_minor'],
            ':currency' => $v['currency'],
            ':reminder_due_at' => $v['reminder_due_at'] !== null ? Interval::toDb($v['reminder_due_at']) : null,
            ':created_by' => $v['created_by_profile_id'],
            ':room_id' => $v['room_id'] ?? null,
            // Contacts is the one customer record: the booking points at it.
            ':contact_id' => (new \Whity\Core\Contacts\CustomerContacts($this->db))->forCustomer(
                $tenantId,
                (string) $v['customer_name'],
                (string) $v['customer_email'],
                (string) $v['customer_phone'],
                $v['customer_profile_id'] !== null ? (int) $v['customer_profile_id'] : null,
            ),
            ':now' => Interval::toDb(new DateTimeImmutable()),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM calendar_bookings b WHERE b.tenant_id = :tenant_id AND b.id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['items'] = $this->itemsFor($tenantId, [$id])[$id] ?? [];

        return $row;
    }

    /**
     * Store the services of a booking of several (WHIT-1173), in order.
     *
     * @param list<array{service_id: int, profile_id: int, room_id: ?int, starts_at: DateTimeImmutable, ends_at: DateTimeImmutable, block: Interval, price_minor: ?int}> $items
     */
    public function insertItems(int $tenantId, int $bookingId, array $items): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_booking_services (tenant_id, booking_id, service_id, profile_id, room_id, position,
                    starts_at, ends_at, blocks_from, blocks_until, price_minor, created_at)
             VALUES (:tenant_id, :booking_id, :service_id, :profile_id, :room_id, :position,
                    :starts_at, :ends_at, :blocks_from, :blocks_until, :price_minor, :now)'
        );
        foreach ($items as $position => $item) {
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':booking_id' => $bookingId,
                ':service_id' => $item['service_id'],
                ':profile_id' => $item['profile_id'],
                ':room_id' => $item['room_id'],
                ':position' => $position,
                ':starts_at' => Interval::toDb($item['starts_at']),
                ':ends_at' => Interval::toDb($item['ends_at']),
                ':blocks_from' => Interval::toDb($item['block']->start),
                ':blocks_until' => Interval::toDb($item['block']->end),
                ':price_minor' => $item['price_minor'],
                ':now' => Interval::toDb(new DateTimeImmutable()),
            ]);
        }
    }

    /**
     * Move the services of a booking of several: each to its new time and person.
     *
     * @param list<array{profile_id: int, starts_at: DateTimeImmutable, ends_at: DateTimeImmutable, block: Interval}> $items in position order
     */
    public function moveItems(int $tenantId, int $bookingId, array $items): void
    {
        $stmt = $this->db->prepare(
            'UPDATE calendar_booking_services
                SET profile_id = :profile_id, starts_at = :starts_at, ends_at = :ends_at,
                    blocks_from = :blocks_from, blocks_until = :blocks_until
              WHERE tenant_id = :tenant_id AND booking_id = :booking_id AND position = :position'
        );
        foreach ($items as $position => $item) {
            $stmt->execute([
                ':profile_id' => $item['profile_id'],
                ':starts_at' => Interval::toDb($item['starts_at']),
                ':ends_at' => Interval::toDb($item['ends_at']),
                ':blocks_from' => Interval::toDb($item['block']->start),
                ':blocks_until' => Interval::toDb($item['block']->end),
                ':tenant_id' => $tenantId,
                ':booking_id' => $bookingId,
                ':position' => $position,
            ]);
        }
    }

    /**
     * The services of these bookings, by booking id, in order. A booking of
     * one service has none.
     *
     * @param list<int> $bookingIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function itemsFor(int $tenantId, array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }
        $in = [];
        $params = [':tenant_id' => $tenantId];
        foreach (array_values(array_unique($bookingIds)) as $i => $id) {
            $in[] = ':b' . $i;
            $params[':b' . $i] = $id;
        }
        $stmt = $this->db->prepare(
            'SELECT i.booking_id, i.position, i.service_id, i.profile_id, i.room_id, i.starts_at, i.ends_at,
                    i.blocks_from, i.blocks_until, i.price_minor, s.name AS service_name, p.display_name AS staff_name
               FROM calendar_booking_services i
               LEFT JOIN calendar_services s ON s.id = i.service_id AND s.tenant_id = i.tenant_id
               LEFT JOIN profiles p ON p.id = i.profile_id
              WHERE i.tenant_id = :tenant_id AND i.booking_id IN (' . implode(', ', $in) . ')
              ORDER BY i.booking_id, i.position'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['booking_id']][] = $row;
        }

        return $out;
    }

    /**
     * Bookings whose service time touches `[from, to)`.
     *
     * @param list<int> $profileIds empty = everyone
     * @param list<string> $statuses empty = live ones (confirmed, pending payment, requested)
     * @param int|null $unitId only bookings whose person or room is in this branch
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $profileIds, DateTimeImmutable $from, DateTimeImmutable $to, array $statuses = [], ?int $unitId = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ', s.name AS service_name, p.display_name AS staff_name, r.name AS room_name
                  FROM calendar_bookings b
                  JOIN calendar_services s ON s.id = b.service_id AND s.tenant_id = b.tenant_id
                  JOIN profiles p ON p.id = b.profile_id
                  LEFT JOIN calendar_rooms r ON r.id = b.room_id AND r.tenant_id = b.tenant_id
                 WHERE b.tenant_id = :tenant_id AND b.starts_at < :to AND b.ends_at > :from';
        $params = [':tenant_id' => $tenantId, ':from' => Interval::toDb($from), ':to' => Interval::toDb($to)];
        if ($unitId !== null) {
            $sql .= ' AND (r.unit_id = :unit_id OR EXISTS (SELECT 1 FROM calendar_staff cs
                        WHERE cs.tenant_id = b.tenant_id AND cs.profile_id = b.profile_id AND cs.unit_id = :unit_id2))';
            $params[':unit_id'] = $unitId;
            $params[':unit_id2'] = $unitId;
        }
        if ($profileIds !== []) {
            $in = [];
            foreach ($profileIds as $i => $id) {
                $in[] = ':p' . $i;
                $params[':p' . $i] = $id;
            }
            // WHIT-1173: and bookings where one of them does one of the services.
            $inItems = [];
            foreach ($profileIds as $i => $id) {
                $inItems[] = ':ip' . $i;
                $params[':ip' . $i] = $id;
            }
            $sql .= ' AND (b.profile_id IN (' . implode(', ', $in) . ')
                       OR EXISTS (SELECT 1 FROM calendar_booking_services i
                                   WHERE i.tenant_id = b.tenant_id AND i.booking_id = b.id
                                     AND i.profile_id IN (' . implode(', ', $inItems) . ')))';
        }
        $statuses = $statuses !== [] ? $statuses : self::LIVE;
        $in = [];
        foreach ($statuses as $i => $status) {
            $in[] = ':s' . $i;
            $params[':s' . $i] = $status;
        }
        $sql .= ' AND b.status IN (' . implode(', ', $in) . ') ORDER BY b.starts_at, b.id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
        $items = $this->itemsFor($tenantId, array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $out = [];
        foreach ($rows as $row) {
            $row['items'] = $items[(int) $row['id']] ?? [];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Live bookings a day in the workspace's zone, split by person, room and
     * service so a screen can leave out what the person unticked (design
     * 3.5, CV.7: Year shades each day by how many bookings it has).
     *
     * @return list<array{date: string, profile_id: int, room_id: ?int, service_id: int, count: int}>
     */
    public function dayCounts(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to, \DateTimeZone $zone): array
    {
        $in = [];
        $params = [':tenant_id' => $tenantId, ':from' => Interval::toDb($from), ':to' => Interval::toDb($to)];
        foreach (self::LIVE as $i => $status) {
            $in[] = ':s' . $i;
            $params[':s' . $i] = $status;
        }
        $stmt = $this->db->prepare(
            'SELECT starts_at, profile_id, room_id, service_id FROM calendar_bookings
              WHERE tenant_id = :tenant_id AND starts_at >= :from AND starts_at < :to AND status IN (' . implode(', ', $in) . ')'
        );
        $stmt->execute($params);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = Interval::fromDb((string) $row['starts_at'])->setTimezone($zone)->format('Y-m-d');
            $room = $row['room_id'] !== null ? (int) $row['room_id'] : null;
            $key = $date . '|' . (int) $row['profile_id'] . '|' . ($room ?? '') . '|' . (int) $row['service_id'];
            $counts[$key] ??= ['date' => $date, 'profile_id' => (int) $row['profile_id'], 'room_id' => $room, 'service_id' => (int) $row['service_id'], 'count' => 0];
            $counts[$key]['count']++;
        }
        ksort($counts);

        return array_values($counts);
    }

    /** `$overlapOk`: moved with Book anyway over a busy person (see {@see self::insert()}). */
    public function move(int $tenantId, int $id, int $profileId, DateTimeImmutable $start, DateTimeImmutable $end, Interval $block, ?DateTimeImmutable $reminderDue, bool $overlapOk = false): void
    {
        $this->db->prepare(
            'UPDATE calendar_bookings
                SET profile_id = :profile_id, starts_at = :starts_at, ends_at = :ends_at, blocks_from = :blocks_from,
                    blocks_until = :blocks_until, reminder_due_at = :reminder_due_at, reminder_sent_at = NULL,
                    overlap_ok = ' . ($overlapOk ? 'TRUE' : 'FALSE') . ',
                    ics_sequence = ics_sequence + 1, updated_at = :now
              WHERE tenant_id = :tenant_id AND id = :id'
        )->execute([
            ':profile_id' => $profileId,
            ':starts_at' => Interval::toDb($start),
            ':ends_at' => Interval::toDb($end),
            ':blocks_from' => Interval::toDb($block->start),
            ':blocks_until' => Interval::toDb($block->end),
            ':reminder_due_at' => $reminderDue !== null ? Interval::toDb($reminderDue) : null,
            ':now' => Interval::toDb(new DateTimeImmutable()),
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);
    }

    /**
     * Move to a new status, only from one of `$from`. Returns false when the
     * booking was not in one of them (someone else got there first).
     *
     * @param list<string> $from
     * @param array<string, string|null> $extra
     */
    public function transition(int $tenantId, int $id, array $from, string $to, array $extra = []): bool
    {
        $set = ['status = :to', 'updated_at = :now'];
        $params = [':to' => $to, ':now' => Interval::toDb(new DateTimeImmutable()), ':tenant_id' => $tenantId, ':id' => $id];
        foreach (['cancelled_by', 'cancel_reason', 'payment_ref'] as $column) {
            if (array_key_exists($column, $extra)) {
                $set[] = $column . ' = :' . $column;
                $params[':' . $column] = $extra[$column];
            }
        }
        if ($to === self::STATUS_CANCELLED) {
            $set[] = 'cancelled_at = :now';
            // E2: the calendar file's cancel must outrank every file sent before it.
            $set[] = 'ics_sequence = ics_sequence + 1';
        }
        if ($to === self::STATUS_CONFIRMED) {
            $set[] = 'hold_expires_at = NULL';
        }
        $in = [];
        foreach ($from as $i => $status) {
            $in[] = ':f' . $i;
            $params[':f' . $i] = $status;
        }
        $stmt = $this->db->prepare(
            'UPDATE calendar_bookings SET ' . implode(', ', $set) . '
              WHERE tenant_id = :tenant_id AND id = :id AND status IN (' . implode(', ', $in) . ')'
        );
        $stmt->execute($params);

        return $stmt->rowCount() === 1;
    }

    /**
     * Mark the customer as arrived, or undo it. Only a confirmed booking can
     * be checked in. Returns false when it is not one.
     */
    public function checkIn(int $tenantId, int $id, ?DateTimeImmutable $at): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE calendar_bookings SET checked_in_at = :at, updated_at = :now
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'confirmed'"
        );
        $stmt->bindValue(':at', $at !== null ? Interval::toDb($at) : null, $at === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':now', Interval::toDb(new DateTimeImmutable()));
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /** Staff suggested another time for a request (set), or the customer moved it themselves (cleared). */
    public function markSuggested(int $tenantId, int $id, ?DateTimeImmutable $at): void
    {
        $stmt = $this->db->prepare('UPDATE calendar_bookings SET suggested_at = :at WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->bindValue(':at', $at !== null ? Interval::toDb($at) : null, $at === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Set the reminder due time (after a request is accepted). */
    public function setReminderDue(int $tenantId, int $id, ?DateTimeImmutable $due): void
    {
        $stmt = $this->db->prepare('UPDATE calendar_bookings SET reminder_due_at = :due, reminder_sent_at = NULL WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->bindValue(':due', $due !== null ? Interval::toDb($due) : null, $due === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * The Today panel: bookings that start in `[from, to)`, those checked in,
     * open requests from `$now` on (any day: they wait for someone), and the
     * reminders sent in `[from, to)`.
     *
     * @return array{booked: int, checked_in: int, requests: int, reminders_sent: int}
     */
    public function today(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now, ?int $unitId): array
    {
        $unit = '';
        $params = [':tenant_id' => $tenantId];
        if ($unitId !== null) {
            $unit = ' AND (EXISTS (SELECT 1 FROM calendar_rooms r WHERE r.tenant_id = b.tenant_id AND r.id = b.room_id AND r.unit_id = :unit_id)
                       OR EXISTS (SELECT 1 FROM calendar_staff cs WHERE cs.tenant_id = b.tenant_id AND cs.profile_id = b.profile_id AND cs.unit_id = :unit_id2))';
            $params[':unit_id'] = $unitId;
            $params[':unit_id2'] = $unitId;
        }
        $count = function (string $where, array $extra) use ($unit, $params): int {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM calendar_bookings b WHERE b.tenant_id = :tenant_id AND ' . $where . $unit);
            $stmt->execute($params + $extra);

            return (int) $stmt->fetchColumn();
        };
        $day = [':from' => Interval::toDb($from), ':to' => Interval::toDb($to)];

        return [
            'booked' => $count("b.status = 'confirmed' AND b.starts_at >= :from AND b.starts_at < :to", $day),
            'checked_in' => $count("b.status = 'confirmed' AND b.checked_in_at IS NOT NULL AND b.starts_at >= :from AND b.starts_at < :to", $day),
            'requests' => $count("b.status = 'requested' AND b.starts_at >= :now", [':now' => Interval::toDb($now)]),
            'reminders_sent' => $count('b.reminder_sent_at >= :from AND b.reminder_sent_at < :to', $day),
        ];
    }

    /**
     * Claim the reminder due at `$due` and point the booking at the next one
     * (WHIT-1417). Only one caller can move `reminder_due_at` off `$due`, so
     * the queued job and the sweep never both send it. False when someone
     * already did, or the booking was moved meanwhile.
     */
    public function claimReminder(int $tenantId, int $id, DateTimeImmutable $due, ?DateTimeImmutable $next, DateTimeImmutable $now): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE calendar_bookings SET reminder_sent_at = :now, reminder_due_at = :next
              WHERE tenant_id = :tenant_id AND id = :id AND reminder_due_at = :due AND status = 'confirmed'"
        );
        $stmt->execute([
            ':now' => Interval::toDb($now),
            ':next' => $next !== null ? Interval::toDb($next) : null,
            ':tenant_id' => $tenantId,
            ':id' => $id,
            ':due' => Interval::toDb($due),
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Keep what happened to one reminder on one channel (WHIT-1417, AP.14). */
    public function logReminder(int $tenantId, int $bookingId, int $hoursBefore, string $channel, string $outcome, DateTimeImmutable $now): void
    {
        $this->db->prepare(
            'INSERT INTO calendar_booking_reminders (tenant_id, booking_id, hours_before, channel, outcome, sent_at)
             VALUES (:tenant_id, :booking_id, :hours_before, :channel, :outcome, :sent_at)'
        )->execute([
            ':tenant_id' => $tenantId,
            ':booking_id' => $bookingId,
            ':hours_before' => $hoursBefore,
            ':channel' => substr($channel, 0, 40),
            ':outcome' => $outcome,
            ':sent_at' => Interval::toDb($now),
        ]);
    }

    /**
     * Every reminder sent for a booking, oldest first.
     *
     * @return list<array{hours_before: int, channel: string, outcome: string, sent_at: ?string}>
     */
    public function reminderLog(int $tenantId, int $bookingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT hours_before, channel, outcome, sent_at FROM calendar_booking_reminders
              WHERE tenant_id = :tenant_id AND booking_id = :booking_id ORDER BY sent_at, id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':booking_id' => $bookingId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'hours_before' => (int) $r['hours_before'],
                'channel' => (string) $r['channel'],
                'outcome' => (string) $r['outcome'],
                'sent_at' => is_string($r['sent_at']) ? Interval::toApi(Interval::fromDb($r['sent_at'])) : null,
            ];
        }

        return $out;
    }

    /**
     * Reminders that are due and still unsent, across every workspace, for the
     * sweep (WHIT-1417, AP.14): confirmed bookings that have not started.
     *
     * @return list<array{tenant_id: int, id: int, starts_at: string}>
     */
    public function dueReminders(DateTimeImmutable $now, int $limit): array
    {
        // @tenant-guard-ignore: the reminder sweep (WHIT-1417, AP.14) is the platform's own scheduled job across every workspace; it reads only ids and start times and queues one reminder job per booking bound to that row's tenant_id, which re-reads the booking under its tenant.
        $stmt = $this->db->prepare(
            "SELECT tenant_id, id, starts_at FROM calendar_bookings
              WHERE status = 'confirmed' AND reminder_due_at IS NOT NULL AND reminder_due_at <= :now AND starts_at > :now
              ORDER BY reminder_due_at LIMIT " . max(1, $limit)
        );
        $stmt->execute([':now' => Interval::toDb($now)]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['tenant_id' => (int) $r['tenant_id'], 'id' => (int) $r['id'], 'starts_at' => (string) $r['starts_at']];
        }

        return $out;
    }

    /** The customer pressed I'm coming (WHIT-1417); false when it was already set or the booking isn't confirmed. */
    public function confirmByCustomer(int $tenantId, int $id, DateTimeImmutable $now): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE calendar_bookings SET confirmed_by_customer_at = :now
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'confirmed' AND confirmed_by_customer_at IS NULL"
        );
        $stmt->execute([':now' => Interval::toDb($now), ':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * How many future live bookings this email holds here (spam cap).
     */
    public function openForEmail(int $tenantId, string $email, DateTimeImmutable $now): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM calendar_bookings
              WHERE tenant_id = :tenant_id AND LOWER(customer_email) = :email AND starts_at > :now
                AND status IN ('confirmed', 'pending_payment', 'requested')"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':email' => strtolower($email), ':now' => Interval::toDb($now)]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function toApi(array $r): array
    {
        $at = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? Interval::toApi(Interval::fromDb($v)) : null;
        $out = [
            'id' => (int) $r['id'],
            'service_id' => (int) $r['service_id'],
            'profile_id' => (int) $r['profile_id'],
            'customer_profile_id' => $r['customer_profile_id'] !== null ? (int) $r['customer_profile_id'] : null,
            'customer' => [
                'name' => (string) $r['customer_name'],
                'email' => (string) $r['customer_email'],
                'phone' => (string) $r['customer_phone'],
                'note' => (string) $r['customer_note'],
            ],
            'starts_at' => $at($r['starts_at']),
            'ends_at' => $at($r['ends_at']),
            'blocks_from' => $at($r['blocks_from']),
            'blocks_until' => $at($r['blocks_until']),
            'status' => (string) $r['status'],
            'hold_expires_at' => $at($r['hold_expires_at']),
            'source' => (string) $r['source'],
            'price_minor' => $r['price_minor'] !== null ? (int) $r['price_minor'] : null,
            'currency' => trim((string) $r['currency']),
            'cancelled_by' => $r['cancelled_by'] !== null ? (string) $r['cancelled_by'] : null,
            'cancel_reason' => (string) $r['cancel_reason'],
            'payment_ref' => $r['payment_ref'] !== null ? (string) $r['payment_ref'] : null,
            'created_at' => $at($r['created_at']),
            'room_id' => isset($r['room_id']) ? (int) $r['room_id'] : null,
            'checked_in_at' => $at($r['checked_in_at'] ?? null),
            'suggested_at' => $at($r['suggested_at'] ?? null),
            'reminder_sent_at' => $at($r['reminder_sent_at'] ?? null),
            // WHIT-1417: the customer pressed I'm coming.
            'confirmed_by_customer_at' => $at($r['confirmed_by_customer_at'] ?? null),
            // The calendar file's revision (E2): raised by every move and the cancel.
            'ics_sequence' => (int) ($r['ics_sequence'] ?? 0),
        ];
        // WHIT-1173: the services of a booking of several, in order; empty
        // for a booking of one service (its service_id and profile_id say it).
        $items = is_array($r['items'] ?? null) ? array_values($r['items']) : [];
        $out['items'] = array_map(static fn (array $i): array => self::itemToApi($i), $items);
        if (array_key_exists('service_name', $r)) {
            $out['service_name'] = LocalizedText::decode((string) $r['service_name'], languages: LocalizedText::BILINGUAL);
            $out['staff_name'] = (string) $r['staff_name'];
            $out['room_name'] = isset($r['room_name']) ? LocalizedText::decode((string) $r['room_name'], languages: LocalizedText::BILINGUAL) : null;
            if (count($out['items']) > 1) {
                $out['service_name'] = self::joinedNames($out['items']);
                $out['staff_name'] = self::joinedStaff($out['items']);
            }
        }

        return $out;
    }

    /**
     * One service of a booking of several, for the API.
     *
     * @param array<string, mixed> $i a row from {@see self::itemsFor()}
     * @return array{position: int, service_id: int, service_name: array<string, string>, profile_id: int, staff_name: string, room_id: ?int, starts_at: string, ends_at: string, blocks_from: string, blocks_until: string, duration_minutes: int, price_minor: ?int}
     */
    public static function itemToApi(array $i): array
    {
        $starts = Interval::fromDb((string) $i['starts_at']);
        $ends = Interval::fromDb((string) $i['ends_at']);

        return [
            'position' => (int) $i['position'],
            'service_id' => (int) $i['service_id'],
            'service_name' => LocalizedText::decode((string) ($i['service_name'] ?? ''), languages: LocalizedText::BILINGUAL),
            'profile_id' => (int) $i['profile_id'],
            'staff_name' => (string) ($i['staff_name'] ?? ''),
            'room_id' => isset($i['room_id']) ? (int) $i['room_id'] : null,
            'starts_at' => Interval::toApi($starts),
            'ends_at' => Interval::toApi($ends),
            'blocks_from' => Interval::toApi(Interval::fromDb((string) $i['blocks_from'])),
            'blocks_until' => Interval::toApi(Interval::fromDb((string) $i['blocks_until'])),
            // The length it was booked for; a later change to the service does not alter it.
            'duration_minutes' => (int) round(($ends->getTimestamp() - $starts->getTimestamp()) / 60),
            'price_minor' => $i['price_minor'] !== null ? (int) $i['price_minor'] : null,
        ];
    }

    /**
     * The services' names joined in each language ("Haircut + Colour"), so
     * everything that shows one service's name shows them all.
     *
     * @param list<array<string, mixed>> $items as {@see self::itemToApi()} gives them
     * @return array<string, string>
     */
    public static function joinedNames(array $items): array
    {
        $out = [];
        foreach (LocalizedText::BILINGUAL as $language) {
            $parts = [];
            foreach ($items as $item) {
                $name = is_array($item['service_name'] ?? null) ? $item['service_name'] : [];
                $label = $name[$language] ?? $name['en'] ?? reset($name);
                if (is_string($label) && $label !== '') {
                    $parts[] = $label;
                }
            }
            if ($parts !== []) {
                $out[$language] = implode(' + ', $parts);
            }
        }

        return $out;
    }

    /**
     * The people doing the services, each once, in order ("Amro Saleh, Awss Haddad").
     *
     * @param list<array<string, mixed>> $items as {@see self::itemToApi()} gives them
     */
    public static function joinedStaff(array $items): string
    {
        $names = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['staff_name'] ?? ''));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }
}
