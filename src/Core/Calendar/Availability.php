<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns weekly wall-clock hours, dated exceptions and busy time into open
 * windows and bookable slots (ADR 0017, section 2). Pure: no database, no
 * clock, so it is tested across zones and clock changes directly.
 *
 * TIME ZONES
 * Working hours are local wall-clock times. They become UTC instants per local
 * date, in the workspace's zone, using that date's own offsets:
 * - a local time that does not exist (the spring-forward gap) becomes the
 *   instant the clocks jumped, the first instant after the gap;
 * - a local time that happens twice (fall-back) is the FIRST occurrence;
 * - `24:00` as an end time is midnight at the end of that day.
 * Slots step through absolute time from the start of each open window, and
 * each candidate is checked as a UTC interval, so a slot is never offered twice
 * nor skipped across a clock change.
 */
final class Availability
{
    /**
     * Open windows for one person in `[from, to)`.
     *
     * @param list<array{weekday: int, start_time: string, end_time: string}> $hours
     * @param list<array{kind: string, interval: Interval}> $exceptions
     * @return list<Interval>
     */
    public static function openWindows(array $hours, array $exceptions, DateTimeZone $zone, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $windows = [];
        $day = $from->setTimezone($zone)->setTime(0, 0)->modify('-1 day');
        $last = $to->setTimezone($zone)->setTime(0, 0)->modify('+1 day');
        while ($day <= $last) {
            $date = $day->format('Y-m-d');
            $weekday = (int) $day->format('N');
            foreach ($hours as $row) {
                if ((int) $row['weekday'] !== $weekday) {
                    continue;
                }
                $start = self::localInstant($date, $row['start_time'], $zone);
                $end = self::localInstant($date, $row['end_time'], $zone);
                if ($start !== null && $end !== null && $start < $end) {
                    $windows[] = new Interval($start, $end);
                }
            }
            $day = $day->modify('+1 day');
        }

        $off = [];
        foreach ($exceptions as $exception) {
            if ($exception['kind'] === 'open') {
                $windows[] = $exception['interval'];
            } else {
                $off[] = $exception['interval'];
            }
        }

        $range = new Interval($from, $to);
        $result = [];
        foreach (Interval::merge($windows) as $window) {
            foreach ($window->subtract($off) as $piece) {
                $clipped = new Interval(max($piece->start, $range->start), min($piece->end, $range->end));
                if (!$clipped->isEmpty()) {
                    $result[] = $clipped;
                }
            }
        }

        return Interval::merge($result);
    }

    /**
     * Bookable start times for one person, in `[earliest, latest)`.
     *
     * @param list<Interval> $windows open windows ({@see openWindows()})
     * @param list<Interval> $busy    everything that blocks: bookings with their buffers, busy events, external busy time
     * @return list<DateTimeImmutable>
     */
    public static function slots(
        array $windows,
        array $busy,
        int $durationMinutes,
        int $bufferBeforeMinutes,
        int $bufferAfterMinutes,
        int $stepMinutes,
        DateTimeImmutable $earliest,
        DateTimeImmutable $latest,
    ): array {
        $busy = Interval::merge($busy);
        $slots = [];
        $step = max(1, $stepMinutes) * 60;
        foreach ($windows as $window) {
            for ($t = $window->start->getTimestamp(); $t + $durationMinutes * 60 <= $window->end->getTimestamp(); $t += $step) {
                $start = (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('UTC'));
                if ($start < $earliest || $start >= $latest) {
                    continue;
                }
                if (!self::clashes($start, $durationMinutes, $bufferBeforeMinutes, $bufferAfterMinutes, $busy)) {
                    $slots[$t] = $start;
                }
            }
        }
        ksort($slots);

        return array_values($slots);
    }

    /**
     * Does a booking at `$start` overlap anything busy, buffers included?
     *
     * @param list<Interval> $busy
     */
    public static function clashes(DateTimeImmutable $start, int $durationMinutes, int $bufferBeforeMinutes, int $bufferAfterMinutes, array $busy): bool
    {
        $block = self::blockFor($start, $durationMinutes, $bufferBeforeMinutes, $bufferAfterMinutes);
        foreach ($busy as $interval) {
            if ($interval->overlaps($block)) {
                return true;
            }
        }

        return false;
    }

    /** The span a booking takes, buffers included. */
    public static function blockFor(DateTimeImmutable $start, int $durationMinutes, int $bufferBeforeMinutes, int $bufferAfterMinutes): Interval
    {
        $t = $start->getTimestamp();

        return new Interval(
            new DateTimeImmutable('@' . ($t - $bufferBeforeMinutes * 60)),
            new DateTimeImmutable('@' . ($t + ($durationMinutes + $bufferAfterMinutes) * 60)),
        );
    }

    /**
     * Is `[start, start + duration)` inside one open window?
     *
     * @param list<Interval> $windows
     */
    public static function withinWindows(DateTimeImmutable $start, int $durationMinutes, array $windows): bool
    {
        $service = new Interval($start, new DateTimeImmutable('@' . ($start->getTimestamp() + $durationMinutes * 60)));
        foreach ($windows as $window) {
            if ($window->contains($service)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A local date and wall-clock time as a UTC instant, with the gap and
     * repeat rules in the class comment. Null for an unreadable time.
     */
    public static function localInstant(string $date, string $time, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (preg_match('/^([01]\d|2[0-4]):([0-5]\d)$/', $time, $m) !== 1 || ((int) $m[1] === 24 && (int) $m[2] !== 0)) {
            return null;
        }
        if ((int) $m[1] === 24) {
            $next = (new DateTimeImmutable($date, $zone))->modify('+1 day')->format('Y-m-d');

            return self::localInstant($next, '00:00', $zone);
        }

        $wanted = $date . ' ' . $time;
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $wanted, $zone);
        if ($at === false) {
            return null;
        }
        $ts = $at->getTimestamp();

        if ($at->format('Y-m-d H:i') !== $wanted) {
            // In a spring-forward gap: the first instant after it is the
            // moment the clocks jumped.
            foreach ($zone->getTransitions($ts - 86400, $ts + 86400) ?: [] as $i => $transition) {
                if ($i > 0 && $transition['ts'] <= $ts && $transition['ts'] > $ts - 10800) {
                    return (new DateTimeImmutable('@' . $transition['ts']))->setTimezone(new DateTimeZone('UTC'));
                }
            }

            return $at->setTimezone(new DateTimeZone('UTC'));
        }

        // A repeated wall time: prefer the earlier instant that reads the same.
        foreach ([3600, 1800] as $back) {
            $earlier = (new DateTimeImmutable('@' . ($ts - $back)))->setTimezone($zone);
            if ($earlier->format('Y-m-d H:i') === $wanted) {
                return $earlier->setTimezone(new DateTimeZone('UTC'));
            }
        }

        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
