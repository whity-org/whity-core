<?php

declare(strict_types=1);

namespace Whity\Api;

use DateTimeImmutable;
use Whity\Auth\RoleChecker;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarItems;
use Whity\Core\Taskaat\TaskDueDates;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\CalendarSettings;
use Whity\Core\Calendar\EventPlace;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\RoomRepository;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\i18n\LocalizedTextRejected;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Mcp\Auth\McpPrincipal;

/**
 * The staff calendar API (WHIT-941, ADR 0017).
 *
 * PERMISSIONS
 * Reading on `calendar:read`. Booking, moving and cancelling customer
 * bookings, and keeping one's OWN events, hours and time off, on
 * `calendar:write`. Services, settings, the staff list, and anybody else's
 * events, hours or time off on `calendar:manage`, which the handler checks
 * itself when the target person is not the caller.
 *
 * AGENTS
 * Create, move and cancel declare no undo window, so an agent's call is
 * always Ask first: it becomes a proposal the person approves, and approval
 * runs the call again under the lock (owner decision 11, ADR 0017 section 9).
 *
 * Ids from another workspace answer 404, never 403.
 */
final class CalendarApiHandler
{
    /** The most people one event invites (ADR 0018). */
    public const MAX_EVENT_PEOPLE = 200;

    private ?TaskDueDates $taskDueDates = null;

    private const MAX_RANGE_DAYS = 62;
    /** Year asks for a whole year; a leap year and a clock change fit. */
    private const MAX_COUNT_DAYS = 367;

    public function __construct(
        private readonly CalendarRepository $calendar,
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookings,
        private readonly BookingService $booking,
        private readonly SettingsService $settings,
        private readonly RoleChecker $roleChecker,
        private readonly ?\Whity\Core\Calendar\CalendarFeed $feed = null,
        private readonly string $feedUrlBase = '',
        private ?RoomRepository $rooms = null,
    ) {
        $this->rooms ??= new RoomRepository($this->calendar->db());
    }

    /**
     * Turn on a person's ICS feed, or replace its URL (the old one stops
     * working). The URL is shown once.
     *
     * @param array<string, string> $params
     */
    public function enableFeed(Request $request, array $params): Response
    {
        return $this->run($request, 'feed', function (int $tenantId, int $actor) use ($params): Response {
            $profileId = $this->staffOr404($tenantId, (int) $params['profileId']);
            $this->assertOwnOrManage($tenantId, $actor, $profileId);
            if ($this->feed === null) {
                throw new CalendarRejectedException('Calendar feeds are not available here', 503);
            }
            $token = $this->feed->issue($tenantId, $profileId);

            return Response::json(['data' => ['url' => rtrim($this->feedUrlBase, '/') . '/api/v1/public/calendar/feeds/' . $token . '.ics']], 201);
        });
    }

    /** @param array<string, string> $params */
    public function disableFeed(Request $request, array $params): Response
    {
        return $this->run($request, 'feed', function (int $tenantId, int $actor) use ($params): Response {
            $profileId = $this->staffOr404($tenantId, (int) $params['profileId']);
            $this->assertOwnOrManage($tenantId, $actor, $profileId);
            $this->feed?->revoke($tenantId, $profileId);

            return Response::json(['data' => ['url' => null]]);
        });
    }

    // ── settings ────────────────────────────────────────────────────────

    /**
     * What the caller last chose on the calendar here (design 3.5, CV.2,
     * CV.4, CV.7): the view, Colour by, unticked people and rooms, Also show.
     * Only ever the caller's own.
     */
    public function getMyView(Request $request): Response
    {
        return $this->run($request, 'view', function (int $tenantId, int $actor): Response {
            return Response::json(['data' => (object) $this->viewPreferences()->get($tenantId, $actor)]);
        });
    }

    /** Change the caller's own choices; keys left out keep their value, null forgets one. */
    public function putMyView(Request $request): Response
    {
        return $this->run($request, 'view', function (int $tenantId, int $actor) use ($request): Response {
            if ($actor === 0) {
                throw new CalendarRejectedException('Only a signed-in person has calendar choices', 422);
            }

            return Response::json(['data' => (object) $this->viewPreferences()->update($tenantId, $actor, JsonBody::parsed($request))]);
        });
    }

    private function viewPreferences(): \Whity\Core\Calendar\ViewPreferences
    {
        return new \Whity\Core\Calendar\ViewPreferences($this->calendar->db());
    }

    public function getSettings(Request $request): Response
    {
        return $this->run($request, 'settings', function (int $tenantId): Response {
            return Response::json(['data' => $this->booking->settingsFor($tenantId)->toApi()]);
        });
    }

    public function putSettings(Request $request): Response
    {
        return $this->run($request, 'settings', function (int $tenantId) use ($request): Response {
            $body = JsonBody::parsed($request);
            $map = [
                'booking_page_enabled' => SettingsRegistry::CALENDAR_BOOKING_PAGE_ENABLED,
                'min_notice_minutes' => SettingsRegistry::CALENDAR_MIN_NOTICE_MINUTES,
                'max_advance_days' => SettingsRegistry::CALENDAR_MAX_ADVANCE_DAYS,
                'slot_step_minutes' => SettingsRegistry::CALENDAR_SLOT_STEP_MINUTES,
                'reminder_hours_before' => SettingsRegistry::CALENDAR_REMINDER_HOURS_BEFORE,
                'payment_hold_minutes' => SettingsRegistry::CALENDAR_PAYMENT_HOLD_MINUTES,
                'week_start' => SettingsRegistry::CALENDAR_WEEK_START,
                'weekend_days' => SettingsRegistry::CALENDAR_WEEKEND_DAYS,
                'instant_booking' => SettingsRegistry::CALENDAR_INSTANT_BOOKING,
            ];
            $writes = [];
            foreach ($map as $field => $key) {
                if (!array_key_exists($field, $body)) {
                    continue;
                }
                $raw = $body[$field];
                $value = match (true) {
                    is_bool($raw) => $raw ? 'true' : 'false',
                    is_int($raw) => (string) $raw,
                    is_array($raw) => implode(',', array_map('strval', $raw)),
                    is_string($raw) => $raw,
                    default => null,
                };
                $error = $value === null ? "{$field} has the wrong type" : SettingsRegistry::validate($key, $value);
                if ($error !== null) {
                    return Response::error($error, 422, ['field' => $field]);
                }
                $writes[$key] = $value;
            }
            foreach ($writes as $key => $value) {
                $this->settings->setTenant($tenantId, $key, $value);
            }

            return Response::json(['data' => $this->booking->settingsFor($tenantId)->toApi()]);
        });
    }

    // ── staff, hours, time off ──────────────────────────────────────────

    public function listStaff(Request $request): Response
    {
        return $this->run($request, 'staff', fn (int $tenantId): Response => Response::json(['data' => $this->calendar->listStaff($tenantId)]));
    }

    /** @param array<string, string> $params */
    public function putStaff(Request $request, array $params): Response
    {
        return $this->run($request, 'staff', function (int $tenantId) use ($request, $params): Response {
            $profileId = (int) $params['profileId'];
            if (!$this->calendar->isStaff($tenantId, $profileId)) {
                throw CalendarRejectedException::notFound('Staff member');
            }
            $body = JsonBody::parsed($request);
            $bookable = isset($body['bookable']) && is_bool($body['bookable']) ? $body['bookable'] : null;
            $sort = isset($body['sort_order']) && is_int($body['sort_order']) ? $body['sort_order'] : null;
            $this->calendar->updateStaff($tenantId, $profileId, $bookable, $sort);
            // The branch they work in (design 3.3); null takes them out of every branch.
            if (array_key_exists('unit_id', $body)) {
                $this->calendar->setStaffUnit($tenantId, $profileId, $this->unitOrNull($tenantId, $body['unit_id']));
            }

            return Response::json(['data' => $this->calendar->listStaff($tenantId)]);
        });
    }

    /** @param array<string, string> $params */
    public function getHours(Request $request, array $params): Response
    {
        return $this->run($request, 'hours', function (int $tenantId) use ($params): Response {
            $profileId = $this->staffOr404($tenantId, (int) $params['profileId']);

            return Response::json(['data' => $this->calendar->hours($tenantId, $profileId)]);
        });
    }

    /** @param array<string, string> $params */
    public function putHours(Request $request, array $params): Response
    {
        return $this->run($request, 'hours', function (int $tenantId, int $actor) use ($request, $params): Response {
            $profileId = $this->staffOr404($tenantId, (int) $params['profileId']);
            $this->assertOwnOrManage($tenantId, $actor, $profileId);
            $body = JsonBody::parsed($request);
            $rows = [];
            foreach (is_array($body['hours'] ?? null) ? $body['hours'] : [] as $i => $row) {
                $weekday = is_array($row) ? ($row['weekday'] ?? null) : null;
                $start = is_array($row) ? ($row['start_time'] ?? null) : null;
                $end = is_array($row) ? ($row['end_time'] ?? null) : null;
                if (!is_int($weekday) || $weekday < 1 || $weekday > 7
                    || !is_string($start) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) !== 1
                    || !is_string($end) || preg_match('/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/', $end) !== 1
                    || strcmp($start, $end) >= 0) {
                    return Response::error("hours[{$i}] needs weekday 1-7 and start_time before end_time as HH:MM", 422);
                }
                $rows[] = ['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end];
            }
            if (count($rows) > 70) {
                return Response::error('Too many working-hours rows', 422);
            }
            $this->calendar->ensureStaff($tenantId, $profileId);
            $this->calendar->replaceHours($tenantId, $profileId, $rows);

            return Response::json(['data' => $this->calendar->hours($tenantId, $profileId)]);
        });
    }

    public function listExceptions(Request $request): Response
    {
        return $this->run($request, 'exceptions', function (int $tenantId) use ($request): Response {
            [$from, $to] = $this->range($request);
            $profileId = $this->optionalInt($request, 'profile_id');
            $rows = $this->calendar->exceptions($tenantId, $profileId, $from, $to);

            return Response::json(['data' => array_map(static fn (array $r): array => [
                'id' => $r['id'],
                'profile_id' => $r['profile_id'],
                'kind' => $r['kind'],
                'starts_at' => Interval::toApi($r['interval']->start),
                'ends_at' => Interval::toApi($r['interval']->end),
                'note' => $r['note'],
            ], $rows)]);
        });
    }

    public function createException(Request $request): Response
    {
        return $this->run($request, 'exceptions', function (int $tenantId, int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $profileId = $this->staffOr404($tenantId, is_int($body['profile_id'] ?? null) ? $body['profile_id'] : $actor);
            $this->assertOwnOrManage($tenantId, $actor, $profileId);
            $kind = $body['kind'] ?? 'off';
            if ($kind !== 'off' && $kind !== 'open') {
                return Response::error("kind must be 'off' or 'open'", 422);
            }
            $when = $this->interval($body);
            $note = is_string($body['note'] ?? null) ? mb_substr(trim($body['note']), 0, 500) : '';
            $this->calendar->ensureStaff($tenantId, $profileId);
            $id = $this->calendar->addException($tenantId, $profileId, $kind, $when, $note, $actor);

            return Response::json(['data' => [
                'id' => $id, 'profile_id' => $profileId, 'kind' => $kind,
                'starts_at' => Interval::toApi($when->start), 'ends_at' => Interval::toApi($when->end), 'note' => $note,
            ]], 201);
        });
    }

    /** @param array<string, string> $params */
    public function deleteException(Request $request, array $params): Response
    {
        return $this->run($request, 'exceptions', function (int $tenantId, int $actor) use ($params): Response {
            $row = $this->calendar->findException($tenantId, (int) $params['id']) ?? throw CalendarRejectedException::notFound('Time off');
            $this->assertOwnOrManage($tenantId, $actor, $row['profile_id']);
            $this->calendar->deleteException($tenantId, $row['id']);

            return Response::json(['data' => ['id' => $row['id'], 'deleted' => true]]);
        });
    }

    // ── rooms (design 3.3) ──────────────────────────────────────────────

    public function listRooms(Request $request): Response
    {
        return $this->run($request, 'rooms', fn (int $tenantId): Response => Response::json(['data' => $this->roomRepo()->list($tenantId)]));
    }

    public function createRoom(Request $request): Response
    {
        return $this->run($request, 'rooms', function (int $tenantId) use ($request): Response {
            $id = $this->roomRepo()->insert($tenantId, $this->roomValues($tenantId, JsonBody::parsed($request), null));

            return Response::json(['data' => $this->roomRepo()->find($tenantId, $id)], 201);
        });
    }

    /** @param array<string, string> $params */
    public function updateRoom(Request $request, array $params): Response
    {
        return $this->run($request, 'rooms', function (int $tenantId) use ($request, $params): Response {
            $existing = $this->roomRepo()->find($tenantId, (int) $params['id']) ?? throw CalendarRejectedException::notFound('Room');
            $this->roomRepo()->update($tenantId, $existing['id'], $this->roomValues($tenantId, JsonBody::parsed($request), $existing));

            return Response::json(['data' => $this->roomRepo()->find($tenantId, $existing['id'])]);
        });
    }

    // ── services ────────────────────────────────────────────────────────

    public function listServices(Request $request): Response
    {
        return $this->run($request, 'services', fn (int $tenantId): Response => Response::json(['data' => $this->services->list($tenantId)]));
    }

    /** @param array<string, string> $params */
    public function showService(Request $request, array $params): Response
    {
        return $this->run($request, 'services', function (int $tenantId) use ($params): Response {
            $service = $this->services->find($tenantId, (int) $params['id']) ?? throw CalendarRejectedException::notFound('Service');

            return Response::json(['data' => $service]);
        });
    }

    public function createService(Request $request): Response
    {
        return $this->run($request, 'services', function (int $tenantId) use ($request): Response {
            $body = JsonBody::parsed($request);
            $values = $this->serviceValues($tenantId, $body, null);
            $id = $this->services->insert($tenantId, $values);
            $this->services->replaceStaff($tenantId, $id, $values['staff_profile_ids']);
            foreach ($values['staff_profile_ids'] as $profileId) {
                $this->calendar->ensureStaff($tenantId, $profileId);
            }

            return Response::json(['data' => $this->services->find($tenantId, $id)], 201);
        });
    }

    /** @param array<string, string> $params */
    public function updateService(Request $request, array $params): Response
    {
        return $this->run($request, 'services', function (int $tenantId) use ($request, $params): Response {
            $existing = $this->services->find($tenantId, (int) $params['id']) ?? throw CalendarRejectedException::notFound('Service');
            $values = $this->serviceValues($tenantId, JsonBody::parsed($request), $existing);
            $this->services->update($tenantId, $existing['id'], $values);
            $this->services->replaceStaff($tenantId, $existing['id'], $values['staff_profile_ids']);
            foreach ($values['staff_profile_ids'] as $profileId) {
                $this->calendar->ensureStaff($tenantId, $profileId);
            }

            return Response::json(['data' => $this->services->find($tenantId, $existing['id'])]);
        });
    }

    // ── availability and bookings ───────────────────────────────────────

    public function availability(Request $request): Response
    {
        return $this->run($request, 'availability', function (int $tenantId) use ($request): Response {
            $serviceId = $this->optionalInt($request, 'service_id') ?? throw new CalendarRejectedException('service_id is required', 422);
            [$from, $to] = $this->range($request);

            return Response::json(['data' => $this->booking->availability($tenantId, $serviceId, $this->optionalInt($request, 'profile_id'), $from, $to)]);
        });
    }

    public function listBookings(Request $request): Response
    {
        return $this->run($request, 'bookings', function (int $tenantId) use ($request): Response {
            [$from, $to] = $this->range($request);
            $profileId = $this->optionalInt($request, 'profile_id');
            $statuses = array_values(array_filter(explode(',', $this->query($request)['status'] ?? ''), static fn (string $s): bool => in_array($s, [
                BookingRepository::STATUS_CONFIRMED, BookingRepository::STATUS_PENDING_PAYMENT,
                BookingRepository::STATUS_CANCELLED, BookingRepository::STATUS_EXPIRED,
                BookingRepository::STATUS_REQUESTED,
            ], true)));
            $rows = $this->bookings->list($tenantId, $profileId !== null ? [$profileId] : [], $from, $to, $statuses, $this->optionalInt($request, 'unit_id'));

            return Response::json(['data' => array_map([BookingRepository::class, 'toApi'], $rows)]);
        });
    }

    /**
     * How many live bookings each day has, by person, room and service, for
     * up to a year at a time (design 3.5, CV.7: the Year view). Days are the
     * workspace's calendar dates.
     */
    public function dayCounts(Request $request): Response
    {
        return $this->run($request, 'bookings', function (int $tenantId) use ($request): Response {
            $q = $this->query($request);
            $from = Interval::fromApi($q['from'] ?? null);
            $to = Interval::fromApi($q['to'] ?? null);
            if ($from === null || $to === null || $to <= $from) {
                throw new CalendarRejectedException('from and to are required, as dates and times with an offset, and from must be before to', 422);
            }
            if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_COUNT_DAYS * 86400) {
                throw new CalendarRejectedException('Ask for at most ' . self::MAX_COUNT_DAYS . ' days at a time', 422);
            }

            return Response::json(['data' => $this->bookings->dayCounts($tenantId, $from, $to, $this->booking->settingsFor($tenantId)->zone)]);
        });
    }

    /** @param array<string, string> $params */
    public function showBooking(Request $request, array $params): Response
    {
        return $this->run($request, 'bookings', fn (int $tenantId): Response => Response::json(['data' => $this->booking->bookingOrFail($tenantId, (int) $params['id'])]));
    }

    public function createBooking(Request $request): Response
    {
        return $this->run($request, 'bookings', function (int $tenantId, int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $serviceId = is_int($body['service_id'] ?? null) ? $body['service_id'] : throw new CalendarRejectedException('service_id is required', 422);
            $start = Interval::fromApi($body['starts_at'] ?? null) ?? throw new CalendarRejectedException('starts_at must be a date and time with an offset, such as 2026-11-16T09:00:00+03:00', 422);
            $profileId = is_int($body['profile_id'] ?? null) ? $body['profile_id'] : null;
            $customer = $this->customer($tenantId, $body);
            $isAgent = self::isAgent($request);
            $booking = $this->booking->book($tenantId, [
                'service_id' => $serviceId,
                'profile_id' => $profileId,
                'starts_at' => $start,
                'customer_profile_id' => $customer['profile_id'],
                'customer_name' => $customer['name'],
                'customer_email' => $customer['email'],
                'customer_phone' => $customer['phone'],
                'customer_note' => $customer['note'],
                'source' => $isAgent ? BookingRepository::SOURCE_AGENT : BookingRepository::SOURCE_STAFF,
                'created_by_profile_id' => $actor,
                // A person at the desk may book outside the published hours;
                // an agent keeps to them.
                'enforce_hours' => $isAgent || ($body['keep_to_hours'] ?? false) === true,
                'public' => false,
            ] + self::gridOptions($body, $isAgent));

            unset($booking['manage_token']);

            return Response::json(['data' => $booking], 201);
        });
    }

    /** @param array<string, string> $params */
    public function moveBooking(Request $request, array $params): Response
    {
        return $this->run($request, 'bookings', function (int $tenantId) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $start = Interval::fromApi($body['starts_at'] ?? null) ?? throw new CalendarRejectedException('starts_at must be a date and time with an offset', 422);
            $profileId = is_int($body['profile_id'] ?? null) ? $body['profile_id'] : null;
            $isAgent = self::isAgent($request);
            $options = self::gridOptions($body, $isAgent);
            $booking = $this->booking->move(
                $tenantId,
                (int) $params['id'],
                $start,
                $profileId,
                $isAgent || ($body['keep_to_hours'] ?? false) === true,
                $options['notify'],
                $options['book_anyway'],
                $options['within_hours'],
            );
            // The customer's new link goes to the customer, by email, not to staff.
            unset($booking['manage_token']);

            return Response::json(['data' => $booking]);
        });
    }

    /** @param array<string, string> $params */
    public function cancelBooking(Request $request, array $params): Response
    {
        return $this->run($request, 'bookings', function (int $tenantId) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';

            return Response::json(['data' => $this->booking->cancel($tenantId, (int) $params['id'], 'staff', $reason)]);
        });
    }

    /**
     * Accept a customer's request (design 3.3).
     *
     * @param array<string, string> $params
     */
    public function acceptBooking(Request $request, array $params): Response
    {
        return $this->run($request, 'bookings', function (int $tenantId) use ($params): Response {
            $booking = $this->booking->accept($tenantId, (int) $params['id']);
            unset($booking['manage_token']);

            return Response::json(['data' => $booking]);
        });
    }

    /**
     * Suggest another time for a customer's request (design 3.3).
     *
     * @param array<string, string> $params
     */
    public function suggestBooking(Request $request, array $params): Response
    {
        return $this->run($request, 'bookings', function (int $tenantId) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $start = Interval::fromApi($body['starts_at'] ?? null) ?? throw new CalendarRejectedException('starts_at must be a date and time with an offset', 422);
            $profileId = is_int($body['profile_id'] ?? null) ? $body['profile_id'] : null;
            $booking = $this->booking->suggest($tenantId, (int) $params['id'], $start, $profileId);
            unset($booking['manage_token']);

            return Response::json(['data' => $booking]);
        });
    }

    /**
     * The customer arrived (POST) or did not after all (DELETE).
     *
     * @param array<string, string> $params
     */
    public function checkIn(Request $request, array $params): Response
    {
        return $this->run($request, 'bookings', fn (int $tenantId): Response => Response::json(['data' => $this->booking->checkIn($tenantId, (int) $params['id'], $request->getMethod() !== 'DELETE')]));
    }

    /** The Today panel: booked, checked in, open requests and reminders sent (design 3.3). */
    public function today(Request $request): Response
    {
        return $this->run($request, 'bookings', function (int $tenantId) use ($request): Response {
            $date = $this->query($request)['date'] ?? null;

            return Response::json(['data' => $this->booking->today($tenantId, is_string($date) && $date !== '' ? $date : null, $this->optionalInt($request, 'unit_id'))]);
        });
    }

    // ── events ──────────────────────────────────────────────────────────

    /**
     * GET /api/calendar/items?from&to[&people=1,2][&kinds=booking,meeting][&cancelled=1][&unit_id]
     *
     * Everything on the calendar in one list with one shape (ADR 0018):
     * bookings, events, meetings, time off and synced events, each with a
     * `kind`, an `id` of the form "booking:7", who is invited and what the
     * viewer may do with it (`can`). Someone else's time off reads "Away" and
     * their synced events "Busy".
     */
    public function items(Request $request): Response
    {
        return $this->run($request, 'items', function (int $tenantId, int $actor) use ($request): Response {
            [$from, $to] = $this->range($request);
            $q = $this->query($request);
            $people = self::idList($q['people'] ?? '', 'people');
            $kinds = array_values(array_filter(explode(',', $q['kinds'] ?? ''), static fn (string $k): bool => $k !== ''));
            $allowed = [...CalendarItems::KINDS, TaskDueDates::KIND];
            foreach ($kinds as $kind) {
                if (!in_array($kind, $allowed, true)) {
                    throw new CalendarRejectedException('kinds are ' . implode(', ', $allowed), 422);
                }
            }
            // Tasks due come from Taskaat only when asked for by name (Also show,
            // off by default) and only for someone who may read tasks.
            $withTasks = in_array(TaskDueDates::KIND, $kinds, true);
            $kinds = array_values(array_diff($kinds, [TaskDueDates::KIND]));
            $manages = $actor > 0 && $this->roleChecker->hasPermissionForProfile($actor, CorePermissions::CALENDAR_MANAGE, $tenantId);
            // Asking for tasks due alone reads nothing else.
            $items = $withTasks && $kinds === [] ? [] : (new CalendarItems($this->bookings, $this->calendar))->between(
                $tenantId,
                $actor,
                $from,
                $to,
                $people,
                $kinds,
                ($q['cancelled'] ?? '') === '1',
                $manages,
                $this->optionalInt($request, 'unit_id'),
            );
            $unavailable = [];
            if ($withTasks && $this->taskDueDates !== null && $actor > 0
                && $this->roleChecker->hasPermissionForProfile($actor, CorePermissions::TASKS_READ, $tenantId)) {
                $zone = CalendarSettings::fromSettings($this->settings->effective($tenantId))->zone;
                $tasks = $this->taskDueDates->between($tenantId, $from, $to, $zone, $people);
                if ($tasks === null) {
                    $unavailable[] = TaskDueDates::KIND;
                } else {
                    $items = [...$items, ...$tasks];
                    usort($items, static fn (array $a, array $b): int => [$a['starts_at'], $a['id']] <=> [$b['starts_at'], $b['id']]);
                }
            }

            return Response::json(['data' => $items, 'unavailable' => $unavailable]);
        });
    }

    /** ADR 0018: tasks due on the calendar, read from Taskaat. */
    public function setTaskDueDates(TaskDueDates $taskDueDates): void
    {
        $this->taskDueDates = $taskDueDates;
    }

    /** @return list<int> */
    private static function idList(string $raw, string $name): array
    {
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            if (preg_match('/^\d+$/', $part) !== 1) {
                throw new CalendarRejectedException("{$name} is a comma-separated list of ids", 422);
            }
            $ids[] = (int) $part;
        }
        if (count($ids) > self::MAX_EVENT_PEOPLE) {
            throw new CalendarRejectedException("Ask for at most " . self::MAX_EVENT_PEOPLE . " {$name} at a time", 422);
        }

        return array_values(array_unique($ids));
    }

    public function listEvents(Request $request): Response
    {
        return $this->run($request, 'events', function (int $tenantId) use ($request): Response {
            [$from, $to] = $this->range($request);
            $profileId = $this->optionalInt($request, 'profile_id');

            // Time off and extra hours have their own route; meetings arrive with T4.
            return Response::json(['data' => $this->calendar->listEvents($tenantId, $profileId !== null ? [$profileId] : [], $from, $to, [
                CalendarRepository::KIND_EVENT, CalendarRepository::KIND_EXTERNAL, CalendarRepository::KIND_MEETING,
            ])]);
        });
    }

    public function createEvent(Request $request): Response
    {
        return $this->run($request, 'events', function (int $tenantId, int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $profileId = $this->staffOr404($tenantId, is_int($body['profile_id'] ?? null) ? $body['profile_id'] : $actor);
            $this->assertOwnOrManage($tenantId, $actor, $profileId);
            [$title, $notes, $when, $allDay, $busy] = $this->eventValues($body);
            $result = $this->booking->createEvent(
                $tenantId, $profileId, $title, $notes, $when, $allDay, $busy, $actor,
                place: $this->eventPlace($tenantId, $body) ?? new EventPlace(),
                people: $this->eventPeople($tenantId, $body),
            );

            return Response::json(['data' => $result['event'], 'overlapping_bookings' => $result['overlapping_bookings']], 201);
        });
    }

    /** @param array<string, string> $params */
    public function updateEvent(Request $request, array $params): Response
    {
        return $this->run($request, 'events', function (int $tenantId, int $actor) use ($request, $params): Response {
            $existing = $this->calendar->findEvent($tenantId, (int) $params['id']) ?? throw CalendarRejectedException::notFound('Event');
            self::assertNotAMeeting($existing);
            $this->assertOwnOrManage($tenantId, $actor, (int) $existing['profile_id']);
            $body = JsonBody::parsed($request);
            [$title, $notes, $when, $allDay, $busy] = $this->eventValues($body + [
                'title' => $existing['title'], 'notes' => $existing['notes'],
                'starts_at' => $existing['starts_at'], 'ends_at' => $existing['ends_at'],
                'all_day' => $existing['all_day'], 'busy' => $existing['busy'],
            ]);
            $result = $this->booking->updateEvent(
                $tenantId, (int) $existing['id'], $title, $notes, $when, $allDay, $busy,
                place: $this->eventPlace($tenantId, $body + ['room_id' => $existing['room_id'], 'location' => $existing['location']], array_key_exists('room_id', $body) || array_key_exists('location', $body)),
                people: $this->eventPeople($tenantId, $body),
            );

            return Response::json(['data' => $result['event'], 'overlapping_bookings' => $result['overlapping_bookings']]);
        });
    }

    /** @param array<string, string> $params */
    public function deleteEvent(Request $request, array $params): Response
    {
        return $this->run($request, 'events', function (int $tenantId, int $actor) use ($params): Response {
            $existing = $this->calendar->findEvent($tenantId, (int) $params['id']) ?? throw CalendarRejectedException::notFound('Event');
            self::assertNotAMeeting($existing);
            $this->assertOwnOrManage($tenantId, $actor, (int) $existing['profile_id']);
            $this->booking->cancelEvent($tenantId, (int) $existing['id']);

            return Response::json(['data' => ['id' => (int) $existing['id'], 'deleted' => true]]);
        });
    }

    /**
     * POST /api/calendar/events/{id}/answer `{ "response": "accepted" }`: an
     * invited person says whether they'll come (ADR 0018). Declining frees
     * their time. Only someone invited can answer, and only for themselves.
     *
     * @param array<string, string> $params
     */
    public function answerEvent(Request $request, array $params): Response
    {
        return $this->run($request, 'events', function (int $tenantId, int $actor) use ($request, $params): Response {
            $response = JsonBody::parsed($request)['response'] ?? null;
            if (!is_string($response) || !in_array($response, ['accepted', 'declined', 'tentative'], true)) {
                throw new CalendarRejectedException('response is accepted, declined or tentative', 422);
            }
            $existing = $this->calendar->findEvent($tenantId, (int) $params['id']) ?? throw CalendarRejectedException::notFound('Event');
            if ($actor <= 0 || !$this->calendar->answerEvent($tenantId, (int) $existing['id'], $actor, $response)) {
                throw CalendarRejectedException::notFound('Invitation');
            }

            return Response::json(['data' => $this->calendar->findEvent($tenantId, (int) $existing['id'])]);
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * A formal meeting is changed on the meeting (its date, place and guests
     * are the meeting's record, ADR 0018), never through the events API.
     *
     * @param array<string, mixed> $event
     */
    private static function assertNotAMeeting(array $event): void
    {
        if (($event['kind'] ?? null) === CalendarRepository::KIND_MEETING) {
            throw new CalendarRejectedException('This is a meeting. Change its date, place or guests on the meeting itself.', 409, ['code' => 'meeting']);
        }
    }

    /**
     * Where an event happens, from `room_id` and `location` (ADR 0018). Null
     * when the body says nothing about it and `$required` is false.
     *
     * @param array<string, mixed> $body
     */
    private function eventPlace(int $tenantId, array $body, bool $required = true): ?EventPlace
    {
        if (!$required) {
            return null;
        }
        $roomId = $body['room_id'] ?? null;
        if ($roomId !== null && (!is_int($roomId) || $this->roomRepo()->find($tenantId, $roomId) === null)) {
            throw CalendarRejectedException::notFound('Room');
        }
        $location = $body['location'] ?? '';
        if (!is_string($location) || mb_strlen($location) > EventPlace::LOCATION_MAX) {
            throw new CalendarRejectedException('location is text, up to ' . EventPlace::LOCATION_MAX . ' characters', 422);
        }

        return new EventPlace($roomId, trim($location));
    }

    /**
     * Who is invited, from `people` (staff of this workspace), or null when
     * the body doesn't say.
     *
     * @param array<string, mixed> $body
     * @return list<int>|null
     */
    private function eventPeople(int $tenantId, array $body): ?array
    {
        if (!array_key_exists('people', $body)) {
            return null;
        }
        $raw = $body['people'];
        if (!is_array($raw) || count($raw) > self::MAX_EVENT_PEOPLE) {
            throw new CalendarRejectedException('people is a list of up to ' . self::MAX_EVENT_PEOPLE . ' people', 422);
        }
        $people = [];
        foreach ($raw as $id) {
            if (!is_int($id)) {
                throw new CalendarRejectedException('people is a list of people\'s ids', 422);
            }
            $people[] = $this->staffOr404($tenantId, $id);
        }

        return array_values(array_unique($people));
    }

    /** @param callable(int, int): Response $work */
    private function run(Request $request, string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : 0;

            return $work($tenantId, $actor);
        } catch (CalendarRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (FormRejectedException | LocalizedTextRejected $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Throwable $e) {
            error_log('[CalendarApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Failed to update the calendar', 500);
        }
    }

    /**
     * What the calendar grid adds to a create or a move (design 3.5, CV.8,
     * CV.9): "Email the customer" (default on), Book anyway when the person is
     * busy, and keeping to the person's hours. An agent never books over
     * someone's time. A booking's length is its service's (owner, Q35.7), so
     * a length of its own is refused rather than ignored.
     *
     * @param array<string, mixed> $body
     * @return array{notify: bool, book_anyway: bool, within_hours: bool}
     */
    private static function gridOptions(array $body, bool $isAgent): array
    {
        if (array_key_exists('duration_minutes', $body)) {
            throw new CalendarRejectedException('A booking lasts as long as its service. Choose another service to change its length.', 422);
        }

        return [
            'notify' => ($body['notify'] ?? true) !== false,
            'book_anyway' => !$isAgent && ($body['book_anyway'] ?? false) === true,
            'within_hours' => ($body['within_hours'] ?? false) === true,
        ];
    }

    private static function isAgent(Request $request): bool
    {
        $user = $request->user;

        return is_object($user) && ($user->principal_kind ?? null) === McpPrincipal::KIND_AGENT;
    }

    private function assertOwnOrManage(int $tenantId, int $actor, int $profileId): void
    {
        if ($actor === $profileId) {
            return;
        }
        if ($actor <= 0 || !$this->roleChecker->hasPermissionForProfile($actor, CorePermissions::CALENDAR_MANAGE, $tenantId)) {
            throw new CalendarRejectedException('Only someone who manages the calendar can change another person\'s calendar.', 403, ['required' => CorePermissions::CALENDAR_MANAGE]);
        }
    }

    private function staffOr404(int $tenantId, int $profileId): int
    {
        if ($profileId <= 0 || !$this->calendar->isStaff($tenantId, $profileId)) {
            throw CalendarRejectedException::notFound('Staff member');
        }

        return $profileId;
    }

    private function roomRepo(): RoomRepository
    {
        return $this->rooms ?? new RoomRepository($this->calendar->db());
    }

    /** A branch id from the body: null, or an organisational unit of this workspace. */
    private function unitOrNull(int $tenantId, mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (!is_int($raw) || !$this->roomRepo()->isUnit($tenantId, $raw)) {
            throw CalendarRejectedException::notFound('Branch');
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $existing
     * @return array{name: array<string, string>, unit_id: ?int, active: bool, sort_order: int}
     */
    private function roomValues(int $tenantId, array $body, ?array $existing): array
    {
        $pick = static fn (string $k, mixed $default): mixed => array_key_exists($k, $body) ? $body[$k] : $default;
        $sort = $pick('sort_order', $existing['sort_order'] ?? 0);
        if (!is_int($sort) || $sort < 0 || $sort > 100000) {
            throw new CalendarRejectedException('sort_order must be a whole number from 0 to 100000', 422);
        }

        return [
            'name' => LocalizedText::label($pick('name', $existing['name'] ?? null), 'name'),
            'unit_id' => array_key_exists('unit_id', $body) ? $this->unitOrNull($tenantId, $body['unit_id']) : ($existing['unit_id'] ?? null),
            'active' => $pick('active', $existing['active'] ?? true) === true,
            'sort_order' => $sort,
        ];
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    private function range(Request $request): array
    {
        $q = $this->query($request);
        $from = Interval::fromApi($q['from'] ?? null);
        $to = Interval::fromApi($q['to'] ?? null);
        if ($from === null || $to === null || $to <= $from) {
            throw new CalendarRejectedException('from and to are required, as dates and times with an offset, and from must be before to', 422);
        }
        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_RANGE_DAYS * 86400) {
            throw new CalendarRejectedException('Ask for at most ' . self::MAX_RANGE_DAYS . ' days at a time', 422);
        }

        return [$from, $to];
    }

    private function optionalInt(Request $request, string $name): ?int
    {
        $raw = $this->query($request)[$name] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new CalendarRejectedException("{$name} must be a whole number", 422);
        }

        return (int) $raw;
    }

    /** @return array<string, string> */
    private function query(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $body */
    private function interval(array $body): Interval
    {
        $start = Interval::fromApi($body['starts_at'] ?? null);
        $end = Interval::fromApi($body['ends_at'] ?? null);
        if ($start === null || $end === null || $end <= $start) {
            throw new CalendarRejectedException('starts_at and ends_at are required, with an offset, and must be in order', 422);
        }
        if ($end->getTimestamp() - $start->getTimestamp() > 366 * 86400) {
            throw new CalendarRejectedException('That is longer than a year', 422);
        }

        return new Interval($start, $end);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: string, 2: Interval, 3: bool, 4: bool}
     */
    private function eventValues(array $body): array
    {
        $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
        if ($title === '' || mb_strlen($title) > 200) {
            throw new CalendarRejectedException('title is required, up to 200 characters', 422);
        }
        $notes = is_string($body['notes'] ?? null) ? mb_substr($body['notes'], 0, 5000) : '';

        return [$title, $notes, $this->interval($body), ($body['all_day'] ?? false) === true, ($body['busy'] ?? true) !== false];
    }

    /**
     * The customer on a staff booking: an existing customer or member by
     * profile id, or details typed at the desk.
     *
     * @param array<string, mixed> $body
     * @return array{profile_id: ?int, name: string, email: string, phone: string, note: string}
     */
    private function customer(int $tenantId, array $body): array
    {
        $c = is_array($body['customer'] ?? null) ? $body['customer'] : [];
        $profileId = is_int($c['profile_id'] ?? null) ? $c['profile_id'] : null;
        $name = is_string($c['name'] ?? null) ? trim($c['name']) : '';
        $email = is_string($c['email'] ?? null) ? strtolower(trim($c['email'])) : '';
        $phone = is_string($c['phone'] ?? null) ? trim($c['phone']) : '';
        $note = is_string($c['note'] ?? null) ? mb_substr(trim($c['note']), 0, 1000) : '';
        if ($profileId !== null) {
            if ($this->calendar->membershipKind($tenantId, $profileId) === null) {
                throw CalendarRejectedException::notFound('Customer');
            }
            if ($name === '') {
                $name = $this->calendar->displayName($profileId);
            }
        }
        if ($name === '' || mb_strlen($name) > 200) {
            throw new CalendarRejectedException('The customer\'s name is required, up to 200 characters', 422);
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CalendarRejectedException('The customer\'s email address does not look right', 422);
        }
        if ($phone !== '' && preg_match('/^\+?[0-9 ()-]{6,40}$/', $phone) !== 1) {
            throw new CalendarRejectedException('The customer\'s phone number does not look right', 422);
        }

        return ['profile_id' => $profileId, 'name' => $name, 'email' => $email, 'phone' => $phone, 'note' => $note];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function serviceValues(int $tenantId, array $body, ?array $existing): array
    {
        $pick = static fn (string $k, mixed $default): mixed => array_key_exists($k, $body) ? $body[$k] : $default;
        $name = LocalizedText::label($pick('name', $existing['name'] ?? null), 'name');
        $descriptionRaw = $pick('description', $existing['description'] ?? []);
        // A stored empty description reads back as `{}` (an empty object), so a
        // PATCH that leaves `description` out must treat that as empty too.
        if ($descriptionRaw instanceof \stdClass && get_object_vars($descriptionRaw) === []) {
            $descriptionRaw = [];
        }
        $description = $descriptionRaw === [] || $descriptionRaw === '' || $descriptionRaw === null ? [] : LocalizedText::label($descriptionRaw, 'description');
        $int = static function (string $k, mixed $v, int $min, int $max): int {
            if (!is_int($v) || $v < $min || $v > $max) {
                throw new CalendarRejectedException("{$k} must be a whole number from {$min} to {$max}", 422);
            }

            return $v;
        };
        $price = $pick('price_minor', $existing['price_minor'] ?? null);
        if ($price !== null && (!is_int($price) || $price < 0)) {
            throw new CalendarRejectedException('price_minor must be a whole number of minor units ($12.50 is 1250, JOD 12.500 is 12500), or null', 422);
        }
        $settings = CalendarSettings::fromSettings($this->settings->effective($tenantId));
        $currency = $pick('currency', $existing['currency'] ?? $settings->currency);
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new CalendarRejectedException('currency must be a three-letter code such as USD', 422);
        }
        $staff = $pick('staff_profile_ids', $existing['staff_profile_ids'] ?? []);
        if (!is_array($staff) || count($staff) > 200) {
            throw new CalendarRejectedException('staff_profile_ids must be a list of staff', 422);
        }
        $staffIds = [];
        foreach ($staff as $id) {
            if (!is_int($id) || !$this->calendar->isStaff($tenantId, $id)) {
                throw CalendarRejectedException::notFound('Staff member');
            }
            $staffIds[] = $id;
        }

        $room = $pick('room_id', $existing['room_id'] ?? null);
        if ($room !== null && (!is_int($room) || $this->roomRepo()->find($tenantId, $room) === null)) {
            throw CalendarRejectedException::notFound('Room');
        }

        return [
            'name' => $name,
            'description' => $description,
            'room_id' => $room,
            'needs_approval' => $pick('needs_approval', $existing['needs_approval'] ?? false) === true,
            'duration_minutes' => $int('duration_minutes', $pick('duration_minutes', $existing['duration_minutes'] ?? null), 5, 1440),
            'buffer_before_minutes' => $int('buffer_before_minutes', $pick('buffer_before_minutes', $existing['buffer_before_minutes'] ?? 0), 0, 240),
            'buffer_after_minutes' => $int('buffer_after_minutes', $pick('buffer_after_minutes', $existing['buffer_after_minutes'] ?? 0), 0, 240),
            'price_minor' => $price,
            'currency' => $currency,
            'bookable_online' => $pick('bookable_online', $existing['bookable_online'] ?? true) === true,
            'active' => $pick('active', $existing['active'] ?? true) === true,
            'sort_order' => $int('sort_order', $pick('sort_order', $existing['sort_order'] ?? 0), 0, 100000),
            'staff_profile_ids' => array_values(array_unique($staffIds)),
        ];
    }
}
