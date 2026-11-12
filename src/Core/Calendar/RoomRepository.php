<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Form\LocalizedLabel;

/**
 * Rooms a service can need (design 3.3, `TplAppointments`). A room's name is
 * a `{ar?, en?}` label; `unit_id` is the branch it belongs to. Tenant first
 * on every method.
 */
final class RoomRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $tenantId, bool $activeOnly = false): array
    {
        $sql = 'SELECT id, name, unit_id, active, sort_order FROM calendar_rooms WHERE tenant_id = :tenant_id';
        if ($activeOnly) {
            $sql .= ' AND active = :active';
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY sort_order, id');
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        if ($activeOnly) {
            $stmt->bindValue(':active', true, PDO::PARAM_BOOL);
        }
        $stmt->execute();

        return array_values(array_map([self::class, 'toApi'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, unit_id, active, sort_order FROM calendar_rooms WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::toApi($row) : null;
    }

    /** @param array{name: array<string, string>, unit_id: ?int, active: bool, sort_order: int} $v */
    public function insert(int $tenantId, array $v): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_rooms (tenant_id, name, unit_id, active, sort_order)
             VALUES (:tenant_id, :name, :unit_id, :active, :sort) RETURNING id'
        );
        $this->bind($stmt, $v);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @param array{name: array<string, string>, unit_id: ?int, active: bool, sort_order: int} $v */
    public function update(int $tenantId, int $id, array $v): void
    {
        $stmt = $this->db->prepare(
            'UPDATE calendar_rooms SET name = :name, unit_id = :unit_id, active = :active, sort_order = :sort, updated_at = :now
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $this->bind($stmt, $v);
        $stmt->bindValue(':now', Interval::toDb(new DateTimeImmutable()));
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Take this room's lock for the rest of the open transaction. Always taken
     * AFTER the people's locks, so two writers never wait on each other in a
     * circle.
     */
    public function lock(int $tenantId, int $roomId): void
    {
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare('SELECT id FROM calendar_rooms WHERE tenant_id = :tenant_id AND id = :id' . $lock);
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $roomId]);
    }

    /**
     * What blocks this room in `[from, to)`: live bookings and requests that
     * hold it, with their buffers.
     *
     * @return list<Interval>
     */
    public function busy(int $tenantId, int $roomId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now, ?int $exceptBookingId = null): array
    {
        $stmt = $this->db->prepare(
            "SELECT blocks_from AS s, blocks_until AS e FROM calendar_bookings
              WHERE tenant_id = :tenant_id AND room_id = :room_id
                AND blocks_from < :to AND blocks_until > :from
                AND id <> :except
                AND (status IN ('confirmed', 'requested') OR (status = 'pending_payment' AND hold_expires_at > :now))"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':room_id' => $roomId,
            ':from' => Interval::toDb($from),
            ':to' => Interval::toDb($to),
            ':except' => $exceptBookingId ?? 0,
            ':now' => Interval::toDb($now),
        ]);
        $busy = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $busy[] = new Interval(Interval::fromDb((string) $r['s']), Interval::fromDb((string) $r['e']));
        }

        return $busy;
    }

    /** Is this an organisational unit of the workspace (a branch)? */
    public function isUnit(int $tenantId, int $unitId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM organizational_units WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $unitId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array{name: array<string, string>, unit_id: ?int, active: bool, sort_order: int} $v */
    private function bind(\PDOStatement $stmt, array $v): void
    {
        $stmt->bindValue(':name', LocalizedLabel::encode($v['name']));
        $stmt->bindValue(':unit_id', $v['unit_id'], $v['unit_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':active', $v['active'], PDO::PARAM_BOOL);
        $stmt->bindValue(':sort', $v['sort_order'], PDO::PARAM_INT);
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, name: array<string, string>, unit_id: ?int, active: bool, sort_order: int}
     */
    private static function toApi(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => LocalizedLabel::decode((string) $r['name']),
            'unit_id' => $r['unit_id'] !== null ? (int) $r['unit_id'] : null,
            'active' => DbBool::of($r['active']),
            'sort_order' => (int) $r['sort_order'],
        ];
    }
}
