<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeZone;
use Whity\Core\Settings\SettingsRegistry;

/**
 * The workspace's calendar settings, read once from the effective settings
 * (ADR 0017, section 1). Immutable — worker-safe.
 */
final class CalendarSettings
{
    /**
     * @param list<int> $weekendDays ISO weekdays, 1 = Monday … 7 = Sunday
     */
    public function __construct(
        public readonly DateTimeZone $zone,
        public readonly bool $bookingPageEnabled,
        public readonly int $minNoticeMinutes,
        public readonly int $maxAdvanceDays,
        public readonly int $slotStepMinutes,
        /** The first reminder's hours before (the largest), 0 when there are none; kept for the API. */
        public readonly int $reminderHoursBefore,
        public readonly int $paymentHoldMinutes,
        public readonly int $weekStart,
        public readonly array $weekendDays,
        public readonly string $currency,
        public readonly bool $instantBooking = true,
        /** WHIT-1173: one booking may hold several services back to back. */
        public readonly bool $multiServiceBooking = false,
        /** WHIT-1334: the walk-in queue at the front desk is on. */
        public readonly bool $walkinsEnabled = false,
        /**
         * WHIT-1417 (AP.15): every reminder, as hours before the start,
         * largest first; empty = no reminders.
         *
         * @var list<int>
         */
        public readonly array $reminderHours = [24],
        /** WHIT-1427 (AP.4): minutes after the start before Did they come? asks. */
        public readonly int $noShowGraceMinutes = 15,
        /** WHIT-1427 (AP.4, Q2): end-of-day marking. */
        public readonly bool $autoNoShow = false,
        /** WHIT-1427 (AP.5): a cancel within this many hours of the start is late. */
        public readonly int $lateCancelHours = 24,
        /** WHIT-1427 (AP.10): the flag counts misses over this many months... */
        public readonly int $noShowWindowMonths = 12,
        /** ...from this many misses (0 = no flag)... */
        public readonly int $noShowFlagAfter = 2,
        /** ...late cancels included unless turned off. */
        public readonly bool $noShowCountLateCancels = true,
    ) {
    }

    /**
     * `calendar.reminders` as hours, largest first; anything unreadable is
     * left out.
     *
     * @return list<int>
     */
    public static function reminderHours(string $csv): array
    {
        $hours = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if (preg_match('/^\d+$/', $part) === 1 && (int) $part >= 1 && (int) $part <= 168) {
                $hours[(int) $part] = (int) $part;
            }
        }
        krsort($hours);

        return array_values(array_slice($hours, 0, 3, true));
    }

    /** @param array<string, string> $effective */
    public static function fromSettings(array $effective): self
    {
        $zoneName = $effective[SettingsRegistry::TIMEZONE] ?? 'UTC';
        try {
            $zone = new DateTimeZone($zoneName !== '' ? $zoneName : 'UTC');
        } catch (\Exception) {
            $zone = new DateTimeZone('UTC');
        }
        $int = static fn (string $key, int $default): int => isset($effective[$key]) && preg_match('/^\d+$/', $effective[$key]) === 1
            ? (int) $effective[$key]
            : $default;

        $reminderHours = self::reminderHours($effective[SettingsRegistry::CALENDAR_REMINDERS] ?? '24,2');

        return new self(
            $zone,
            ($effective[SettingsRegistry::CALENDAR_BOOKING_PAGE_ENABLED] ?? 'false') === 'true',
            $int(SettingsRegistry::CALENDAR_MIN_NOTICE_MINUTES, 120),
            max(1, $int(SettingsRegistry::CALENDAR_MAX_ADVANCE_DAYS, 60)),
            max(5, $int(SettingsRegistry::CALENDAR_SLOT_STEP_MINUTES, 15)),
            $reminderHours[0] ?? 0,
            max(1, $int(SettingsRegistry::CALENDAR_PAYMENT_HOLD_MINUTES, 15)),
            self::weekday($effective[SettingsRegistry::CALENDAR_WEEK_START] ?? 'saturday') ?? 6,
            self::weekdays($effective[SettingsRegistry::CALENDAR_WEEKEND_DAYS] ?? 'friday,saturday'),
            $effective[SettingsRegistry::BILLING_DEFAULT_CURRENCY] ?? 'USD',
            ($effective[SettingsRegistry::CALENDAR_INSTANT_BOOKING] ?? 'true') !== 'false',
            ($effective[SettingsRegistry::CALENDAR_MULTI_SERVICE_BOOKING] ?? 'false') === 'true',
            ($effective[SettingsRegistry::CALENDAR_WALKINS_ENABLED] ?? 'false') === 'true',
            $reminderHours,
            $int(SettingsRegistry::CALENDAR_NO_SHOW_GRACE_MINUTES, 15),
            ($effective[SettingsRegistry::CALENDAR_AUTO_NO_SHOW] ?? 'false') === 'true',
            $int(SettingsRegistry::CALENDAR_LATE_CANCEL_HOURS, 24),
            max(1, $int(SettingsRegistry::CALENDAR_NO_SHOW_WINDOW_MONTHS, 12)),
            $int(SettingsRegistry::CALENDAR_NO_SHOW_FLAG_AFTER, 2),
            ($effective[SettingsRegistry::CALENDAR_NO_SHOW_COUNT_LATE_CANCELS] ?? 'true') !== 'false',
        );
    }

    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public static function weekday(string $name): ?int
    {
        $index = array_search(strtolower(trim($name)), self::WEEKDAYS, true);

        return $index === false ? null : $index + 1;
    }

    /** @return list<int> */
    public static function weekdays(string $csv): array
    {
        $days = [];
        foreach (explode(',', $csv) as $name) {
            $day = self::weekday($name);
            if ($day !== null) {
                $days[$day] = $day;
            }
        }
        ksort($days);

        return array_values($days);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'time_zone' => $this->zone->getName(),
            'booking_page_enabled' => $this->bookingPageEnabled,
            'min_notice_minutes' => $this->minNoticeMinutes,
            'max_advance_days' => $this->maxAdvanceDays,
            'slot_step_minutes' => $this->slotStepMinutes,
            'reminder_hours_before' => $this->reminderHoursBefore,
            'reminders' => $this->reminderHours,
            'payment_hold_minutes' => $this->paymentHoldMinutes,
            'week_start' => self::WEEKDAYS[$this->weekStart - 1],
            'weekend_days' => array_map(static fn (int $d): string => self::WEEKDAYS[$d - 1], $this->weekendDays),
            'currency' => $this->currency,
            'instant_booking' => $this->instantBooking,
            'multi_service_booking' => $this->multiServiceBooking,
            'walkins_enabled' => $this->walkinsEnabled,
            'no_show_grace_minutes' => $this->noShowGraceMinutes,
            'auto_no_show' => $this->autoNoShow,
            'late_cancel_hours' => $this->lateCancelHours,
            'no_show_window_months' => $this->noShowWindowMonths,
            'no_show_flag_after' => $this->noShowFlagAfter,
            'no_show_count_late_cancels' => $this->noShowCountLateCancels,
        ];
    }
}
