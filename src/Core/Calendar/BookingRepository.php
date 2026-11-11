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

    public const SOURCE_STAFF = 'staff';
    public const SOURCE_PUBLIC = 'public';
    public const SOURCE_AGENT = 'agent';

    private const COLUMNS = 'b.id, b.service_id, b.profile_id, b.customer_profile_id, b.customer_name, b.customer_email,
        b.customer_phone, b.customer_note, b.starts_at, b.ends_at, b.blocks_from, b.blocks_until, b.status,
        b.hold_expires_at, b.source, b.price_minor, b.currency, b.payment_ref, b.reminder_due_at, b.reminder_sent_at,
        b.created_by_profile_id, b.cancelled_by, b.cancel_reason, b.cancelled_at, b.created_at, b.tenant_id';

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
                    created_at, updated_at)
             VALUES (:tenant_id, :service_id, :profile_id, :customer_profile_id, :customer_name, :customer_email,
                    :customer_phone, :customer_note, :starts_at, :ends_at, :blocks_from, :blocks_until, :status,
                    :hold_expires_at, :source, :price_minor, :currency, :reminder_due_at, :created_by, :now, :now)
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
     * @param list<string> $statuses empty = live ones (confirmed, pending payment)
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $profileIds, DateTimeImmutable $from, DateTimeImmutable $to, array $statuses = []): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ', s.name AS service_name, p.display_name AS staff_name
                  FROM calendar_bookings b
                  JOIN calendar_services s ON s.id = b.service_id AND s.tenant_id = b.tenant_id
                  JOIN profiles p ON p.id = b.profile_id
                 WHERE b.tenant_id = :tenant_id AND b.starts_at < :to AND b.ends_at > :from';
        $params = [':tenant_id' => $tenantId, ':from' => Interval::toDb($from), ':to' => Interval::toDb($to)];
        if ($profileIds !== []) {
            $in = [];
            foreach ($profileIds as $i => $id) {
                $in[] = ':p' . $i;
                $params[':p' . $i] = $id;
            }
            $sql .= ' AND b.profile_id IN (' . implode(', ', $in) . ')';
        }
        $statuses = $statuses !== [] ? $statuses : [self::STATUS_CONFIRMED, self::STATUS_PENDING_PAYMENT];
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
     * How many future live bookings this email holds here (spam cap).
     */
    public function openForEmail(int $tenantId, string $email, DateTimeImmutable $now): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM calendar_bookings
              WHERE tenant_id = :tenant_id AND LOWER(customer_email) = :email AND starts_at > :now
                AND status IN ('confirmed', 'pending_payment')"
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
            'created_at' => $at($r['created_at']),
        ];
        if (array_key_exists('service_name', $r)) {
            $out['service_name'] = \Whity\Core\Form\LocalizedLabel::decode((string) $r['service_name']);
            $out['staff_name'] = (string) $r['staff_name'];
        }

        return $out;
    }
}
