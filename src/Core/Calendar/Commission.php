<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\Money\Currency;

/**
 * Staff commission (WHIT-1334, design section 35 §4, register SL.5-SL.7): a
 * percentage per member of staff, with per-service overrides, and a report by
 * period read from the calendar each time. Moved from the Salons plugin's
 * `Commission` (WHIT-1154).
 *
 * A booking counts once it is done: confirmed and already over. A booking of
 * several services (WHIT-1173) counts each service line for the person who
 * gave it, at the line's own price and that person's rate for that service;
 * any other booking counts once, at its price. The base is the booking's own
 * currency, so a report holds one row per person per currency. Bookings with
 * no price count, at zero. Periods are in the workspace's time zone, and a week
 * starts on the workspace's first day of the week.
 *
 * Rates are kept in basis points (12.5% is 1250); `service_id` 0 is a
 * person's rate for every service without one of its own.
 */
final class Commission
{
    public const PERIODS = ['this_month', 'last_month', 'this_week', 'last_7_days', 'last_30_days'];

    /** @var (\Closure(): DateTimeImmutable)|null */
    private readonly ?\Closure $clock;

    /** @param (\Closure(): DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly PDO $db,
        private readonly BookingService $booking,
        private readonly CalendarRepository $calendar,
        private readonly ServiceRepository $services,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * Every rate, by person, each person's own rate first.
     *
     * @return list<array<string, mixed>>
     */
    public function rates(int $tenantId, string $language = 'en'): array
    {
        $stmt = $this->db->prepare('SELECT id, profile_id, service_id, percent_bp FROM calendar_commission_rates WHERE tenant_id = :t ORDER BY profile_id, service_id');
        $stmt->execute([':t' => $tenantId]);
        $staff = $this->staffNames($tenantId, false);
        $services = $this->serviceNames($tenantId, $language, false);
        $ar = $language === 'ar';

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $serviceId = (int) $r['service_id'];
            $out[] = [
                'id' => (int) $r['id'],
                'profile_id' => (int) $r['profile_id'],
                'person' => $staff[(int) $r['profile_id']] ?? '',
                'service_id' => $serviceId === 0 ? null : $serviceId,
                'service' => $serviceId === 0 ? ($ar ? 'كل الخدمات' : 'All services') : ($services[$serviceId] ?? ''),
                'percent' => self::percent((int) $r['percent_bp']),
                'percent_bp' => (int) $r['percent_bp'],
            ];
        }

        return $out;
    }

    /**
     * Set a person's rate, for every service or for one.
     *
     * @param array<string, mixed> $input profile_id, service_id (empty: all services), percent
     * @return array<string, mixed> the rate as {@see rates()} lists it
     */
    public function setRate(int $tenantId, array $input, ?int $by, string $language = 'en'): array
    {
        $profileId = self::int($input['profile_id'] ?? null);
        if ($profileId === null || !array_key_exists($profileId, $this->staffNames($tenantId, true))) {
            throw new CalendarRejectedException('Choose a member of staff.', 422, ['field' => 'profile_id']);
        }
        $serviceId = self::int($input['service_id'] ?? null) ?? 0;
        if ($serviceId !== 0 && !array_key_exists($serviceId, $this->serviceNames($tenantId, 'en', true))) {
            throw new CalendarRejectedException('Choose a service from the list, or leave it empty for all services.', 422, ['field' => 'service_id']);
        }
        $bp = self::basisPoints($input['percent'] ?? null);

        $at = $this->now()->format('Y-m-d H:i:s');
        $update = $this->db->prepare('UPDATE calendar_commission_rates SET percent_bp = :bp, updated_at = :at, updated_by_profile_id = :by WHERE tenant_id = :t AND profile_id = :p AND service_id = :s');
        $update->execute([':bp' => $bp, ':at' => $at, ':by' => $by, ':t' => $tenantId, ':p' => $profileId, ':s' => $serviceId]);
        if ($update->rowCount() === 0) {
            $this->db->prepare('INSERT INTO calendar_commission_rates (tenant_id, profile_id, service_id, percent_bp, updated_at, updated_by_profile_id) VALUES (:t, :p, :s, :bp, :at, :by)')
                ->execute([':t' => $tenantId, ':p' => $profileId, ':s' => $serviceId, ':bp' => $bp, ':at' => $at, ':by' => $by]);
        }

        foreach ($this->rates($tenantId, $language) as $rate) {
            if ($rate['profile_id'] === $profileId && ($rate['service_id'] ?? 0) === $serviceId) {
                return $rate;
            }
        }

        return [];
    }

    /** Remove a rate. Someone without one earns no commission. */
    public function removeRate(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM calendar_commission_rates WHERE tenant_id = :t AND id = :id');
        $stmt->execute([':t' => $tenantId, ':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw CalendarRejectedException::notFound('Rate');
        }
    }

    /**
     * The periods to choose from, with their dates.
     *
     * @return list<array{id: string, label: string, from: string, to: string}>
     */
    public function periods(int $tenantId, string $language = 'en'): array
    {
        $labels = $language === 'ar'
            ? ['this_month' => 'هذا الشهر', 'last_month' => 'الشهر الماضي', 'this_week' => 'هذا الأسبوع', 'last_7_days' => 'آخر 7 أيام', 'last_30_days' => 'آخر 30 يوماً']
            : ['this_month' => 'This month', 'last_month' => 'Last month', 'this_week' => 'This week', 'last_7_days' => 'Last 7 days', 'last_30_days' => 'Last 30 days'];
        $out = [];
        foreach (self::PERIODS as $id) {
            [$from, $to] = $this->range($tenantId, $id);
            $last = $to->modify('-1 day');
            $out[] = [
                'id' => $id,
                'label' => $labels[$id] . ' (' . $from->format('Y-m-d') . ' – ' . $last->format('Y-m-d') . ')',
                'from' => $from->format('Y-m-d'),
                'to' => $last->format('Y-m-d'),
            ];
        }

        return $out;
    }

    /**
     * What each person earned in the period: one row per person and currency.
     *
     * @return list<array<string, mixed>>
     */
    public function report(int $tenantId, string $period, string $language = 'en'): array
    {
        if (!in_array($period, self::PERIODS, true)) {
            throw new CalendarRejectedException('Choose a period.', 422, ['field' => 'period']);
        }
        [$from, $to] = $this->range($tenantId, $period);
        $rates = $this->rateTable($tenantId);
        $staff = $this->staffNames($tenantId, false);

        $rows = [];
        foreach ($this->doneLines($tenantId, $from, $to) as $line) {
            $profileId = $line['profile_id'];
            $bp = $rates[$profileId][$line['service_id']] ?? $rates[$profileId][0] ?? 0;
            $key = $profileId . '|' . $line['currency'];
            $rows[$key] ??= ['profile_id' => $profileId, 'currency' => $line['currency'], 'bookings' => 0, 'sales_minor' => 0, 'commission_minor' => 0, 'rated' => isset($rates[$profileId])];
            $rows[$key]['bookings']++;
            $rows[$key]['sales_minor'] += $line['price_minor'];
            $rows[$key]['commission_minor'] += intdiv($line['price_minor'] * $bp + 5000, 10000);
        }

        $ar = $language === 'ar';
        $out = [];
        foreach ($rows as $r) {
            $out[] = $r + [
                'person' => $staff[$r['profile_id']] ?? '',
                'sales' => self::money($r['sales_minor'], $r['currency']),
                'commission' => $r['rated'] ? self::money($r['commission_minor'], $r['currency']) : ($ar ? 'لا نسبة محددة' : 'No rate set'),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['person'], $a['currency']] <=> [$b['person'], $b['currency']]);

        return $out;
    }

    /**
     * The bookable staff a rate can be set for.
     *
     * @return list<array{profile_id: int, name: string}>
     */
    public function staff(int $tenantId): array
    {
        $out = [];
        foreach ($this->staffNames($tenantId, true) as $id => $name) {
            $out[] = ['profile_id' => $id, 'name' => $name];
        }

        return $out;
    }

    /**
     * The active services a rate can be set for.
     *
     * @return list<array{id: int, name: string}>
     */
    public function services(int $tenantId, string $language = 'en'): array
    {
        $out = [];
        foreach ($this->serviceNames($tenantId, $language, true) as $id => $name) {
            $out[] = ['id' => $id, 'name' => $name];
        }

        return $out;
    }

    /**
     * [from, to) of a period, as instants at midnight in the workspace's zone.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function range(int $tenantId, string $period): array
    {
        $settings = $this->booking->settingsFor($tenantId);
        $zone = $settings->zone;
        $today = $this->now()->setTimezone($zone)->setTime(0, 0);
        $month = $today->modify('first day of this month');
        // ISO weekday of today (1 Monday … 7 Sunday) back to the workspace's first day.
        $back = ((int) $today->format('N') - $settings->weekStart + 7) % 7;

        return match ($period) {
            'this_month' => [$month, $month->modify('+1 month')],
            'last_month' => [$month->modify('-1 month'), $month],
            'this_week' => [$week = $today->modify("-{$back} days"), $week->modify('+7 days')],
            'last_7_days' => [$today->modify('-6 days'), $today->modify('+1 day')],
            default => [$today->modify('-29 days'), $today->modify('+1 day')],
        };
    }

    /**
     * Every done service line that started in [from, to): confirmed, over by
     * now. One per service of a booking of several, else one per booking.
     *
     * @return list<array{booking_id: int, profile_id: int, service_id: int, price_minor: int, currency: string}>
     */
    public function doneLines(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $utc = new DateTimeZone('UTC');
        $params = [
            ':t' => $tenantId,
            ':status' => BookingRepository::STATUS_CONFIRMED,
            ':from' => $from->setTimezone($utc)->format('Y-m-d H:i:s'),
            ':to' => $to->setTimezone($utc)->format('Y-m-d H:i:s'),
            ':now' => $this->now()->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
        $several = $this->db->prepare(
            'SELECT b.id AS booking_id, i.profile_id, i.service_id, i.price_minor, b.currency
               FROM calendar_booking_services i
               JOIN calendar_bookings b ON b.tenant_id = i.tenant_id AND b.id = i.booking_id
              WHERE i.tenant_id = :t AND b.status = :status
                AND b.starts_at >= :from AND b.starts_at < :to AND b.ends_at <= :now
              ORDER BY b.id, i.position'
        );
        $several->execute($params);
        $one = $this->db->prepare(
            'SELECT b.id AS booking_id, b.profile_id, b.service_id, b.price_minor, b.currency
               FROM calendar_bookings b
              WHERE b.tenant_id = :t AND b.status = :status
                AND b.starts_at >= :from AND b.starts_at < :to AND b.ends_at <= :now
                AND NOT EXISTS (SELECT 1 FROM calendar_booking_services i WHERE i.tenant_id = b.tenant_id AND i.booking_id = b.id)
              ORDER BY b.id'
        );
        $one->execute($params);

        $out = [];
        foreach ([...$several->fetchAll(PDO::FETCH_ASSOC), ...$one->fetchAll(PDO::FETCH_ASSOC)] as $r) {
            $out[] = [
                'booking_id' => (int) $r['booking_id'],
                'profile_id' => (int) $r['profile_id'],
                'service_id' => (int) $r['service_id'],
                'price_minor' => $r['price_minor'] !== null ? (int) $r['price_minor'] : 0,
                'currency' => strtoupper(trim((string) $r['currency'])),
            ];
        }

        return $out;
    }

    /** @return array<int, array<int, int>> profile id => service id (0: all) => basis points */
    private function rateTable(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT profile_id, service_id, percent_bp FROM calendar_commission_rates WHERE tenant_id = :t');
        $stmt->execute([':t' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['profile_id']][(int) $r['service_id']] = (int) $r['percent_bp'];
        }

        return $out;
    }

    /** @return array<int, string> profile id => name */
    private function staffNames(int $tenantId, bool $bookableOnly): array
    {
        $out = [];
        foreach ($this->calendar->listStaff($tenantId) as $s) {
            if (!$bookableOnly || $s['bookable']) {
                $out[$s['profile_id']] = $s['display_name'];
            }
        }

        return $out;
    }

    /** @return array<int, string> service id => name in the person's language */
    private function serviceNames(int $tenantId, string $language, bool $activeOnly): array
    {
        $out = [];
        foreach ($this->services->list($tenantId, $activeOnly) as $s) {
            $name = $s['name'];
            $out[(int) $s['id']] = is_array($name) || is_string($name) ? LocalizedText::resolve($name, $language) : '';
        }

        return $out;
    }

    /** "12.5", "12,5", "12.5%" or 12 → basis points; 0 to 100. */
    public static function basisPoints(mixed $value): int
    {
        $text = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? trim(str_replace([',', '%'], ['.', ''], $value)) : '');
        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text) !== 1 || (float) $text > 100) {
            throw new CalendarRejectedException('Write the percentage as a number from 0 to 100, like 30 or 12.5.', 422, ['field' => 'percent']);
        }
        [$whole, $fraction] = array_pad(explode('.', $text), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function percent(int $bp): string
    {
        return rtrim(rtrim(sprintf('%d.%02d', intdiv($bp, 100), $bp % 100), '0'), '.') . '%';
    }

    /** $312.00 or JOD 312.000 (CU.2); an amount with no currency is the bare number. */
    private static function money(int $minor, string $currency): string
    {
        if ($currency === '') {
            return number_format($minor / 100, 2, '.', ',');
        }

        return Currency::display($minor, $currency);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : $this->booking->now();
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
