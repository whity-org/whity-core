<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;

/**
 * One calendar for everything with a time (ADR 0018): the read model behind
 * `GET /api/calendar/items`.
 *
 * Bookings keep their own table (a customer, a service, a payment hold and a
 * manage link are a customer-facing contract) and everything else is an event
 * with a kind. This joins the two into one list with one shape, so the grid,
 * the details, editing and the phone read items the same way whatever they
 * are. Free/busy uses the same two sources ({@see CalendarRepository::busy()}),
 * so what the calendar shows and what blocks a booking never disagree.
 *
 * Who sees what (owner, 16 Nov 2026): titles of what the viewer can open;
 * someone else's time off reads "Away" and their synced private events read
 * "Busy", with no notes.
 */
final class CalendarItems
{
    public const KIND_BOOKING = 'booking';
    public const KIND_TASK_DUE = 'task_due';

    /** Every kind an item can be. */
    public const KINDS = [
        self::KIND_BOOKING,
        CalendarRepository::KIND_EVENT,
        CalendarRepository::KIND_MEETING,
        CalendarRepository::KIND_TIME_OFF,
        CalendarRepository::KIND_EXTERNAL,
    ];

    /** What a colleague reads for someone else's time off and synced events. */
    public const AWAY = 'Away';
    public const BUSY = 'Busy';

    private const MOVABLE_BOOKINGS = [
        BookingRepository::STATUS_CONFIRMED,
        BookingRepository::STATUS_REQUESTED,
        BookingRepository::STATUS_PENDING_PAYMENT,
    ];

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly CalendarRepository $calendar,
    ) {
    }

    /**
     * The items touching `[from, to)`, oldest start first.
     *
     * @param list<int>    $profileIds whose calendars (empty = everyone)
     * @param list<string> $kinds      which kinds (empty = all)
     * @param bool         $withCancelled include cancelled bookings
     * @param bool         $viewerManages the viewer may change anyone's calendar
     * @return list<array<string, mixed>>
     */
    public function between(
        int $tenantId,
        int $viewerId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $profileIds = [],
        array $kinds = [],
        bool $withCancelled = false,
        bool $viewerManages = false,
        ?int $unitId = null,
    ): array {
        $wants = static fn (string $kind): bool => $kinds === [] || in_array($kind, $kinds, true);
        $items = [];

        if ($wants(self::KIND_BOOKING)) {
            $statuses = $withCancelled ? [...BookingRepository::LIVE, BookingRepository::STATUS_CANCELLED] : [];
            foreach ($this->bookings->list($tenantId, $profileIds, $from, $to, $statuses, $unitId) as $row) {
                foreach ($this->fromBookingRuns(BookingRepository::toApi($row), $viewerId, $viewerManages) as $item) {
                    if ($profileIds === [] || in_array($item['organiser_id'], $profileIds, true)) {
                        $items[] = $item;
                    }
                }
            }
        }

        $eventKinds = array_values(array_filter(
            [CalendarRepository::KIND_EVENT, CalendarRepository::KIND_MEETING, CalendarRepository::KIND_TIME_OFF, CalendarRepository::KIND_EXTERNAL],
            $wants,
        ));
        if ($eventKinds !== []) {
            foreach ($this->calendar->listEvents($tenantId, $profileIds, $from, $to) as $event) {
                if (in_array($event['kind'], $eventKinds, true)) {
                    $items[] = $this->fromEvent($event, $viewerId, $viewerManages);
                }
            }
        }

        usort($items, static fn (array $a, array $b): int => [$a['starts_at'], $a['id']] <=> [$b['starts_at'], $b['id']]);

        return $items;
    }

    /**
     * A booking as the grid draws it. A booking of several services
     * (WHIT-1173) done by more than one person is drawn once per person, for
     * the services they do back to back ("booking:7" for the first,
     * "booking:7:2" for the one starting at the third service), so each
     * person's column shows their own part. Its length is the services', so
     * it is never resized, and a split one moves from its details (Move), not
     * by dragging one part.
     *
     * @param array<string, mixed> $b a booking as {@see BookingRepository::toApi()} gives it
     * @return list<array<string, mixed>>
     */
    private function fromBookingRuns(array $b, int $viewerId, bool $viewerManages): array
    {
        /** @var list<array<string, mixed>> $parts */
        $parts = $b['items'] ?? [];
        if (count($parts) < 2) {
            return [$this->fromBooking($b, $viewerId, $viewerManages)];
        }
        $runs = [];
        foreach ($parts as $part) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][0]['profile_id'] === $part['profile_id']) {
                $runs[$last][] = $part;
            } else {
                $runs[] = [$part];
            }
        }
        $out = [];
        foreach ($runs as $n => $run) {
            $first = $run[0];
            $end = $run[count($run) - 1];
            // "Mine" is whoever does this part; the booking itself is unchanged.
            $item = $this->fromBooking(['profile_id' => $first['profile_id']] + $b, $viewerId, $viewerManages);
            $item['booking'] = $b;
            $names = BookingRepository::joinedNames($run);
            $item['title'] = $names['en'] ?? (string) (reset($names) ?: $item['title']);
            $item['starts_at'] = $first['starts_at'];
            $item['ends_at'] = $end['ends_at'];
            $item['organiser_id'] = (int) $first['profile_id'];
            $item['room_id'] = $first['room_id'];
            $item['can']['resize'] = false;
            if (count($runs) > 1) {
                $item['id'] = $n === 0 ? $item['id'] : self::KIND_BOOKING . ':' . $b['id'] . ':' . $first['position'];
                $item['can']['move'] = false;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $b a booking as {@see BookingRepository::toApi()} gives it
     * @return array<string, mixed>
     */
    private function fromBooking(array $b, int $viewerId, bool $viewerManages): array
    {
        $mine = (int) $b['profile_id'] === $viewerId;
        $changeable = ($mine || $viewerManages) && in_array($b['status'], self::MOVABLE_BOOKINGS, true);
        $service = $b['service_name'] ?? null;

        return [
            'id' => self::KIND_BOOKING . ':' . $b['id'],
            'kind' => self::KIND_BOOKING,
            'title' => is_array($service) ? ($service['en'] ?? (string) reset($service)) : (string) ($b['customer']['name'] ?? ''),
            'starts_at' => $b['starts_at'],
            'ends_at' => $b['ends_at'],
            'all_day' => false,
            'busy' => in_array($b['status'], BookingRepository::LIVE, true),
            'status' => $b['status'],
            'organiser_id' => (int) $b['profile_id'],
            'people' => [],
            'room_id' => $b['room_id'],
            'location' => '',
            'booking' => $b,
            'event' => null,
            'can' => ['open' => true, 'move' => $changeable, 'resize' => $changeable, 'cancel' => $changeable],
        ];
    }

    /**
     * An event as `GET /api/calendar/events` lists it (WHIT-1285, #2237):
     * another person's synced event or time off says only Busy or Away, with
     * no notes, place or link to where it came from, as on the grid.
     *
     * @param array<string, mixed> $e an event as {@see CalendarRepository::eventToApi()} gives it
     * @return array<string, mixed>
     */
    public static function redactedFor(array $e, int $viewerId): array
    {
        $kind = (string) ($e['kind'] ?? '');
        if ((int) ($e['profile_id'] ?? 0) === $viewerId) {
            return $e;
        }
        $e['external_ref'] = null;
        if (!in_array($kind, [CalendarRepository::KIND_TIME_OFF, CalendarRepository::KIND_EXTERNAL], true)) {
            return $e;
        }

        return ['title' => $kind === CalendarRepository::KIND_TIME_OFF ? self::AWAY : self::BUSY, 'notes' => '', 'location' => ''] + $e;
    }

    /**
     * @param array<string, mixed> $e an event as {@see CalendarRepository::eventToApi()} gives it, with people
     * @return array<string, mixed>
     */
    private function fromEvent(array $e, int $viewerId, bool $viewerManages): array
    {
        $kind = (string) $e['kind'];
        $mine = (int) $e['profile_id'] === $viewerId;
        $invited = in_array($viewerId, array_column($e['people'], 'profile_id'), true);
        $private = !$mine && in_array($kind, [CalendarRepository::KIND_TIME_OFF, CalendarRepository::KIND_EXTERNAL], true);
        $title = match (true) {
            $private && $kind === CalendarRepository::KIND_TIME_OFF => self::AWAY,
            $private => self::BUSY,
            default => (string) $e['title'],
        };
        // A synced event changes in the calendar it came from, and a meeting on
        // the meeting itself; neither moves here.
        $changeable = !in_array($kind, [CalendarRepository::KIND_EXTERNAL, CalendarRepository::KIND_MEETING], true) && ($mine || $viewerManages);

        return [
            'id' => $kind . ':' . $e['id'],
            'kind' => $kind,
            'title' => $title,
            'starts_at' => $e['starts_at'],
            'ends_at' => $e['ends_at'],
            'all_day' => $e['all_day'],
            'busy' => $e['busy'],
            'status' => $e['status'],
            'organiser_id' => (int) $e['profile_id'],
            'people' => $e['people'],
            'room_id' => $e['room_id'],
            'location' => $private ? '' : $e['location'],
            'booking' => null,
            'event' => [
                'id' => (int) $e['id'],
                'notes' => $private ? '' : $e['notes'],
                'meeting_id' => $e['meeting_id'],
                'external_ref' => $mine ? $e['external_ref'] : null,
            ],
            'can' => [
                'open' => !$private || $invited,
                'move' => $changeable,
                'resize' => $changeable && !$e['all_day'],
                'cancel' => $changeable,
            ],
        ];
    }
}
