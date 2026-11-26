<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Reports › Appointments (WHIT-1437, design section 39 §4.2–§4.3, AP.6–AP.9):
 * the no-show, late-cancel and missed rates for a period, broken down one way
 * at a time, with the totals of the period before for the trend.
 *
 * Worked out from the bookings each time, like the commission report (SL.5),
 * so a corrected mark changes the past at once. A booking counts once, by its
 * start in the workspace's zone, under its first service and person. What is
 * due and what is never counted is {@see AttendanceRepository} (AP.6).
 *
 * Breakdowns: `staff`, `service`, `customer`, `source`, `weekday_hour`,
 * `reminder` (said they're coming, reminded, not reminded) and `returning`
 * (a customer's first booking here, or a later one). Branch and resource
 * come with branches' zones and resources (M5, M6).
 */
final class AttendanceReport
{
    public const BREAKDOWNS = ['staff', 'service', 'customer', 'source', 'weekday_hour', 'reminder', 'returning'];

    public const PERIODS = ['this_week', 'this_month', 'last_month', 'last_3_months', 'last_6_months', 'last_12_months'];

    /** A year of bookings is plenty for one report; more is cut and said so. */
    public const MAX_BOOKINGS = 50000;

    private const KEY_SQL = "CASE WHEN b.contact_id IS NOT NULL THEN 'c:' || CAST(b.contact_id AS VARCHAR(20))
        WHEN b.customer_email <> '' THEN 'e:' || LOWER(b.customer_email) ELSE NULL END";

    public function __construct(
        private readonly PDO $db,
        private readonly CalendarRepository $calendar,
        private readonly ServiceRepository $services,
    ) {
    }

    /**
     * A named period as `[from, to, previousFrom]` in UTC, in the workspace's
     * zone: this week starts on the workspace's first day; months are calendar
     * months, the current one included in "last n months". The period before
     * is as long and ends where this one starts.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: DateTimeImmutable}
     */
    public static function period(string $name, DateTimeImmutable $now, CalendarSettings $settings): array
    {
        $local = $now->setTimezone($settings->zone)->setTime(0, 0);
        $utc = new DateTimeZone('UTC');
        $monthStart = $local->modify('first day of this month');
        switch ($name) {
            case 'this_week':
                $back = ((int) $local->format('N') - $settings->weekStart + 7) % 7;
                $from = $local->modify('-' . $back . ' days');
                $to = $from->modify('+7 days');
                $prev = $from->modify('-7 days');
                break;
            case 'last_month':
                $from = $monthStart->modify('-1 month');
                $to = $monthStart;
                $prev = $from->modify('-1 month');
                break;
            case 'last_3_months':
            case 'last_6_months':
            case 'last_12_months':
                $n = (int) preg_replace('/\D/', '', $name);
                $to = $monthStart->modify('+1 month');
                $from = $to->modify('-' . $n . ' months');
                $prev = $from->modify('-' . $n . ' months');
                break;
            default: // this_month
                $from = $monthStart;
                $to = $monthStart->modify('+1 month');
                $prev = $from->modify('-1 month');
        }

        return [$from->setTimezone($utc), $to->setTimezone($utc), $prev->setTimezone($utc)];
    }

    /**
     * The report: `totals`, `previous` (the period before, for the trend) and
     * `rows`, one per key of the breakdown, most due first (customers: most
     * missed first).
     *
     * @param array{profile_id?: ?int, service_id?: ?int, source?: ?string} $filters
     * @return array<string, mixed>
     */
    public function build(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $previousFrom, string $by, array $filters, CalendarSettings $settings, DateTimeImmutable $now): array
    {
        if (!in_array($by, self::BREAKDOWNS, true)) {
            throw new CalendarRejectedException('by is one of ' . implode(', ', self::BREAKDOWNS) . '.', 422);
        }
        $cutoff = $now->modify('-' . $settings->noShowGraceMinutes . ' minutes');
        $rows = $this->bookings($tenantId, $from, $to, $filters);
        $truncated = count($rows) >= self::MAX_BOOKINGS;
        $first = $by === 'returning' ? $this->firstBookings($tenantId, $rows) : [];

        $groups = [];
        $total = self::zero();
        foreach ($rows as $r) {
            $kind = self::kind($r, $cutoff);
            if ($kind === null) {
                continue;
            }
            $total[$kind]++;
            [$key, $label] = $this->keyOf($by, $r, $settings->zone, $first);
            $groups[$key] ??= ['key' => $key, 'label' => $label] + self::zero();
            $groups[$key][$kind]++;
        }

        $out = [];
        foreach ($groups as $g) {
            $out[] = ['key' => $g['key'], 'label' => $this->labelFor($tenantId, $by, $g['key'], $g['label'])]
                + AttendanceRepository::rates($g['came'], $g['no_show'], $g['late_cancels'], $g['early_cancels'], $g['unmarked']);
        }
        usort($out, static function (array $a, array $b) use ($by): int {
            if ($by === 'weekday_hour') {
                return strcmp((string) $a['key'], (string) $b['key']);
            }
            if ($by === 'customer') {
                return [$b['no_show'] + $b['late_cancels'], $b['due']] <=> [$a['no_show'] + $a['late_cancels'], $a['due']];
            }

            return [$b['due'], (string) $a['key']] <=> [$a['due'], (string) $b['key']];
        });

        $previous = self::zero();
        foreach ($this->bookings($tenantId, $previousFrom, $from, $filters) as $r) {
            $kind = self::kind($r, $cutoff);
            if ($kind !== null) {
                $previous[$kind]++;
            }
        }

        return [
            'from' => Interval::toApi($from),
            'to' => Interval::toApi($to),
            'previous_from' => Interval::toApi($previousFrom),
            'by' => $by,
            'totals' => AttendanceRepository::rates($total['came'], $total['no_show'], $total['late_cancels'], $total['early_cancels'], $total['unmarked']),
            'previous' => AttendanceRepository::rates($previous['came'], $previous['no_show'], $previous['late_cancels'], $previous['early_cancels'], $previous['unmarked']),
            'rows' => $out,
            'truncated' => $truncated,
        ];
    }

    /**
     * The report as CSV, one row per key, in the reader's language.
     *
     * @param array<string, mixed> $report {@see self::build()}
     */
    public static function csv(array $report, string $language): string
    {
        $ar = str_starts_with($language, 'ar');
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }
        fwrite($stream, "\xEF\xBB\xBF");
        \Whity\Http\CsvCell::writeRow($stream, $ar
            ? ['الاسم', 'المستحق', 'حضر', 'لم يحضر', 'إلغاء متأخر', 'إلغاء مبكر', 'غير مسجّل', 'نسبة عدم الحضور', 'نسبة الإلغاء المتأخر', 'نسبة الفائت']
            : ['Name', 'Due', 'Came', "Didn't come", 'Late cancels', 'Early cancels', 'Not marked', 'No-show rate', 'Late-cancel rate', 'Missed rate']);
        $rate = static fn (mixed $v): string => is_float($v) || is_int($v) ? number_format((float) $v * 100, 1, '.', '') . '%' : '';
        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        $by = (string) ($report['by'] ?? '');
        foreach ($rows as $r) {
            $label = $r['label'];
            $name = is_array($label) ? (string) ($label[$ar ? 'ar' : 'en'] ?? $label['en'] ?? reset($label) ?: '') : (string) $label;
            $name = self::csvName($by, (string) $r['key'], $name, $ar);
            \Whity\Http\CsvCell::writeRow($stream, [
                $name, (string) $r['due'], (string) $r['came'], (string) $r['no_show'], (string) $r['late_cancels'],
                (string) $r['early_cancels'], (string) $r['unmarked'],
                $rate($r['no_show_rate']), $rate($r['late_cancel_rate']), $rate($r['missed_rate']),
            ]);
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** A row's name in words for the spreadsheet, as the screen shows it. */
    private static function csvName(string $by, string $key, string $name, bool $ar): string
    {
        $words = $ar
            ? ['staff' => 'حجزه الفريق', 'public' => 'حجزه الزبون عبر الإنترنت', 'agent' => 'حجزه مساعد ووافق عليه شخص', 'walkin' => 'بدون حجز', 'confirmed' => 'قال إنه سيحضر', 'reminded' => 'ذُكِّر', 'not_reminded' => 'لم يُذكَّر', 'new' => 'أول موعد', 'returning' => 'حضر من قبل', 'none' => 'بلا اسم']
            : ['staff' => 'Booked by the team', 'public' => 'Booked online by the customer', 'agent' => 'Booked by an agent, approved by a person', 'walkin' => 'Walk-in', 'confirmed' => 'Said they\'re coming', 'reminded' => 'Reminded', 'not_reminded' => 'Not reminded', 'new' => 'First booking', 'returning' => 'Came before', 'none' => 'No name given'];
        if ($by === 'weekday_hour' && preg_match('/^([1-7])-(\d{2})$/', $key, $m) === 1) {
            $days = $ar
                ? ['الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد']
                : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

            return $days[(int) $m[1] - 1] . ' ' . $m[2] . ':00';
        }
        if (in_array($by, ['source', 'reminder', 'returning'], true) || ($by === 'customer' && $name === '')) {
            return $words[$key === '' || $by === 'customer' ? 'none' : $key] ?? $key;
        }

        return $name;
    }

    /** @return array{came: int, no_show: int, late_cancels: int, early_cancels: int, unmarked: int} */
    private static function zero(): array
    {
        return ['came' => 0, 'no_show' => 0, 'late_cancels' => 0, 'early_cancels' => 0, 'unmarked' => 0];
    }

    /**
     * What one booking counts as, or null when it never counts.
     *
     * @param array<string, mixed> $r
     */
    private static function kind(array $r, DateTimeImmutable $cutoff): ?string
    {
        if ($r['status'] === BookingRepository::STATUS_CONFIRMED) {
            return match ($r['attendance']) {
                BookingRepository::ATTENDED => 'came',
                BookingRepository::NO_SHOW => 'no_show',
                default => Interval::fromDb((string) $r['starts_at']) <= $cutoff ? 'unmarked' : null,
            };
        }
        if ($r['status'] === BookingRepository::STATUS_CANCELLED) {
            return match ($r['cancel_kind']) {
                BookingRepository::CANCEL_LATE => 'late_cancels',
                BookingRepository::CANCEL_EARLY => 'early_cancels',
                default => null,
            };
        }

        return null;
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, string> $first each customer's first start
     * @return array{0: string, 1: string}
     */
    private function keyOf(string $by, array $r, DateTimeZone $zone, array $first): array
    {
        switch ($by) {
            case 'staff':
                return [(string) $r['profile_id'], ''];
            case 'service':
                return [(string) $r['service_id'], ''];
            case 'customer':
                $key = is_string($r['customer_key']) ? $r['customer_key'] : 'none';

                return [$key, (string) $r['customer_name']];
            case 'source':
                return [(string) $r['source'], ''];
            case 'weekday_hour':
                $local = Interval::fromDb((string) $r['starts_at'])->setTimezone($zone);

                return [$local->format('N') . '-' . $local->format('H'), ''];
            case 'reminder':
                return [$r['confirmed_by_customer_at'] !== null ? 'confirmed' : ($r['reminder_sent_at'] !== null ? 'reminded' : 'not_reminded'), ''];
            default: // returning
                $key = is_string($r['customer_key']) ? $r['customer_key'] : null;

                return [$key !== null && ($first[$key] ?? '') === (string) $r['starts_at'] ? 'new' : 'returning', ''];
        }
    }

    /** @return string|array<string, string> */
    private function labelFor(int $tenantId, string $by, string $key, string $label): string|array
    {
        if ($by === 'staff') {
            return $this->calendar->displayName((int) $key);
        }
        if ($by === 'service') {
            $name = $this->services->find($tenantId, (int) $key)['name'] ?? null;

            return is_array($name) ? array_map('strval', $name) : $key;
        }

        return $by === 'customer' ? $label : $key;
    }

    /**
     * The bookings starting in `[from, to)`, with what the report needs.
     *
     * @param array{profile_id?: ?int, service_id?: ?int, source?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    private function bookings(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to, array $filters): array
    {
        $where = [];
        $params = [':tenant_id' => $tenantId, ':from' => Interval::toDb($from), ':to' => Interval::toDb($to)];
        if (($filters['profile_id'] ?? null) !== null) {
            $where[] = 'AND (b.profile_id = :profile_id OR EXISTS (SELECT 1 FROM calendar_booking_services p
                WHERE p.tenant_id = b.tenant_id AND p.booking_id = b.id AND p.profile_id = :profile_id))';
            $params[':profile_id'] = $filters['profile_id'];
        }
        if (($filters['service_id'] ?? null) !== null) {
            $where[] = 'AND (b.service_id = :service_id OR EXISTS (SELECT 1 FROM calendar_booking_services s
                WHERE s.tenant_id = b.tenant_id AND s.booking_id = b.id AND s.service_id = :service_id))';
            $params[':service_id'] = $filters['service_id'];
        }
        if (($filters['source'] ?? null) !== null) {
            $where[] = 'AND b.source = :source';
            $params[':source'] = $filters['source'];
        }
        $stmt = $this->db->prepare(
            'SELECT b.id, b.profile_id, b.service_id, b.source, b.starts_at, b.status, b.attendance, b.cancel_kind,
                    b.reminder_sent_at, b.confirmed_by_customer_at, b.customer_name, ' . self::KEY_SQL . ' AS customer_key
               FROM calendar_bookings b
              WHERE b.tenant_id = :tenant_id AND b.starts_at >= :from AND b.starts_at < :to ' . implode(' ', $where) . '
              ORDER BY b.starts_at, b.id
              LIMIT ' . self::MAX_BOOKINGS
        );
        $stmt->execute($params);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Each customer's first booking ever here (confirmed or cancelled), as
     * its stored start, for "new or returning".
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function firstBookings(int $tenantId, array $rows): array
    {
        $keys = array_values(array_unique(array_filter(array_column($rows, 'customer_key'), 'is_string')));
        $out = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $in = [];
            $params = [':tenant_id' => $tenantId];
            foreach ($chunk as $i => $key) {
                $in[] = ':k' . $i;
                $params[':k' . $i] = $key;
            }
            $stmt = $this->db->prepare(
                'SELECT ' . self::KEY_SQL . " AS customer_key, MIN(b.starts_at) AS first_at
                   FROM calendar_bookings b
                  WHERE b.tenant_id = :tenant_id AND b.status IN ('confirmed', 'cancelled')
                    AND " . self::KEY_SQL . ' IN (' . implode(', ', $in) . ')
                  GROUP BY ' . self::KEY_SQL
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string) $r['customer_key']] = (string) $r['first_at'];
            }
        }

        return $out;
    }
}
