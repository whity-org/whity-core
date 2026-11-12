<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

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

    private const COLUMNS = 'b.id, b.service_id, b.profile_id, b.customer_profile_id, b.customer_name, b.customer_email,
        b.customer_phone, b.customer_note, b.starts_at, b.ends_at, b.blocks_from, b.blocks_until, b.status,
        b.hold_expires_at, b.source, b.price_minor, b.currency, b.payment_ref, b.reminder_due_at, b.reminder_sent_at,
        b.created_by_profile_id, b.cancelled_by, b.cancel_reason, b.cancelled_at, b.created_at, b.tenant_id,
        b.room_id, b.checked_in_at, b.suggested_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string, mixed> $v */
    public function insert(int $tenantId, array $v): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_bookings (tenant_id, service_id, profile_id, customer_profile_id, customer_name,
                    customer_email, customer_phone, customer_note, starts_at, ends_at, blocks_from, blocks_until,
                    status, hold_expires_at, source, price_minor, currency, reminder_due_at, created_by_profile_id,
                    room_id, created_at, updated_at)
             VALUES (:tenant_id, :service_id, :profile_id, :customer_profile_id, :customer_name, :customer_email,
                    :customer_phone, :customer_note, :starts_at, :ends_at, :blocks_from, :blocks_until, :status,
                    :hold_expires_at, :source, :price_minor, :currency, :reminder_due_at, :created_by, :room_id, :now, :now)
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

        return is_array($row) ? $row : null;
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
            $sql .= ' AND b.profile_id IN (' . implode(', ', $in) . ')';
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

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function move(int $tenantId, int $id, int $profileId, DateTimeImmutable $start, DateTimeImmutable $end, Interval $block, ?DateTimeImmutable $reminderDue): void
    {
        $this->db->prepare(
            'UPDATE calendar_bookings
                SET profile_id = :profile_id, starts_at = :starts_at, ends_at = :ends_at, blocks_from = :blocks_from,
                    blocks_until = :blocks_until, reminder_due_at = :reminder_due_at, reminder_sent_at = NULL,
                    updated_at = :now
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

    /** Claim the reminder; false when another worker already did. */
    public function markReminded(int $tenantId, int $id, DateTimeImmutable $now): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE calendar_bookings SET reminder_sent_at = :now
              WHERE tenant_id = :tenant_id AND id = :id AND reminder_sent_at IS NULL'
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
        ];
        if (array_key_exists('service_name', $r)) {
            $out['service_name'] = \Whity\Core\Form\LocalizedLabel::decode((string) $r['service_name']);
            $out['staff_name'] = (string) $r['staff_name'];
            $out['room_name'] = isset($r['room_name']) ? \Whity\Core\Form\LocalizedLabel::decode((string) $r['room_name']) : null;
        }

        return $out;
    }
}
