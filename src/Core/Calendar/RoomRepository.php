<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\i18n\LocalizedText;

/**
 * Rooms and equipment (design 3.3; WHIT-1439, design section 39 §6, AP.18):
 * `calendar_resources`, the table that was `calendar_rooms` (migration 365). A resource's
 * name is a `{ar?, en?}` label; `kind` is room, chair, equipment or other;
 * `group_key` gathers several of a thing ("Chairs"); `unit_id` is its branch.
 *
 * What a service needs is `calendar_service_resources`: a specific resource,
 * or any one from a group, in order. What a booking holds is
 * `calendar_booking_resources`. Tenant first on every method.
 */
final class RoomRepository
{
    private const COLUMNS = 'id, name, unit_id, active, sort_order, kind, group_key';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $tenantId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM calendar_resources WHERE tenant_id = :tenant_id';
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
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM calendar_resources WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::toApi($row) : null;
    }

    /** @param array{name: array<string, string>, unit_id: ?int, active: bool, sort_order: int, kind?: string, group_key?: string} $v */
    public function insert(int $tenantId, array $v): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_resources (tenant_id, name, unit_id, active, sort_order, kind, group_key)
             VALUES (:tenant_id, :name, :unit_id, :active, :sort, :kind, :group_key) RETURNING id'
        );
        $this->bind($stmt, $v);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @param array{name: array<string, string>, unit_id: ?int, active: bool, sort_order: int, kind?: string, group_key?: string} $v */
    public function update(int $tenantId, int $id, array $v): void
    {
        $stmt = $this->db->prepare(
            'UPDATE calendar_resources SET name = :name, unit_id = :unit_id, active = :active, sort_order = :sort,
                    kind = :kind, group_key = :group_key, updated_at = :now
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $this->bind($stmt, $v);
        $stmt->bindValue(':now', Interval::toDb(new DateTimeImmutable()));
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Take this resource's lock for the rest of the open transaction. Always
     * taken AFTER the people's locks, in resource-id order, so two writers
     * never wait on each other in a circle.
     */
    public function lock(int $tenantId, int $roomId): void
    {
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare('SELECT id FROM calendar_resources WHERE tenant_id = :tenant_id AND id = :id' . $lock);
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $roomId]);
    }

    /**
     * What a service needs, in order (AP.18): for each need, the resources
     * that can meet it, in the order to try them. A specific resource is a
     * list of one; "any one from a group" is the group's active resources by
     * their order. A service with no needs listed but a `room_id` (written
     * before migration 365, or by a writer that knows only rooms) needs that
     * room. An inactive specific resource still counts, so a service never
     * silently stops needing it.
     *
     * @return list<list<int>>
     */
    public function needsFor(int $tenantId, int $serviceId, ?int $legacyRoomId, ?int $branchId = null): array
    {
        $rows = $this->requirements($tenantId, $serviceId);
        if ($rows === []) {
            return $legacyRoomId !== null ? [[$legacyRoomId]] : [];
        }
        $needs = [];
        foreach ($rows as $row) {
            if ($row['resource_id'] !== null) {
                $needs[] = [$row['resource_id']];
                continue;
            }
            // WHIT-1440 (AP.16): any one from a group means one in the
            // person's branch, or one that belongs to no branch.
            $stmt = $this->db->prepare(
                'SELECT id FROM calendar_resources
                  WHERE tenant_id = :tenant_id AND group_key = :group_key AND active = :active'
                . ($branchId !== null ? ' AND (unit_id IS NULL OR unit_id = :unit_id)' : '')
                . ' ORDER BY sort_order, id'
            );
            if ($branchId !== null) {
                $stmt->bindValue(':unit_id', $branchId, PDO::PARAM_INT);
            }
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':group_key', (string) $row['group_key']);
            $stmt->bindValue(':active', true, PDO::PARAM_BOOL);
            $stmt->execute();
            $needs[] = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        return $needs;
    }

    /**
     * A service's needs as written: each a specific `resource_id` or a
     * `group_key`.
     *
     * @return list<array{resource_id: ?int, group_key: ?string}>
     */
    public function requirements(int $tenantId, int $serviceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT resource_id, group_key FROM calendar_service_resources
              WHERE tenant_id = :tenant_id AND service_id = :service_id ORDER BY sort_order, id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'resource_id' => $r['resource_id'] !== null ? (int) $r['resource_id'] : null,
                'group_key' => $r['group_key'] !== null ? (string) $r['group_key'] : null,
            ];
        }

        return $out;
    }

    /**
     * Replace a service's needs. Its `room_id` becomes the first specific
     * resource, or none, for everything that still reads it.
     *
     * @param list<array{resource_id: ?int, group_key: ?string}> $needs
     */
    public function replaceRequirements(int $tenantId, int $serviceId, array $needs): void
    {
        $this->db->prepare('DELETE FROM calendar_service_resources WHERE tenant_id = :tenant_id AND service_id = :service_id')
            ->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId]);
        $insert = $this->db->prepare(
            'INSERT INTO calendar_service_resources (tenant_id, service_id, resource_id, group_key, sort_order)
             VALUES (:tenant_id, :service_id, :resource_id, :group_key, :sort)'
        );
        $first = null;
        foreach ($needs as $i => $need) {
            $insert->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $insert->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
            $insert->bindValue(':resource_id', $need['resource_id'], $need['resource_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insert->bindValue(':group_key', $need['group_key'], $need['group_key'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':sort', $i, PDO::PARAM_INT);
            $insert->execute();
            $first ??= $need['resource_id'];
        }
        $this->db->prepare('UPDATE calendar_services SET room_id = :room_id WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':room_id' => $first, ':tenant_id' => $tenantId, ':id' => $serviceId]);
    }

    /**
     * The groups in use, for choosing "any one from a group".
     *
     * @return list<string>
     */
    public function groups(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT group_key FROM calendar_resources WHERE tenant_id = :tenant_id AND group_key <> '' ORDER BY group_key"
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * What blocks this resource in `[from, to)`: live bookings and requests
     * that hold it, with their buffers, and busy events held in it.
     *
     * @return list<Interval>
     */
    public function busy(int $tenantId, int $roomId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now, ?int $exceptBookingId = null): array
    {
        $live = "(b.status IN ('confirmed', 'requested') OR (b.status = 'pending_payment' AND b.hold_expires_at > :now))";
        $params = [
            ':tenant_id' => $tenantId,
            ':room_id' => $roomId,
            ':from' => Interval::toDb($from),
            ':to' => Interval::toDb($to),
            ':except' => $exceptBookingId ?? 0,
            ':now' => Interval::toDb($now),
        ];
        $busy = [];
        // A booking of several services (WHIT-1173) holds a room only for the
        // services that need it, read from its services; since migration 366
        // a booking also lists every resource it took.
        foreach ([
            "SELECT b.blocks_from AS s, b.blocks_until AS e FROM calendar_bookings b
              WHERE b.tenant_id = :tenant_id AND b.room_id = :room_id
                AND b.blocks_from < :to AND b.blocks_until > :from AND b.id <> :except AND {$live}
                AND NOT EXISTS (SELECT 1 FROM calendar_booking_services i WHERE i.tenant_id = b.tenant_id AND i.booking_id = b.id)",
            "SELECT i.blocks_from AS s, i.blocks_until AS e FROM calendar_booking_services i
               JOIN calendar_bookings b ON b.id = i.booking_id AND b.tenant_id = i.tenant_id
              WHERE i.tenant_id = :tenant_id AND i.room_id = :room_id
                AND i.blocks_from < :to AND i.blocks_until > :from AND b.id <> :except AND {$live}",
            "SELECT r.blocks_from AS s, r.blocks_until AS e FROM calendar_booking_resources r
               JOIN calendar_bookings b ON b.id = r.booking_id AND b.tenant_id = r.tenant_id
              WHERE r.tenant_id = :tenant_id AND r.resource_id = :room_id
                AND r.blocks_from < :to AND r.blocks_until > :from AND b.id <> :except AND {$live}",
        ] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $busy[] = new Interval(Interval::fromDb((string) $r['s']), Interval::fromDb((string) $r['e']));
            }
        }

        // ADR 0018: an event (a meeting, say) held in the room blocks it too.
        $events = $this->db->prepare(
            "SELECT starts_at AS s, ends_at AS e FROM calendar_events
              WHERE tenant_id = :tenant_id AND room_id = :room_id
                AND starts_at < :to AND ends_at > :from
                AND busy = :busy AND status = 'confirmed'"
        );
        $events->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $events->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $events->bindValue(':from', Interval::toDb($from));
        $events->bindValue(':to', Interval::toDb($to));
        $events->bindValue(':busy', true, PDO::PARAM_BOOL);
        $events->execute();
        foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $r) {
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

    /** @param array{name: array<string, string>, unit_id: ?int, active: bool, sort_order: int, kind?: string, group_key?: string} $v */
    private function bind(\PDOStatement $stmt, array $v): void
    {
        $stmt->bindValue(':name', LocalizedText::encode($v['name']));
        $stmt->bindValue(':unit_id', $v['unit_id'], $v['unit_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':active', $v['active'], PDO::PARAM_BOOL);
        $stmt->bindValue(':sort', $v['sort_order'], PDO::PARAM_INT);
        $stmt->bindValue(':kind', $v['kind'] ?? 'room');
        $stmt->bindValue(':group_key', trim($v['group_key'] ?? ''));
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, name: array<string, string>, unit_id: ?int, active: bool, sort_order: int, kind: string, group_key: string}
     */
    private static function toApi(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => LocalizedText::decode((string) $r['name'], languages: LocalizedText::BILINGUAL),
            'unit_id' => $r['unit_id'] !== null ? (int) $r['unit_id'] : null,
            'active' => DbBool::of($r['active']),
            'sort_order' => (int) $r['sort_order'],
            'kind' => (string) ($r['kind'] ?? 'room'),
            'group_key' => (string) ($r['group_key'] ?? ''),
        ];
    }
}
