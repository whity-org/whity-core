<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Calendar\Availability;

/**
 * Free times across every clock change of a year (WHIT-1440, design section
 * 39 §5.3, AP.16): for six zones, every date of 2026 offers exactly the
 * working hours on the wall clock, 09:00 to 16:00 for an hour's service,
 * never one twice or one missing, whatever the offset that day. Lord Howe
 * moves by 30 minutes; Amman has had no change since 2022; Santiago changes
 * in the southern spring and autumn.
 */
final class DstYearTest extends TestCase
{
    /** @return array<string, array{string, int}> zone, clock changes in 2026 */
    public static function zones(): array
    {
        return [
            'Amman' => ['Asia/Amman', 0],
            'London' => ['Europe/London', 2],
            'New York' => ['America/New_York', 2],
            'Sydney' => ['Australia/Sydney', 2],
            'Lord Howe' => ['Australia/Lord_Howe', 2],
            'Santiago' => ['America/Santiago', 2],
        ];
    }

    #[DataProvider('zones')]
    public function testEveryDateOffersTheSameWallClockHours(string $name, int $changes): void
    {
        $zone = new DateTimeZone($name);
        $from = new DateTimeImmutable('2026-01-01 00:00:00', $zone);
        $to = new DateTimeImmutable('2027-01-01 00:00:00', $zone);
        $transitions = array_slice($zone->getTransitions($from->getTimestamp(), $to->getTimestamp()), 1);
        self::assertCount($changes, $transitions, "{$name} changes its clocks {$changes} times in 2026");

        $hours = array_map(static fn (int $d): array => ['weekday' => $d, 'start_time' => '09:00', 'end_time' => '17:00'], range(1, 7));
        $utc = new DateTimeZone('UTC');
        $windows = Availability::openWindows($hours, [], $zone, $from->setTimezone($utc), $to->setTimezone($utc));
        $slots = Availability::slots($windows, [], 60, 0, 0, 60, $from->setTimezone($utc), $to->setTimezone($utc));

        $byDate = [];
        foreach ($slots as $slot) {
            $local = $slot->setTimezone($zone);
            $byDate[$local->format('Y-m-d')][] = $local->format('H:i');
        }
        self::assertCount(365, $byDate, 'every date of the year is open');
        $expected = ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00'];
        foreach ($byDate as $date => $times) {
            self::assertSame($expected, $times, "{$name} on {$date}");
        }

        // On each change day and the day after, the offset moved but the hours did not.
        foreach ($transitions as $change) {
            $day = (new DateTimeImmutable('@' . $change['ts']))->setTimezone($zone)->format('Y-m-d');
            $next = (new DateTimeImmutable($day . ' 12:00:00', $zone))->modify('+1 day')->format('Y-m-d');
            self::assertSame($expected, $byDate[$day], "{$name} on the change day {$day}");
            self::assertSame($expected, $byDate[$next], "{$name} on the day after {$day}");
        }
    }

    public function testLordHowesHalfHourChangeMovesTheUtcStartByHalfAnHour(): void
    {
        $zone = new DateTimeZone('Australia/Lord_Howe');
        $utc = new DateTimeZone('UTC');
        $hours = [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']];
        // Summer (+11) ends on 5 April 2026; winter is +10:30.
        $summer = Availability::openWindows($hours, [], $zone, new DateTimeImmutable('2026-03-29 12:00:00', $utc), new DateTimeImmutable('2026-03-31 00:00:00', $utc));
        $winter = Availability::openWindows($hours, [], $zone, new DateTimeImmutable('2026-04-05 12:00:00', $utc), new DateTimeImmutable('2026-04-07 00:00:00', $utc));
        self::assertSame('22:00', $summer[0]->start->format('H:i'));
        self::assertSame('22:30', $winter[0]->start->format('H:i'));
    }
}
