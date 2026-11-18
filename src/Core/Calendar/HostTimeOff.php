<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Calendar\TimeOff;
use Whity\Sdk\Calendar\TimeOffRejected;

/**
 * The SDK's {@see TimeOff} host service (SDK 1.120, WHIT-1210): a plugin puts
 * time off on a person's calendar as the person who approved it.
 *
 * It is the time-off route without HTTP: an event of kind `time_off`, busy,
 * written through the booking service so it takes the person's lock and fires
 * `calendar.event.*` like any other. The checks are the route's: the acting
 * person needs `calendar:write`, and `calendar:manage` too when it is someone
 * else's calendar; the person must be staff.
 *
 * A plugin's reference makes `create()` idempotent. It is looked up first,
 * and migration 307's unique index catches two retries racing past the
 * lookup: the loser reads back the winner's row.
 */
final class HostTimeOff implements TimeOff
{
    private const MAX_SECONDS = 366 * 86400;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly CalendarRepository $calendar,
        private readonly RoleChecker $roles,
    ) {
    }

    public function create(int $tenantId, int $profileId, string $startUtc, string $endUtc, string $reason, int $actingProfileId, ?string $externalRef = null): int
    {
        $when = self::interval($startUtc, $endUtc);
        if ($externalRef !== null && ($externalRef === '' || strlen($externalRef) > 255)) {
            throw new TimeOffRejected(TimeOffRejected::INVALID_REF, 'A reference is 1 to 255 characters.');
        }
        if ($profileId <= 0 || !$this->calendar->isStaff($tenantId, $profileId)) {
            throw new TimeOffRejected(TimeOffRejected::NOT_STAFF, 'Time off is only for staff of this workspace.');
        }
        $this->assertMay($tenantId, $actingProfileId, $profileId);

        $note = mb_substr(trim($reason), 0, 500);
        $title = $note !== '' ? mb_substr($note, 0, 200) : 'Time off';

        if ($externalRef !== null) {
            $existing = $this->byRef($tenantId, $externalRef);
            if ($existing !== null) {
                return $this->keep($tenantId, $existing, $profileId, $when, $title, $note);
            }
        }

        $this->calendar->ensureStaff($tenantId, $profileId);
        try {
            $event = $this->bookings->createEvent($tenantId, $profileId, $title, $note, $when, false, true, $actingProfileId, $externalRef, kind: CalendarRepository::KIND_TIME_OFF);
        } catch (\PDOException $e) {
            // Another call with the same reference won the race (migration 307).
            $existing = $externalRef !== null ? $this->byRef($tenantId, $externalRef) : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->keep($tenantId, $existing, $profileId, $when, $title, $note);
        }

        return (int) $event['event']['id'];
    }

    public function cancel(int $tenantId, int $timeOffId, int $actingProfileId): bool
    {
        $row = $this->calendar->timeOff($tenantId, id: $timeOffId)[0] ?? null;
        if ($row === null) {
            return false;
        }
        $this->assertMay($tenantId, $actingProfileId, $row['profile_id']);
        $this->bookings->cancelEvent($tenantId, $row['id']);

        return true;
    }

    public function forPerson(int $tenantId, int $profileId, string $fromUtc, string $toUtc): array
    {
        $range = self::interval($fromUtc, $toUtc);

        return array_map(self::present(...), $this->calendar->timeOff($tenantId, $profileId, $range->start, $range->end));
    }

    public function byExternalRef(int $tenantId, string $externalRef): ?array
    {
        $row = $externalRef !== '' ? $this->byRef($tenantId, $externalRef) : null;

        return $row !== null ? self::present($row) : null;
    }

    /** @return array{id: int, profile_id: int, interval: Interval, note: string, external_ref: ?string}|null */
    private function byRef(int $tenantId, string $externalRef): ?array
    {
        return $this->calendar->timeOff($tenantId, externalRef: $externalRef)[0] ?? null;
    }

    /**
     * The reference already has time off: the same person's is moved to the
     * new times and reason when they changed; someone else's is a conflict.
     *
     * @param array{id: int, profile_id: int, interval: Interval, note: string, external_ref: ?string} $existing
     */
    private function keep(int $tenantId, array $existing, int $profileId, Interval $when, string $title, string $note): int
    {
        if ($existing['profile_id'] !== $profileId) {
            throw new TimeOffRejected(TimeOffRejected::REF_CONFLICT, 'That reference already names time off for someone else.');
        }
        $same = $existing['interval']->start == $when->start && $existing['interval']->end == $when->end && $existing['note'] === $note;
        if (!$same) {
            $this->bookings->updateEvent($tenantId, $existing['id'], $title, $note, $when, false, true);
        }

        return $existing['id'];
    }

    private function assertMay(int $tenantId, int $actor, int $profileId): void
    {
        $may = $actor > 0
            && $this->roles->hasPermissionForProfile($actor, CorePermissions::CALENDAR_WRITE, $tenantId)
            && ($actor === $profileId || $this->roles->hasPermissionForProfile($actor, CorePermissions::CALENDAR_MANAGE, $tenantId));
        if (!$may) {
            throw new TimeOffRejected(TimeOffRejected::FORBIDDEN, "Only someone who manages the calendar can change another person's time off.");
        }
    }

    private static function interval(string $start, string $end): Interval
    {
        $from = Interval::fromApi($start);
        $to = Interval::fromApi($end);
        if ($from === null || $to === null || $to <= $from || $to->getTimestamp() - $from->getTimestamp() > self::MAX_SECONDS) {
            throw new TimeOffRejected(TimeOffRejected::INVALID_TIMES, 'Times need an offset or Z, the end after the start, and at most a year between them.');
        }

        return new Interval($from, $to);
    }

    /**
     * @param array{id: int, profile_id: int, interval: Interval, note: string, external_ref: ?string} $row
     * @return array{id: int, profileId: int, startUtc: string, endUtc: string, reason: string, externalRef: ?string}
     */
    private static function present(array $row): array
    {
        return [
            'id' => $row['id'],
            'profileId' => $row['profile_id'],
            'startUtc' => Interval::toApi($row['interval']->start),
            'endUtc' => Interval::toApi($row['interval']->end),
            'reason' => $row['note'],
            'externalRef' => $row['external_ref'],
        ];
    }
}
