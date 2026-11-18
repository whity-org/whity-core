<?php

declare(strict_types=1);

namespace Whity\Core\Delegation;

use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Interval;
use Whity\Sdk\Delegation\Away;

/**
 * The SDK's {@see Away} host service (SDK 1.120, WHIT-1210): whether a person
 * is away at a moment, and who decides for them. Read only.
 *
 * Two things make a person away: their "I'm away" period
 * ({@see AwayService}, whole UTC days, with a stand-in) and time off on their
 * calendar (an event of kind `time_off`, no stand-in). The period wins when
 * both cover the moment; the time off's id comes with it.
 */
final class HostAway implements Away
{
    public function __construct(
        private readonly AwayService $away,
        private readonly CalendarRepository $calendar,
    ) {
    }

    public function at(int $tenantId, int $profileId, string $atUtc): ?array
    {
        $at = Interval::fromApi($atUtc) ?? throw new \InvalidArgumentException('atUtc must be a date and time with an offset or Z.');
        $timeOff = $this->calendar->timeOff($tenantId, $profileId, $at, $at->modify('+1 second'))[0] ?? null;
        $period = $this->away->inForceOn($tenantId, $profileId, $at->format('Y-m-d'));

        if ($period !== null) {
            return [
                'from' => $period['startsOn'] . 'T00:00:00Z',
                'until' => $period['backOn'] . 'T00:00:00Z',
                'standInProfileId' => (int) $period['delegate']['id'],
                'covers' => array_values($period['covers']),
                'source' => 'away',
                'timeOffId' => $timeOff['id'] ?? null,
            ];
        }
        if ($timeOff === null) {
            return null;
        }

        return [
            'from' => Interval::toApi($timeOff['interval']->start),
            'until' => Interval::toApi($timeOff['interval']->end),
            'standInProfileId' => null,
            'covers' => [],
            'source' => 'time_off',
            'timeOffId' => $timeOff['id'],
        ];
    }
}
