<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * The database's guard against double bookings (migration 360, design section
 * 39 §5.1, AP.11–AP.13), as the calendar sees it.
 *
 * `calendar_holds` is written by triggers on PostgreSQL; nothing in PHP
 * writes it. This class only reads it and turns the constraint's refusal
 * into the calendar's usual 409.
 */
final class HoldConflicts
{
    public const CONSTRAINT = 'calendar_holds_no_overlap';

    /** PostgreSQL's SQLSTATE for an exclusion-constraint violation. */
    public const SQLSTATE = '23P01';

    /**
     * The 409 for a refusal by the constraint, or null when `$e` is anything
     * else. `extra.held` marks it as the database's, so a caller trying
     * several people can try the next one.
     */
    public static function fromPdo(PDOException $e): ?CalendarRejectedException
    {
        $state = is_array($e->errorInfo) && isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : (string) $e->getCode();
        if ($state !== self::SQLSTATE || !str_contains($e->getMessage(), self::CONSTRAINT)) {
            return null;
        }
        $reason = preg_match('/\)=\(\d+, resource,/', $e->getMessage()) === 1
            ? CalendarRejectedException::ROOM_TAKEN
            : CalendarRejectedException::PERSON_BUSY;
        $notFree = CalendarRejectedException::notFree($reason);

        return new CalendarRejectedException($notFree->clientMessage, 409, $notFree->extra + ['held' => true]);
    }

    /** Whether a 409 came from the database's check rather than the service's own. */
    public static function isHeld(CalendarRejectedException $e): bool
    {
        return $e->status === 409 && ($e->extra['held'] ?? false) === true;
    }

    /**
     * Payment holds on these people and rooms whose time has run out but
     * which are still `pending_payment`, by booking id.
     *
     * @param list<int> $people
     * @param list<int> $resources
     * @return list<int>
     */
    public static function lapsed(PDO $db, int $tenantId, array $people, array $resources, DateTimeImmutable $now): array
    {
        $who = [];
        $params = [':tenant_id' => $tenantId, ':now' => Interval::toDb($now)];
        foreach (['person' => $people, 'resource' => $resources] as $kind => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if ($ids === []) {
                continue;
            }
            $in = [];
            foreach ($ids as $i => $id) {
                $in[] = ':' . $kind . $i;
                $params[':' . $kind . $i] = $id;
            }
            $who[] = "(h.holder_kind = '" . $kind . "' AND h.holder_id IN (" . implode(', ', $in) . '))';
        }
        if ($who === []) {
            return [];
        }
        $stmt = $db->prepare(
            "SELECT DISTINCT h.booking_id
               FROM calendar_holds h
               JOIN calendar_bookings b ON b.tenant_id = h.tenant_id AND b.id = h.booking_id
              WHERE h.tenant_id = :tenant_id
                AND h.expires_at IS NOT NULL AND h.expires_at <= :now
                AND b.status = 'pending_payment'
                AND (" . implode(' OR ', $who) . ')
              ORDER BY h.booking_id'
        );
        $stmt->execute($params);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Live bookings that overlap another on the same person or room, for
     * `whity-cli calendar:overlaps` (AP.12): those that came in before the
     * guard, and Book anyway ones. Every workspace, or one. PostgreSQL only.
     *
     * @return list<array{tenant_id: int, holder_kind: string, holder_id: int, booking_id: int, other_booking_id: int, holds_from: string, holds_until: string, before_guard: bool}>
     */
    public static function overlaps(PDO $db, ?int $tenantId, DateTimeImmutable $from): array
    {
        $params = [':from' => Interval::toDb($from)];
        $scope = '';
        if ($tenantId !== null) {
            $scope = ' AND a.tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }
        // Every workspace is the operator's view (the CLI); a tenant id narrows it.
        $stmt = $db->prepare(
            'SELECT a.tenant_id, a.holder_kind, a.holder_id, a.booking_id, b.booking_id AS other_booking_id,
                    a.holds_from, a.holds_until, ba.overlap_before_guard AS before_guard
               FROM calendar_holds a
               JOIN calendar_holds b
                 ON b.tenant_id = a.tenant_id AND b.holder_kind = a.holder_kind AND b.holder_id = a.holder_id
                AND b.booking_id < a.booking_id
                AND b.holds_from < a.holds_until AND a.holds_from < b.holds_until
               JOIN calendar_bookings ba ON ba.tenant_id = a.tenant_id AND ba.id = a.booking_id
              WHERE a.holds_until > :from' . $scope . '
              ORDER BY a.tenant_id, a.holds_from, a.booking_id'
        );
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'tenant_id' => (int) $row['tenant_id'],
                'holder_kind' => (string) $row['holder_kind'],
                'holder_id' => (int) $row['holder_id'],
                'booking_id' => (int) $row['booking_id'],
                'other_booking_id' => (int) $row['other_booking_id'],
                'holds_from' => (string) $row['holds_from'],
                'holds_until' => (string) $row['holds_until'],
                'before_guard' => \Whity\Core\Db\DbBool::of($row['before_guard']),
            ];
        }

        return $rows;
    }
}
