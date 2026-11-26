<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;

/**
 * Did they come: the counts and rates (WHIT-1427, design section 39 §4,
 * AP.6, AP.10). Worked out from the bookings each time, the way the
 * commission report is; only the marks are stored, so a corrected mark
 * changes the past at once.
 *
 * A booking counts once, by its start. "Due" is came + didn't come + late
 * cancels. Never counted: requests never approved, holds that expired unpaid,
 * "we cancelled" and early cancels. A confirmed booking past its start plus
 * the grace minutes with no mark is "not marked" and left out of every rate.
 *
 * A customer is their contact (`c:<id>`) or, for a booking without one, their
 * email (`e:<lower-case email>`); a booking with neither has no customer here.
 */
final class AttendanceRepository
{
    private const KEY_SQL = "CASE WHEN b.contact_id IS NOT NULL THEN 'c:' || CAST(b.contact_id AS VARCHAR(20))
        WHEN b.customer_email <> '' THEN 'e:' || LOWER(b.customer_email) ELSE NULL END";

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The customer a booking belongs to, as a key, or null.
     *
     * @param array<string, mixed> $booking {@see BookingRepository::toApi()}
     */
    public static function customerKey(array $booking): ?string
    {
        if (is_int($booking['contact_id'] ?? null)) {
            return 'c:' . $booking['contact_id'];
        }
        $email = strtolower(trim((string) ($booking['customer']['email'] ?? $booking['customer_email'] ?? '')));

        return $email !== '' ? 'e:' . $email : null;
    }

    /** A key as the API takes it: a contact id or an email. */
    public static function keyFor(?int $contactId, ?string $email): ?string
    {
        if ($contactId !== null && $contactId > 0) {
            return 'c:' . $contactId;
        }
        $email = strtolower(trim((string) $email));

        return $email !== '' ? 'e:' . $email : null;
    }

    /**
     * Came, didn't come, late and early cancels, and not marked, for bookings
     * starting in `[from, to)`.
     *
     * @param array{profile_id?: ?int, service_id?: ?int, customer?: ?string, source?: ?string} $filters
     * @return array{came: int, no_show: int, late_cancels: int, early_cancels: int, unmarked: int, due: int, no_show_rate: ?float, late_cancel_rate: ?float, missed_rate: ?float, unmarked_share: ?float}
     */
    public function counts(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $unmarkedBefore, array $filters = []): array
    {
        $where = ['b.starts_at >= :from', 'b.starts_at < :to'];
        $params = [
            ':tenant_id' => $tenantId,
            ':from' => Interval::toDb($from),
            ':to' => Interval::toDb($to),
            ':cutoff' => Interval::toDb($unmarkedBefore),
        ];
        if (($filters['profile_id'] ?? null) !== null) {
            // The booking's person, or anyone doing one of its services.
            $where[] = '(b.profile_id = :profile_id OR EXISTS (SELECT 1 FROM calendar_booking_services p
                WHERE p.tenant_id = b.tenant_id AND p.booking_id = b.id AND p.profile_id = :profile_id))';
            $params[':profile_id'] = $filters['profile_id'];
        }
        if (($filters['service_id'] ?? null) !== null) {
            $where[] = '(b.service_id = :service_id OR EXISTS (SELECT 1 FROM calendar_booking_services s
                WHERE s.tenant_id = b.tenant_id AND s.booking_id = b.id AND s.service_id = :service_id))';
            $params[':service_id'] = $filters['service_id'];
        }
        if (($filters['customer'] ?? null) !== null) {
            $where[] = self::KEY_SQL . ' = :customer';
            $params[':customer'] = $filters['customer'];
        }
        if (($filters['source'] ?? null) !== null) {
            $where[] = 'b.source = :source';
            $params[':source'] = $filters['source'];
        }
        $stmt = $this->db->prepare(
            "SELECT
                SUM(CASE WHEN b.status = 'confirmed' AND b.attendance = 'attended' THEN 1 ELSE 0 END) AS came,
                SUM(CASE WHEN b.status = 'confirmed' AND b.attendance = 'no_show' THEN 1 ELSE 0 END) AS no_show,
                SUM(CASE WHEN b.status = 'cancelled' AND b.cancel_kind = 'late' THEN 1 ELSE 0 END) AS late_cancels,
                SUM(CASE WHEN b.status = 'cancelled' AND b.cancel_kind = 'early' THEN 1 ELSE 0 END) AS early_cancels,
                SUM(CASE WHEN b.status = 'confirmed' AND b.attendance IS NULL AND b.starts_at <= :cutoff THEN 1 ELSE 0 END) AS unmarked
               FROM calendar_bookings b
              WHERE b.tenant_id = :tenant_id AND " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $n = static fn (string $k): int => is_array($row) ? (int) ($row[$k] ?? 0) : 0;

        return self::rates($n('came'), $n('no_show'), $n('late_cancels'), $n('early_cancels'), $n('unmarked'));
    }

    /**
     * @return array{came: int, no_show: int, late_cancels: int, early_cancels: int, unmarked: int, due: int, no_show_rate: ?float, late_cancel_rate: ?float, missed_rate: ?float, unmarked_share: ?float}
     */
    public static function rates(int $came, int $noShow, int $late, int $early, int $unmarked): array
    {
        $due = $came + $noShow + $late;
        $rate = static fn (int $part): ?float => $due > 0 ? round($part / $due, 4) : null;

        return [
            'came' => $came,
            'no_show' => $noShow,
            'late_cancels' => $late,
            'early_cancels' => $early,
            'unmarked' => $unmarked,
            'due' => $due,
            'no_show_rate' => $rate($noShow),
            'late_cancel_rate' => $rate($late),
            'missed_rate' => $rate($noShow + $late),
            // AP.6: above 10%, a rate says "Not all bookings are marked".
            'unmarked_share' => $due + $unmarked > 0 ? round($unmarked / ($due + $unmarked), 4) : null,
        ];
    }

    /**
     * What happened to each customer's due bookings since `$from` (the window,
     * or their last "Start again"), oldest first, for the flag: one list of
     * `true` (missed) and `false` (came) per customer key asked for.
     *
     * @param list<string> $keys
     * @return array<string, list<bool>>
     */
    public function history(int $tenantId, array $keys, DateTimeImmutable $from, DateTimeImmutable $now, bool $lateCancelsCount): array
    {
        $keys = array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
        if ($keys === []) {
            return [];
        }
        $since = $this->resets($tenantId, $keys);
        $in = [];
        $params = [':tenant_id' => $tenantId, ':from' => Interval::toDb($from), ':now' => Interval::toDb($now)];
        foreach ($keys as $i => $key) {
            $in[] = ':k' . $i;
            $params[':k' . $i] = $key;
        }
        $stmt = $this->db->prepare(
            'SELECT ' . self::KEY_SQL . " AS customer_key, b.starts_at, b.status, b.attendance, b.cancel_kind
               FROM calendar_bookings b
              WHERE b.tenant_id = :tenant_id AND b.starts_at >= :from AND b.starts_at <= :now
                AND ((b.status = 'confirmed' AND b.attendance IS NOT NULL) OR (b.status = 'cancelled' AND b.cancel_kind = 'late'))
                AND " . self::KEY_SQL . ' IN (' . implode(', ', $in) . ')
              ORDER BY b.starts_at, b.id'
        );
        $stmt->execute($params);
        $out = array_fill_keys($keys, []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (string) $r['customer_key'];
            if (isset($since[$key]) && (string) $r['starts_at'] < $since[$key]) {
                continue;
            }
            if ($r['status'] === 'cancelled') {
                if ($lateCancelsCount) {
                    $out[$key][] = true;
                }
                continue;
            }
            $out[$key][] = $r['attendance'] === BookingRepository::NO_SHOW;
        }

        return $out;
    }

    /**
     * One customer's counts over all time, and when they first booked, for
     * the line on their record (AP.8): "Came 12 · Didn't come 2 · Late
     * cancels 1 · since March 2026".
     *
     * @return array{came: int, no_show: int, late_cancels: int, first_at: ?string}
     */
    public function customerTotals(int $tenantId, string $key, DateTimeImmutable $now): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                SUM(CASE WHEN b.status = 'confirmed' AND b.attendance = 'attended' THEN 1 ELSE 0 END) AS came,
                SUM(CASE WHEN b.status = 'confirmed' AND b.attendance = 'no_show' THEN 1 ELSE 0 END) AS no_show,
                SUM(CASE WHEN b.status = 'cancelled' AND b.cancel_kind = 'late' THEN 1 ELSE 0 END) AS late_cancels,
                MIN(CASE WHEN b.status IN ('confirmed', 'cancelled') THEN b.starts_at END) AS first_at
               FROM calendar_bookings b
              WHERE b.tenant_id = :tenant_id AND b.starts_at <= :now AND " . self::KEY_SQL . ' = :customer'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':now' => Interval::toDb($now), ':customer' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'came' => is_array($row) ? (int) ($row['came'] ?? 0) : 0,
            'no_show' => is_array($row) ? (int) ($row['no_show'] ?? 0) : 0,
            'late_cancels' => is_array($row) ? (int) ($row['late_cancels'] ?? 0) : 0,
            'first_at' => is_array($row) && is_string($row['first_at'] ?? null) ? Interval::toApi(Interval::fromDb($row['first_at'])) : null,
        ];
    }

    /** "Start again from today" for a customer's count (AP.10). */
    public function reset(int $tenantId, string $key, DateTimeImmutable $now, int $by): void
    {
        $this->db->prepare(
            'INSERT INTO calendar_no_show_resets (tenant_id, customer_key, cleared_at, cleared_by_profile_id)
             VALUES (:tenant_id, :customer_key, :cleared_at, :by)'
        )->execute([':tenant_id' => $tenantId, ':customer_key' => substr($key, 0, 320), ':cleared_at' => Interval::toDb($now), ':by' => $by > 0 ? $by : null]);
    }

    /**
     * When each customer's count last started again, as stored timestamps.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    public function resets(int $tenantId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $in = [];
        $params = [':tenant_id' => $tenantId];
        foreach ($keys as $i => $key) {
            $in[] = ':k' . $i;
            $params[':k' . $i] = $key;
        }
        $stmt = $this->db->prepare(
            'SELECT customer_key, MAX(cleared_at) AS cleared_at FROM calendar_no_show_resets
              WHERE tenant_id = :tenant_id AND customer_key IN (' . implode(', ', $in) . ') GROUP BY customer_key'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['customer_key']] = substr((string) $r['cleared_at'], 0, 19);
        }

        return $out;
    }

    /**
     * Confirmed bookings nobody marked that started in `[from, before)`, for
     * end-of-day marking (AP.4). Ids only.
     *
     * @return list<int>
     */
    public function unmarkedIds(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $before): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM calendar_bookings
              WHERE tenant_id = :tenant_id AND status = 'confirmed' AND attendance IS NULL
                AND starts_at >= :from AND starts_at < :before
              ORDER BY starts_at, id"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':from' => Interval::toDb($from), ':before' => Interval::toDb($before)]);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * The workspaces with end-of-day marking on, and since when (the setting's
     * last change), so turning it on never marks the months before.
     *
     * @return array<int, string> tenant id => when it was turned on
     */
    public function autoNoShowWorkspaces(): array
    {
        // @tenant-guard-ignore: end-of-day marking (WHIT-1427, AP.4) is the platform's own scheduled job across every workspace; it reads only which workspaces turned the switch on, then works in each one by its tenant_id.
        $stmt = $this->db->query(
            "SELECT tenant_id, updated_at FROM tenant_settings WHERE setting_key = 'calendar.auto_no_show' AND value = 'true'"
        );
        $out = [];
        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $r) {
            $out[(int) $r['tenant_id']] = substr((string) $r['updated_at'], 0, 19);
        }

        return $out;
    }
}
