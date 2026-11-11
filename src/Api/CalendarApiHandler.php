<?php

declare(strict_types=1);

namespace Whity\Api;

use DateTimeImmutable;
use Whity\Auth\RoleChecker;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\CalendarSettings;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\LocalizedLabel;
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
    private const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly CalendarRepository $calendar,
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookings,
        private readonly BookingService $booking,
        private readonly SettingsService $settings,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    // ── settings ────────────────────────────────────────────────────────

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
            ], true)));
            $rows = $this->bookings->list($tenantId, $profileId !== null ? [$profileId] : [], $from, $to, $statuses);

            return Response::json(['data' => array_map([BookingRepository::class, 'toApi'], $rows)]);
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
            ]);

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
            $booking = $this->booking->move($tenantId, (int) $params['id'], $start, $profileId, self::isAgent($request) || ($body['keep_to_hours'] ?? false) === true);

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

    // ── events ──────────────────────────────────────────────────────────

    public function listEvents(Request $request): Response
    {
        return $this->run($request, 'events', function (int $tenantId) use ($request): Response {
            [$from, $to] = $this->range($request);
            $profileId = $this->optionalInt($request, 'profile_id');

            return Response::json(['data' => $this->calendar->listEvents($tenantId, $profileId !== null ? [$profileId] : [], $from, $to)]);
        });
    }

    public function createEvent(Request $request): Response
    {
        return $this->run($request, 'events', function (int $tenantId, int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $profileId = $this->staffOr404($tenantId, is_int($body['profile_id'] ?? null) ? $body['profile_id'] : $actor);
            $this->assertOwnOrManage($tenantId, $actor, $profileId);
            [$title, $notes, $when, $allDay, $busy] = $this->eventValues($body);
            $result = $this->booking->createEvent($tenantId, $profileId, $title, $notes, $when, $allDay, $busy, $actor);

            return Response::json(['data' => $result['event'], 'overlapping_bookings' => $result['overlapping_bookings']], 201);
        });
    }

    /** @param array<string, string> $params */
    public function updateEvent(Request $request, array $params): Response
    {
        return $this->run($request, 'events', function (int $tenantId, int $actor) use ($request, $params): Response {
            $existing = $this->calendar->findEvent($tenantId, (int) $params['id']) ?? throw CalendarRejectedException::notFound('Event');
            $this->assertOwnOrManage($tenantId, $actor, (int) $existing['profile_id']);
            [$title, $notes, $when, $allDay, $busy] = $this->eventValues(JsonBody::parsed($request) + [
                'title' => $existing['title'], 'notes' => $existing['notes'],
                'starts_at' => $existing['starts_at'], 'ends_at' => $existing['ends_at'],
                'all_day' => $existing['all_day'], 'busy' => $existing['busy'],
            ]);
            $result = $this->booking->updateEvent($tenantId, (int) $existing['id'], $title, $notes, $when, $allDay, $busy);

            return Response::json(['data' => $result['event'], 'overlapping_bookings' => $result['overlapping_bookings']]);
        });
    }

    /** @param array<string, string> $params */
    public function deleteEvent(Request $request, array $params): Response
    {
        return $this->run($request, 'events', function (int $tenantId, int $actor) use ($params): Response {
            $existing = $this->calendar->findEvent($tenantId, (int) $params['id']) ?? throw CalendarRejectedException::notFound('Event');
            $this->assertOwnOrManage($tenantId, $actor, (int) $existing['profile_id']);
            $this->booking->cancelEvent($tenantId, (int) $existing['id']);

            return Response::json(['data' => ['id' => (int) $existing['id'], 'deleted' => true]]);
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────

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
        } catch (FormRejectedException $e) {
            return Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log('[CalendarApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Failed to update the calendar', 500);
        }
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
                $name = $this->displayName($profileId);
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

    private function displayName(int $profileId): string
    {
        $stmt = $this->calendar->db()->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $name = $stmt->fetchColumn();

        return is_string($name) ? $name : '';
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function serviceValues(int $tenantId, array $body, ?array $existing): array
    {
        $pick = static fn (string $k, mixed $default): mixed => array_key_exists($k, $body) ? $body[$k] : $default;
        $name = LocalizedLabel::fromInput($pick('name', $existing['name'] ?? null), 'name');
        $descriptionRaw = $pick('description', $existing['description'] ?? []);
        $description = $descriptionRaw === [] || $descriptionRaw === '' || $descriptionRaw === null ? [] : LocalizedLabel::fromInput($descriptionRaw, 'description');
        $int = static function (string $k, mixed $v, int $min, int $max): int {
            if (!is_int($v) || $v < $min || $v > $max) {
                throw new CalendarRejectedException("{$k} must be a whole number from {$min} to {$max}", 422);
            }

            return $v;
        };
        $price = $pick('price_minor', $existing['price_minor'] ?? null);
        if ($price !== null && (!is_int($price) || $price < 0)) {
            throw new CalendarRejectedException('price_minor must be a whole number of minor units (JOD 12.500 is 12500), or null', 422);
        }
        $settings = CalendarSettings::fromSettings($this->settings->effective($tenantId));
        $currency = $pick('currency', $existing['currency'] ?? $settings->currency);
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new CalendarRejectedException('currency must be a three-letter code such as JOD', 422);
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

        return [
            'name' => $name,
            'description' => $description,
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
