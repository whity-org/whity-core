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
        public readonly int $reminderHoursBefore,
        public readonly int $paymentHoldMinutes,
        public readonly int $weekStart,
        public readonly array $weekendDays,
        public readonly string $currency,
    ) {
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

        return new self(
            $zone,
            ($effective[SettingsRegistry::CALENDAR_BOOKING_PAGE_ENABLED] ?? 'false') === 'true',
            $int(SettingsRegistry::CALENDAR_MIN_NOTICE_MINUTES, 120),
            max(1, $int(SettingsRegistry::CALENDAR_MAX_ADVANCE_DAYS, 60)),
            max(5, $int(SettingsRegistry::CALENDAR_SLOT_STEP_MINUTES, 15)),
            $int(SettingsRegistry::CALENDAR_REMINDER_HOURS_BEFORE, 24),
            max(1, $int(SettingsRegistry::CALENDAR_PAYMENT_HOLD_MINUTES, 15)),
            self::weekday($effective[SettingsRegistry::CALENDAR_WEEK_START] ?? 'saturday') ?? 6,
            self::weekdays($effective[SettingsRegistry::CALENDAR_WEEKEND_DAYS] ?? 'friday,saturday'),
            $effective[SettingsRegistry::BILLING_DEFAULT_CURRENCY] ?? 'JOD',
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
            'payment_hold_minutes' => $this->paymentHoldMinutes,
            'week_start' => self::WEEKDAYS[$this->weekStart - 1],
            'weekend_days' => array_map(static fn (int $d): string => self::WEEKDAYS[$d - 1], $this->weekendDays),
            'currency' => $this->currency,
        ];
    }
}
