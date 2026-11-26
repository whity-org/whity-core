<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Whity\Sdk\JobInterface;

/**
 * End-of-day marking (WHIT-1427, design section 39 §4.1, AP.4, Q2).
 *
 * Queued once an hour by `schedule:run`. In every workspace that turned on
 * `calendar.auto_no_show` (off by default), confirmed bookings that nobody
 * marked and whose day has ended in the workspace's zone become Didn't come,
 * marked by Whity (`attendance_auto`). Only the last two days, and never
 * before the switch was turned on. A person can always change the mark.
 */
final class AttendanceAutoMarkJob implements JobInterface
{
    public const NAME = 'core.calendar.attendance_auto_mark';

    public function __construct(private readonly BookingService $bookings)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return ['marked' => $this->bookings->autoMarkNoShows()];
    }
}
