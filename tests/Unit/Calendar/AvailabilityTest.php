<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Whity\Core\Calendar\Availability;
use Whity\Core\Calendar\Interval;

/**
 * ADR 0017, section 2: hours are wall clock, turned into UTC per local date,
 * with the spring-forward and fall-back rules, and no slot twice or skipped.
 */
final class AvailabilityTest extends TestCase
{
    private static function utc(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    /**
     * @param list<DateTimeImmutable> $slots
     * @return list<string>
     */
    private static function fmt(array $slots): array
    {
        return array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i'), $slots);
    }

    public function testAmmanHoursBecomeUtcAtPlusThree(): void
    {
        $zone = new DateTimeZone('Asia/Amman');
        // 2026-11-15 is a Sunday (ISO 7).
        $windows = Availability::openWindows(
            [['weekday' => 7, 'start_time' => '09:00', 'end_time' => '17:00']],
            [],
            $zone,
            self::utc('2026-11-15 00:00'),
            self::utc('2026-11-16 00:00'),
        );
        self::assertCount(1, $windows);
        self::assertSame('2026-11-15 06:00', $windows[0]->start->format('Y-m-d H:i'));
        self::assertSame('2026-11-15 14:00', $windows[0]->end->format('Y-m-d H:i'));
    }

    public function testSpringForwardGapStartsAtTheJump(): void
    {
        // London jumps from 01:00 GMT to 02:00 BST on 2026-03-29 (a Sunday).
        $zone = new DateTimeZone('Europe/London');
        $start = Availability::localInstant('2026-03-29', '01:30', $zone);
        self::assertNotNull($start);
        self::assertSame('2026-03-29 01:00', $start->format('Y-m-d H:i'), 'a time in the gap becomes the first instant after it');
    }

    public function testFallBackRepeatMeansTheFirstOccurrence(): void
    {
        // London repeats 01:00-02:00 on 2026-10-25: 01:30 BST is 00:30 UTC.
        $zone = new DateTimeZone('Europe/London');
        $at = Availability::localInstant('2026-10-25', '01:30', $zone);
        self::assertNotNull($at);
        self::assertSame('2026-10-25 00:30', $at->format('Y-m-d H:i'));
    }

    public function testDayAcrossFallBackKeepsWallClockHours(): void
    {
        // New York falls back on 2026-11-01 (Sunday). 00:00-04:00 local is 5h elapsed.
        $zone = new DateTimeZone('America/New_York');
        $windows = Availability::openWindows(
            [['weekday' => 7, 'start_time' => '00:00', 'end_time' => '04:00']],
            [],
            $zone,
            self::utc('2026-11-01 00:00'),
            self::utc('2026-11-02 12:00'),
        );
        self::assertCount(1, $windows);
        self::assertSame('2026-11-01 04:00', $windows[0]->start->format('Y-m-d H:i'));
        self::assertSame('2026-11-01 09:00', $windows[0]->end->format('Y-m-d H:i'));

        // Hourly slots step through absolute time: five distinct starts, none twice.
        $slots = Availability::slots($windows, [], 60, 0, 0, 60, self::utc('2026-01-01'), self::utc('2027-01-01'));
        self::assertSame(['2026-11-01 04:00', '2026-11-01 05:00', '2026-11-01 06:00', '2026-11-01 07:00', '2026-11-01 08:00'], self::fmt($slots));
    }

    public function testSameWeeklyRowMovesWithTheClocks(): void
    {
        $zone = new DateTimeZone('Europe/London');
        $hours = [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']];
        $winter = Availability::openWindows($hours, [], $zone, self::utc('2026-03-23 00:00'), self::utc('2026-03-24 00:00'));
        $summer = Availability::openWindows($hours, [], $zone, self::utc('2026-03-30 00:00'), self::utc('2026-03-31 00:00'));
        self::assertSame('09:00', $winter[0]->start->format('H:i'));
        self::assertSame('08:00', $summer[0]->start->format('H:i'));
    }

    public function testBuffersBusyTimeOffAndNotice(): void
    {
        $zone = new DateTimeZone('UTC');
        $windows = Availability::openWindows(
            [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00']],
            [['kind' => 'off', 'interval' => new Interval(self::utc('2026-11-16 12:00'), self::utc('2026-11-16 13:00'))]],
            $zone,
            self::utc('2026-11-16 00:00'),
            self::utc('2026-11-17 00:00'),
        );
        self::assertSame('2026-11-16 12:00', $windows[0]->end->format('Y-m-d H:i'));

        // A booking 10:00-10:30 is busy; 30-minute service with 15 min after.
        $busy = [new Interval(self::utc('2026-11-16 10:00'), self::utc('2026-11-16 10:30'))];
        $slots = Availability::slots($windows, $busy, 30, 0, 15, 30, self::utc('2026-11-16 09:30'), self::utc('2026-12-01'));
        // 09:00 is before the notice; 09:30 + 30 + 15 runs into 10:00; 10:00 is busy;
        // 10:30, 11:00 and 11:30 fit (11:30 ends at 12:00, its buffer after a window end is allowed).
        self::assertSame(['2026-11-16 10:30', '2026-11-16 11:00', '2026-11-16 11:30'], self::fmt($slots));
    }

    public function testOpenExceptionAddsHoursOnAClosedDay(): void
    {
        $windows = Availability::openWindows(
            [],
            [['kind' => 'open', 'interval' => new Interval(self::utc('2026-11-20 08:00'), self::utc('2026-11-20 10:00'))]],
            new DateTimeZone('Asia/Amman'),
            self::utc('2026-11-20 00:00'),
            self::utc('2026-11-21 00:00'),
        );
        self::assertCount(1, $windows);
        self::assertSame('08:00', $windows[0]->start->format('H:i'));
    }

    public function testApiInstantsNeedAnOffset(): void
    {
        self::assertNull(Interval::fromApi('2026-11-16T09:00:00'));
        $at = Interval::fromApi('2026-11-16T09:00:00+03:00');
        self::assertNotNull($at);
        self::assertSame('2026-11-16T06:00:00Z', Interval::toApi($at));
    }
}
