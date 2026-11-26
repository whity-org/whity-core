<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Log\ScrubbedErrorLog;
use Whity\Core\Queue\QueueService;
use Whity\Sdk\Calendar\BookingPaymentStep;
use Whity\Sdk\Calendar\BookingQuote;
use Whity\Sdk\Calendar\PaymentRequirement;
use Whity\Sdk\Calendar\PendingBooking;
use Whity\Core\Settings\SettingsService;

/**
 * Availability, bookings and busy events (ADR 0017).
 *
 * NO DOUBLE BOOKING
 * Every write that can take time on a person's calendar runs in one
 * transaction that first locks that person's `calendar_staff` row, then
 * re-reads their busy time inside the transaction, checks, writes and
 * commits ({@see self::underLock()}). Writers for one person queue; writers
 * for different people do not wait on each other. "Anyone" tries one person
 * at a time, so no request holds two locks except a move between two people,
 * which takes both in profile-id order.
 *
 * A service that needs a room (design 3.3) also takes that room's lock,
 * AFTER the people's, and the room's live bookings count as busy time.
 *
 * THE DATABASE TOO (migration 360, design section 39, AP.11)
 * On PostgreSQL every live booking's people and rooms are also written to
 * `calendar_holds` by triggers, and an exclusion constraint refuses two that
 * overlap, whoever writes them. The locks above stay: they give the friendly
 * reason. {@see self::locked()} checks the constraint before it returns and
 * turns a refusal into the same 409; Book anyway marks only the person's hold
 * as allowed to overlap (`overlap_ok`), never a room's.
 *
 * SEVERAL SERVICES (WHIT-1173)
 * With `calendar.multi_service_booking` on, one booking may hold several
 * services back to back, done by one person or each by their own. Such a
 * booking takes every person's lock in profile-id order, then every room's
 * in id order, and each person is busy only for their own services
 * ({@see self::bookMany()}).
 *
 * REQUESTS (design 3.3)
 * A customer's booking is a request when the service needs approval or the
 * workspace turned instant booking off. A request holds its time like a
 * booking; staff accept it or suggest another time, and the customer takes
 * the suggestion from their manage link.
 *
 * Events and messages go out only after the commit.
 */
final class BookingService
{
    /** At most this many services in one booking (WHIT-1173). */
    public const MAX_SERVICES = 8;

    private ?CalendarInvites $invites = null;

    private ?EventReminders $reminders = null;

    /** @var list<ExternalBusySource> */
    private array $busySources = [];

    private ?ManageLinks $links = null;

    private ?QueueService $queue = null;

    private ?CalendarPlugins $plugins = null;

    /** C4: reminders by text, through Messages. */
    private ?BookingTextReminders $textReminders = null;

    private string $manageUrlBase = '';

    private ?RoomRepository $rooms = null;
    private ?BranchZones $zones = null;
    private ?Waitlist $waitlist = null;
    private ?IntakeForms $intake = null;
    private ?VisitRecords $visits = null;
    private ?\Whity\Storage\StorageDriverInterface $storage = null;

    /** WHIT-1427: every attendance mark is audited (AP.3). */
    private ?\Whity\Core\Audit\AuditLoggerInterface $audit = null;

    private ?AttendanceRepository $attendance = null;

    public function __construct(
        private readonly PDO $db,
        private readonly CalendarRepository $calendar,
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookings,
        private readonly SettingsService $settings,
        private readonly ?HookManager $hooks = null,
        private BookingMessenger $messenger = new NullBookingMessenger(),
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function setInvites(CalendarInvites $invites): void
    {
        $this->invites = $invites;
    }

    /** Staff reminders before events (ADR 0018). */
    public function setReminders(EventReminders $reminders): void
    {
        $this->reminders = $reminders;
    }

    public function addBusySource(ExternalBusySource $source): void
    {
        $this->busySources[] = $source;
    }

    public function setMessenger(BookingMessenger $messenger): void
    {
        $this->messenger = $messenger;
        $this->waitlist = null;
    }

    /** Customer manage links (WHIT-942): issued on create and move, revoked on cancel. */
    public function setLinks(ManageLinks $links): void
    {
        $this->links = $links;
    }

    /** Plugins' payment step and busy times (SDK 1.98), read at call time. */
    /** Reminders by text (C4); left unset, the app's Messages is used when a reminder goes. */
    public function setTextReminders(BookingTextReminders $textReminders): void
    {
        $this->textReminders = $textReminders;
        $this->waitlist = null;
    }

    public function setPlugins(CalendarPlugins $plugins): void
    {
        $this->plugins = $plugins;
    }

    /** Attendance marks are recorded here (WHIT-1427, AP.3). */
    public function setAudit(\Whity\Core\Audit\AuditLoggerInterface $audit): void
    {
        $this->audit = $audit;
        $this->visits = null;
    }

    public function attendanceRepository(): AttendanceRepository
    {
        return $this->attendance ??= new AttendanceRepository($this->db);
    }

    /** Where the customer's manage page lives; a payment step returns them there. */
    public function setManageUrlBase(string $base): void
    {
        $this->manageUrlBase = $base;
        $this->waitlist = null;
        $this->intake = null;
        $this->visits = null;
    }

    /** Reminders are queued here when set ({@see BookingReminderJob}). */
    public function setQueue(QueueService $queue): void
    {
        $this->queue = $queue;
    }

    public function settingsFor(int $tenantId): CalendarSettings
    {
        return CalendarSettings::fromSettings($this->settings->effective($tenantId));
    }

    public function now(): DateTimeImmutable
    {
        $now = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();

        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Bookable slots for a service in `[from, to)`, by start time, each with
     * the people free then. `$exceptBookingId` treats that booking's own time
     * and buffers as free, so a customer moving it sees the times next to it.
     *
     * @return list<array{starts_at: string, ends_at: string, profile_ids: list<int>}>
     */
    public function availability(int $tenantId, int $serviceId, ?int $profileId, DateTimeImmutable $from, DateTimeImmutable $to, bool $publicOnly = false, ?int $exceptBookingId = null): array
    {
        $service = $this->service($tenantId, $serviceId, $publicOnly);
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $earliest = max($from, $now->modify('+' . $settings->minNoticeMinutes . ' minutes'));
        $latest = min($to, $now->modify('+' . $settings->maxAdvanceDays . ' days'));
        if ($latest <= $earliest) {
            return [];
        }

        $byStart = [];
        foreach ($this->candidates($tenantId, $service, $profileId, $publicOnly) as $candidate) {
            // WHIT-1440 (AP.19): each person's own length and buffer after.
            $own = ServiceRepository::forPerson($service, $candidate);
            foreach ($this->slotsFor($tenantId, $candidate, $own, $settings, $earliest, $latest, $now, $exceptBookingId) as $slot) {
                $key = $slot->getTimestamp();
                // The end is the first free person's, in the order "anyone" tries them.
                $byStart[$key] ??= ['start' => $slot, 'minutes' => (int) $own['duration_minutes'], 'profile_ids' => []];
                $byStart[$key]['profile_ids'][] = $candidate;
            }
        }
        ksort($byStart);

        return array_values(array_map(static fn (array $s): array => [
            'starts_at' => Interval::toApi($s['start']),
            'ends_at' => Interval::toApi($s['start']->modify('+' . $s['minutes'] . ' minutes')),
            'profile_ids' => $s['profile_ids'],
        ], $byStart));
    }

    /**
     * Free start times for several services back to back (WHIT-1173), in
     * `[from, to)`. A time is free when, for each service, someone who may do
     * it is free for that service's own part of the span, buffers included,
     * inside their hours. Each item's `profile_id` null is "anyone", who may
     * be a different person per service; `$samePerson` asks for one person
     * who does them all. Each time comes with the people it would be with.
     *
     * Moving a booking of several passes its own id as `$exceptBookingId`,
     * so its own time counts as free, and `$requireSetting` false, so a
     * booking made before the workspace turned the setting off still moves.
     *
     * @param list<array{service_id: int, profile_id: ?int}> $items
     * @return list<array{starts_at: string, ends_at: string, profile_ids: list<int>, items: list<array{service_id: int, profile_id: int, starts_at: string, ends_at: string}>}>
     */
    public function availabilityForMany(int $tenantId, array $items, bool $samePerson, DateTimeImmutable $from, DateTimeImmutable $to, bool $publicOnly = false, ?int $exceptBookingId = null, bool $requireSetting = true): array
    {
        $plan = $this->plan($tenantId, $items, $samePerson, $publicOnly, $requireSetting);
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $earliest = max($from, $now->modify('+' . $settings->minNoticeMinutes . ' minutes'));
        $latest = min($to, $now->modify('+' . $settings->maxAdvanceDays . ' days'));
        if ($latest <= $earliest) {
            return [];
        }

        // The longest the whole booking can take, whoever does each part.
        $total = 0;
        foreach ($plan['services'] as $k => $service) {
            $longest = (int) $service['duration_minutes'];
            foreach ($plan['candidates'][$k] as $person) {
                $longest = max($longest, (int) ServiceRepository::forPerson($service, $person)['duration_minutes']);
            }
            $total += $longest;
        }
        $wideFrom = $earliest->modify('-1 day');
        $wideTo = $latest->modify('+1 day')->modify('+' . $total . ' minutes');
        $windows = [];
        $busy = [];
        foreach ($plan['candidates'] as $who) {
            foreach ($who as $person) {
                if (isset($windows[$person])) {
                    continue;
                }
                // WHIT-1440 (AP.16): each person's hours in their branch's zone.
                $zone = $this->zoneOf($tenantId, $person, $settings);
                $windows[$person] = Availability::openWindows(
                    $this->calendar->hours($tenantId, $person),
                    $this->calendar->exceptions($tenantId, $person, $wideFrom, $wideTo),
                    $zone,
                    $earliest->setTimezone($zone)->setTime(0, 0),
                    $latest->modify('+' . $total . ' minutes'),
                );
                $busy[$person] = Interval::merge($this->busyFor($tenantId, $person, $wideFrom, $wideTo, $now, $exceptBookingId));
            }
        }
        // WHIT-1439 (AP.18): what each service needs, and each resource's busy time, read once.
        $needs = [];
        foreach ($plan['services'] as $k => $service) {
            foreach ($plan['candidates'][$k] as $person) {
                $needs[$k][$person] = $this->needs($tenantId, ServiceRepository::forPerson($service, $person));
            }
        }
        $busyOf = $this->busyReader($tenantId, $wideFrom, $wideTo, $now, $exceptBookingId);
        $free = static function (int $person, int $k, DateTimeImmutable $at) use ($plan, $windows, $busy, $needs, $busyOf): bool {
            $service = ServiceRepository::forPerson($plan['services'][$k], $person);
            $minutes = (int) $service['duration_minutes'];
            if (!Availability::withinWindows($at, $minutes, $windows[$person] ?? [])) {
                return false;
            }
            if (Availability::clashes($at, $minutes, (int) $service['buffer_before_minutes'], (int) $service['buffer_after_minutes'], $busy[$person] ?? [])) {
                return false;
            }

            $own = $needs[$k][$person] ?? [];

            return $own === [] || self::choose($own, Availability::blockFor($at, $minutes, (int) $service['buffer_before_minutes'], (int) $service['buffer_after_minutes']), $busyOf) !== null;
        };

        // Start times step from the start of each open window of whoever may
        // do the first service, as one service's do (on the hours' grid).
        $step = max(1, $settings->slotStepMinutes) * 60;
        $starts = [];
        foreach ($plan['candidates'][0] as $person) {
            $firstMinutes = (int) ServiceRepository::forPerson($plan['services'][0], $person)['duration_minutes'] * 60;
            foreach ($windows[$person] as $window) {
                for ($t = $window->start->getTimestamp(); $t + $firstMinutes <= $window->end->getTimestamp(); $t += $step) {
                    if ($t >= $earliest->getTimestamp() && $t < $latest->getTimestamp()) {
                        $starts[$t] = true;
                    }
                }
            }
        }
        ksort($starts);

        $out = [];
        foreach (array_keys($starts) as $t) {
            $start = (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('UTC'));
            $people = self::assign($plan, $start, $free);
            if ($people === null) {
                continue;
            }
            $parts = self::parts($plan['services'], $people, $start);
            $out[] = [
                'starts_at' => Interval::toApi($start),
                'ends_at' => Interval::toApi($parts[count($parts) - 1]['ends_at']),
                'profile_ids' => array_values(array_unique($people)),
                'items' => array_map(static fn (array $part): array => [
                    'service_id' => $part['service_id'],
                    'profile_id' => $part['profile_id'],
                    'starts_at' => Interval::toApi($part['starts_at']),
                    'ends_at' => Interval::toApi($part['ends_at']),
                ], $parts),
            ];
        }

        return $out;
    }

    /**
     * Book a service, or several back to back (WHIT-1173: `items` with two
     * or more, when the workspace turned `calendar.multi_service_booking`
     * on; see {@see self::bookMany()}).
     *
     * @param array{
     *   service_id: int, profile_id: ?int, starts_at: DateTimeImmutable,
     *   customer_profile_id: ?int, customer_name: string, customer_email: string,
     *   customer_phone: string, customer_note: string, source: string,
     *   created_by_profile_id: ?int, enforce_hours: bool, public: bool,
     *   book_anyway?: bool, within_hours?: bool, notify?: bool,
     *   items?: list<array{service_id: int, profile_id: ?int}>, same_person?: bool
     * } $request
     * @return array<string, mixed> the booking
     */
    public function book(int $tenantId, array $request): array
    {
        $items = $request['items'] ?? [];
        if (count($items) > 1) {
            return $this->bookMany($tenantId, $request, $items);
        }
        if (count($items) === 1) {
            $request['service_id'] = $items[0]['service_id'];
            $request['profile_id'] = $items[0]['profile_id'];
        }
        $service = $this->service($tenantId, $request['service_id'], $request['public']);
        // Staff only: Book anyway, keep to the hours, and whether the customer
        // is emailed (CV.8, CV.9). A booking's length is always its service's:
        // the length goes with the price, so staff change the service instead
        // (owner, Q35.7).
        $staffOnly = !$request['public'];
        $bookAnyway = $staffOnly && ($request['book_anyway'] ?? false);
        $checkHours = $request['enforce_hours'] || ($staffOnly && ($request['within_hours'] ?? false));
        $notify = !$staffOnly || ($request['notify'] ?? true);
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $start = $request['starts_at'];
        if ($request['enforce_hours']) {
            $this->assertInWindow($start, $settings, $now);
        }
        $candidates = $this->candidates($tenantId, $service, $request['profile_id'], $request['public']);
        if ($candidates === []) {
            throw new CalendarRejectedException('Nobody offers this service.', 422);
        }

        // A request is not paid for until it is accepted: no payment step.
        $asRequest = $request['public'] && ($service['needs_approval'] || !$settings->instantBooking);
        $step = $asRequest ? null : $this->plugins?->paymentStep();
        $room = $this->roomLock($tenantId, $service);
        $why = null;
        $base = $service;
        foreach ($candidates as $candidate) {
            // WHIT-1440 (AP.19): the person's own length, buffer after and
            // price; the booking keeps what it used (its end, block and price).
            $service = ServiceRepository::forPerson($base, $candidate);
            $end = $start->modify('+' . $service['duration_minutes'] . ' minutes');
            $block = Availability::blockFor($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes']);
            // Asked before the lock: a plugin never runs while a person's
            // calendar is held. A step that fails refuses the booking rather
            // than letting it through unpaid.
            $quote = new BookingQuote($tenantId, (int) $service['id'], $candidate, $start, $end, $service['price_minor'], (string) $service['currency'], $request['customer_email'], $request['source']);
            $requirement = null;
            if ($step !== null) {
                try {
                    $requirement = $step->requirement($quote);
                } catch (Throwable $e) {
                    error_log('[calendar] the payment step failed to answer: ' . $e->getMessage());
                    throw new CalendarRejectedException('Booking is unavailable right now. Try again later.', 503);
                }
            }
            $hold = $requirement !== null ? $now->modify('+' . $settings->paymentHoldMinutes . ' minutes') : null;
            try {
                $id = $this->underLock([[$tenantId, $candidate]], function () use ($tenantId, $candidate, $service, $settings, $request, $start, $end, $block, $now, $hold, $asRequest, $bookAnyway, $checkHours, &$why): ?int {
                    $why = $this->whyNotFree($tenantId, $candidate, $service, $settings, $start, $now, null, $checkHours, $bookAnyway);
                    if ($why !== null) {
                        return null;
                    }
                    // WHIT-1439 (AP.18): the first free resource for each need.
                    $chosen = $this->chooseFor($tenantId, $service, $start, $now, null) ?? [];

                    $newId = $this->bookings->insert($tenantId, [
                        'service_id' => $service['id'],
                        'profile_id' => $candidate,
                        'customer_profile_id' => $request['customer_profile_id'],
                        'customer_name' => $request['customer_name'],
                        'customer_email' => $request['customer_email'],
                        'customer_phone' => $request['customer_phone'],
                        'customer_note' => $request['customer_note'],
                        'starts_at' => $start,
                        'ends_at' => $end,
                        'block' => $block,
                        'status' => $asRequest ? BookingRepository::STATUS_REQUESTED : ($hold !== null ? BookingRepository::STATUS_PENDING_PAYMENT : BookingRepository::STATUS_CONFIRMED),
                        'hold_expires_at' => $hold,
                        'source' => $request['source'],
                        'price_minor' => $service['price_minor'],
                        'currency' => $service['currency'],
                        'reminder_due_at' => $asRequest ? null : $this->reminderDue($start, $settings, $now),
                        'created_by_profile_id' => $request['created_by_profile_id'],
                        'room_id' => $chosen[0] ?? null,
                        'overlap_ok' => $bookAnyway && $this->personClashes($tenantId, $candidate, $service, $start, $now, null),
                    ]);
                    if ($chosen !== []) {
                        $this->bookings->setResources($tenantId, $newId, self::resourceRows([$chosen], [$block]));
                    }

                    return $newId;
                }, $room);
            } catch (CalendarRejectedException $e) {
                // The database refused it (AP.11): try the next person.
                if (!HoldConflicts::isHeld($e)) {
                    throw $e;
                }
                $why = is_string($e->extra['reason'] ?? null) ? $e->extra['reason'] : null;
                $id = null;
            }
            if ($id !== null) {
                return $this->created($tenantId, $id, $quote, $requirement, $hold, $step, $asRequest, $notify);
            }
        }

        // One person asked for: say why that person's time is not free, so
        // staff can choose Book anyway. Anyone, or a customer: the plain 409.
        throw $staffOnly && count($candidates) === 1 && is_string($why) ? CalendarRejectedException::notFree($why) : CalendarRejectedException::taken();
    }

    /**
     * Book several services back to back as one booking (WHIT-1173).
     *
     * The booking row is the first service's (service, person, room) and
     * spans them all, priced at the total; each service is its own row with
     * its own person, time and buffers ({@see BookingRepository::insertItems()}).
     *
     * NO DOUBLE BOOKING, AS FOR ONE SERVICE. Who does what is chosen first
     * from what is free now ({@see self::assign()}); then every person in it
     * is locked in profile-id order, then every room in id order, and each
     * service's time is checked again under the locks before anything is
     * written. If someone was taken in between, the choice is made again
     * (a few times at most) before the plain 409.
     *
     * @param array<string, mixed> $request as {@see self::book()} takes it
     * @param list<array{service_id: int, profile_id: ?int}> $items
     * @return array<string, mixed>
     */
    private function bookMany(int $tenantId, array $request, array $items): array
    {
        // The request's flags are PHP booleans the handlers built, not database values.
        $public = $request['public'] === true;
        $plan = $this->plan($tenantId, $items, ($request['same_person'] ?? false) === true, $public);
        $staffOnly = !$public;
        $bookAnyway = $staffOnly && ($request['book_anyway'] ?? false) === true;
        $checkHours = $request['enforce_hours'] === true || ($staffOnly && ($request['within_hours'] ?? false) === true);
        $notify = !$staffOnly || ($request['notify'] ?? true) !== false;
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $start = $request['starts_at'];
        if (!$start instanceof DateTimeImmutable) {
            throw new CalendarRejectedException('Choose a time', 422);
        }
        if ($request['enforce_hours']) {
            $this->assertInWindow($start, $settings, $now);
        }
        $needsApproval = false;
        foreach ($plan['services'] as $service) {
            $needsApproval = $needsApproval || $service['needs_approval'] === true;
        }
        $asRequest = $public && ($needsApproval || !$settings->instantBooking);
        $step = $asRequest ? null : $this->plugins?->paymentStep();
        $why = null;
        $tried = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $people = self::assign($plan, $start, function (int $person, int $k, DateTimeImmutable $at) use ($tenantId, $plan, $settings, $now, $checkHours, $bookAnyway, &$why): bool {
                $why = $this->whyNotFree($tenantId, $person, ServiceRepository::forPerson($plan['services'][$k], $person), $settings, $at, $now, null, $checkHours, $bookAnyway);

                return $why === null;
            });
            if ($people === null || isset($tried[implode(',', $people)])) {
                break;
            }
            $tried[implode(',', $people)] = true;
            $parts = self::parts($plan['services'], $people, $start);
            // WHIT-1440 (AP.19): each part as its person provides it.
            $own = array_map(static fn (array $service, int $person): array => ServiceRepository::forPerson($service, $person), $plan['services'], $people);
            $total = self::totalPrice($own);
            $end = $parts[count($parts) - 1]['ends_at'];
            $quote = new BookingQuote($tenantId, $parts[0]['service_id'], $parts[0]['profile_id'], $start, $end, $total, $plan['currency'], (string) $request['customer_email'], (string) $request['source']);
            $requirement = null;
            if ($step !== null) {
                try {
                    $requirement = $step->requirement($quote);
                } catch (Throwable $e) {
                    error_log('[calendar] the payment step failed to answer: ' . $e->getMessage());
                    throw new CalendarRejectedException('Booking is unavailable right now. Try again later.', 503);
                }
            }
            $hold = $requirement !== null ? $now->modify('+' . $settings->paymentHoldMinutes . ' minutes') : null;
            try {
                $id = $this->underLocks($tenantId, $people, $this->resourceIds($tenantId, $plan['services']), function () use ($tenantId, $plan, $own, $parts, $settings, $request, $start, $end, $now, $hold, $asRequest, $total, $checkHours, $bookAnyway, &$why): ?int {
                    $overlapOk = false;
                    $chosen = [];
                    foreach ($parts as $k => $part) {
                        $why = $this->whyNotFree($tenantId, $part['profile_id'], $own[$k], $settings, $part['starts_at'], $now, null, $checkHours, $bookAnyway);
                        if ($why !== null) {
                            return null;
                        }
                        $overlapOk = $overlapOk || ($bookAnyway && $this->personClashes($tenantId, $part['profile_id'], $own[$k], $part['starts_at'], $now, null));
                        // WHIT-1439 (AP.18): each part's own resources.
                        $chosen[$k] = $this->chooseFor($tenantId, $own[$k], $part['starts_at'], $now, null) ?? [];
                        $parts[$k]['room_id'] = $chosen[$k][0] ?? null;
                    }
                    $id = $this->bookings->insert($tenantId, [
                        'service_id' => $parts[0]['service_id'],
                        'profile_id' => $parts[0]['profile_id'],
                        'customer_profile_id' => $request['customer_profile_id'],
                        'customer_name' => $request['customer_name'],
                        'customer_email' => $request['customer_email'],
                        'customer_phone' => $request['customer_phone'],
                        'customer_note' => $request['customer_note'],
                        'starts_at' => $start,
                        'ends_at' => $end,
                        'block' => self::span($parts),
                        'status' => $asRequest ? BookingRepository::STATUS_REQUESTED : ($hold !== null ? BookingRepository::STATUS_PENDING_PAYMENT : BookingRepository::STATUS_CONFIRMED),
                        'hold_expires_at' => $hold,
                        'source' => $request['source'],
                        'price_minor' => $total,
                        'currency' => $plan['currency'],
                        'reminder_due_at' => $asRequest ? null : $this->reminderDue($start, $settings, $now),
                        'created_by_profile_id' => $request['created_by_profile_id'],
                        'room_id' => $parts[0]['room_id'],
                        'overlap_ok' => $overlapOk,
                    ]);
                    $this->bookings->insertItems($tenantId, $id, $parts);
                    $this->bookings->setResources($tenantId, $id, self::resourceRows($chosen, array_column($parts, 'block')));

                    return $id;
                });
            } catch (CalendarRejectedException $e) {
                // The database refused it (AP.11): choose again.
                if (!HoldConflicts::isHeld($e)) {
                    throw $e;
                }
                $why = is_string($e->extra['reason'] ?? null) ? $e->extra['reason'] : null;
                $id = null;
            }
            if ($id !== null) {
                return $this->created($tenantId, $id, $quote, $requirement, $hold, $step, $asRequest, $notify);
            }
        }

        // Every person chosen by staff: say why, so they can choose Book anyway.
        throw $staffOnly && $plan['fixed'] && is_string($why) ? CalendarRejectedException::notFree($why) : CalendarRejectedException::taken();
    }

    /**
     * After a booking is written: its link and reminder, then the payment
     * step, the request or the confirmation, and who is told.
     *
     * @return array<string, mixed>
     */
    private function created(int $tenantId, int $id, BookingQuote $quote, ?PaymentRequirement $requirement, ?DateTimeImmutable $hold, ?BookingPaymentStep $step, bool $asRequest, bool $notify): array
    {
        $booking = $this->afterWrite($tenantId, $id);
        if ($requirement !== null && $hold !== null && $step !== null) {
            return $this->startPayment($tenantId, $booking, $quote, $requirement, $hold, $step);
        }
        // WHIT-1443 (AP.20): the forms to fill, linked from the email and the page.
        $booking['intake_forms'] = $this->intakeLinks($tenantId, $booking);
        if ($asRequest) {
            $this->announceBooking(CalendarEventNames::BOOKING_CREATED, $tenantId, $booking);
            $this->tell('requested', $tenantId, $booking);

            return $booking;
        }
        $this->announceBooking(CalendarEventNames::BOOKING_CREATED, $tenantId, $booking);
        $this->announceBooking(CalendarEventNames::BOOKING_CONFIRMED, $tenantId, $booking);
        if ($notify) {
            $this->tell('confirmed', $tenantId, $booking);
        }

        return $booking;
    }

    /**
     * Move a booking to a new start, and optionally to another person.
     *
     * Design 3.5 (CV.9): staff may also choose Book anyway when the person is
     * busy, keep it inside the person's hours (`withinHours`, a drag on the
     * grid), and untick "Email the new time" (`notify` false). Its length
     * stays its service's (owner, Q35.7).
     *
     * @return array<string, mixed>
     */
    public function move(int $tenantId, int $bookingId, DateTimeImmutable $start, ?int $profileId, bool $enforceHours, bool $notify = true, bool $bookAnyway = false, bool $withinHours = false): array
    {
        $before = $this->bookings->find($tenantId, $bookingId);
        $booking = $this->moveUnderLock($tenantId, $bookingId, $start, $profileId, $enforceHours, $bookAnyway, $withinHours);
        // E2 `booking-moved`: the email says what the time was.
        $booking['previous_starts_at'] = is_string($before['starts_at'] ?? null) ? Interval::toApi(Interval::fromDb($before['starts_at'])) : null;
        if ($booking['status'] === BookingRepository::STATUS_REQUESTED) {
            // The customer (or staff) changed the time of a request: it is
            // still a request, and no longer staff's suggestion.
            $this->bookings->markSuggested($tenantId, $bookingId, null);
            $booking['suggested_at'] = null;
            $this->announceBooking(CalendarEventNames::BOOKING_RESCHEDULED, $tenantId, $booking);
            $this->tell('requested', $tenantId, $booking);
            $this->offerFreed($tenantId, $before);

            return $booking;
        }
        $this->announceBooking(CalendarEventNames::BOOKING_RESCHEDULED, $tenantId, $booking);
        if ($notify) {
            $this->tell('rescheduled', $tenantId, $booking);
        }
        // WHIT-1442 (AP.23): the time it left is offered to the waitlist.
        $this->offerFreed($tenantId, $before);

        return $booking;
    }

    /** Where visit files are kept: the tenant's own storage (WHIT-1443, AP.21). */
    public function setStorage(\Whity\Storage\StorageDriverInterface $storage): void
    {
        $this->storage = $storage;
        $this->visits = null;
    }

    /** Forms to fill before a visit (WHIT-1443, AP.20). */
    public function intake(): IntakeForms
    {
        // The public form page sits beside the manage page: `/booking` → `/f`.
        $base = $this->manageUrlBase !== '' ? (preg_replace('#/booking/?$#', '/f', rtrim($this->manageUrlBase, '/')) ?? '') : '';

        return $this->intake ??= new IntakeForms($this->db, $base);
    }

    /** Visit records (WHIT-1443, AP.21). */
    public function visits(): VisitRecords
    {
        return $this->visits ??= new VisitRecords($this->db, $this->intake(), $this->storage, $this->audit, fn (): DateTimeImmutable => $this->now());
    }

    /**
     * Each booking with the intake forms it needs, read once for a whole list.
     *
     * @param list<array<string, mixed>> $bookings
     * @return list<array<string, mixed>>
     */
    public function withIntake(int $tenantId, array $bookings): array
    {
        $status = $this->intake()->statusFor($tenantId, $bookings);
        foreach ($bookings as $i => $b) {
            $bookings[$i]['intake'] = $status[(int) $b['id']] ?? ['status' => IntakeForms::NOT_NEEDED, 'forms' => []];
        }

        return $bookings;
    }

    /**
     * A link to each intake form a booking still needs (WHIT-1443, AP.20),
     * for its confirmation and the page after booking.
     *
     * @param array<string, mixed> $booking
     * @return list<array{form_id: int, name: string, url: string}>
     */
    public function intakeLinks(int $tenantId, array $booking): array
    {
        $links = [];
        try {
            foreach ($this->intake()->statusFor($tenantId, [$booking])[(int) $booking['id']]['forms'] ?? [] as $form) {
                if ($form['done']) {
                    continue;
                }
                $url = $this->intake()->linkFor($tenantId, (int) $booking['id'], $form['form_id'], $this->now());
                if ($url !== null) {
                    $links[] = ['form_id' => $form['form_id'], 'name' => $form['name'], 'url' => $url];
                }
            }
        } catch (Throwable $e) {
            ScrubbedErrorLog::failure('[calendar] could not make the intake form links', $e);
        }

        return $links;
    }

    /**
     * A fresh link to one of a booking's intake forms (WHIT-1443, AP.20): for
     * Send again (`$send`, emailed to the customer) or Fill in at the desk.
     *
     * @return array{url: string, sent: ?string}
     */
    public function intakeLink(int $tenantId, int $bookingId, int $formId, bool $send): array
    {
        $booking = $this->bookingOrFail($tenantId, $bookingId);
        $form = null;
        foreach ($booking['intake']['forms'] ?? [] as $f) {
            if ($f['form_id'] === $formId) {
                $form = $f;
            }
        }
        if ($form === null) {
            throw new CalendarRejectedException('This booking doesn\'t ask for that form.', 422);
        }
        $url = $this->intake()->linkFor($tenantId, $bookingId, $formId, $this->now())
            ?? throw new CalendarRejectedException('That form has no public link. Turn on its link under Forms first.', 422, ['code' => 'form_not_public']);
        $sent = null;
        if ($send) {
            if (!$this->messenger instanceof IntakeMessenger) {
                throw new CalendarRejectedException('Email isn\'t set up here.', 503);
            }
            $sent = $this->messenger->intakeForm($tenantId, [
                'to' => (string) ($booking['customer']['email'] ?? ''),
                'name' => (string) ($booking['customer']['name'] ?? ''),
                'form' => $form['name'],
                'url' => $url,
                'service' => is_array($booking['service']['name'] ?? null) ? $booking['service']['name'] : [],
                'starts_at' => (string) $booking['starts_at'],
                'time_zone' => is_string($booking['time_zone'] ?? null) ? $booking['time_zone'] : null,
            ]);
            if ($sent === BookingRepository::REMINDER_NO_ADDRESS) {
                throw new CalendarRejectedException('This customer has no email. Fill it in at the desk instead.', 422, ['code' => 'no_email']);
            }
        }

        return ['url' => $url, 'sent' => $sent];
    }

    /** The waitlist (WHIT-1442, AP.23). */
    public function waitlist(): Waitlist
    {
        return $this->waitlist ??= new Waitlist(
            $this->db,
            $this,
            $this->messenger instanceof WaitlistMessenger ? $this->messenger : null,
            $this->textReminders ??= BookingTextReminders::fromApp($this->settings),
            $this->manageUrlBase !== '' ? rtrim($this->manageUrlBase, '/') . '/offer' : '/booking/offer',
        );
    }

    /**
     * After a booking's time was freed (it was cancelled, or moved away from
     * it), offer that time to the waitlist (WHIT-1442, AP.23). Outside the
     * locks and after the write; never fails the cancel or the move.
     *
     * @param array<string, mixed>|null $before the booking as it was
     */
    private function offerFreed(int $tenantId, ?array $before): void
    {
        if ($before === null || (is_array($before['items'] ?? null) && $before['items'] !== []) || !is_string($before['starts_at'] ?? null)) {
            return;
        }
        $still = $this->bookings->find($tenantId, (int) $before['id']);
        if ($still !== null && in_array($still['status'], BookingRepository::LIVE, true)
            && $still['starts_at'] === $before['starts_at'] && (int) $still['profile_id'] === (int) $before['profile_id']) {
            return;
        }
        try {
            $this->waitlist()->offerFreed($tenantId, (int) $before['profile_id'], (int) $before['service_id'], Interval::fromDb($before['starts_at']));
        } catch (Throwable $e) {
            ScrubbedErrorLog::failure('[calendar] could not offer a freed time to the waitlist', $e);
        }
    }

    /**
     * Accept a customer's request: it becomes a confirmed booking, the
     * customer is told, and the reminder is queued.
     *
     * @return array<string, mixed>
     */
    public function accept(int $tenantId, int $bookingId): array
    {
        $row = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if (!$this->bookings->transition($tenantId, $bookingId, [BookingRepository::STATUS_REQUESTED], BookingRepository::STATUS_CONFIRMED)) {
            throw new CalendarRejectedException('Only a request can be accepted.', 422);
        }
        // The time can't have changed: a request moves only while it is a request.
        $this->bookings->setReminderDue($tenantId, $bookingId, $this->reminderDue(Interval::fromDb((string) $row['starts_at']), $this->settingsFor($tenantId), $this->now()));
        $booking = $this->afterWrite($tenantId, $bookingId);
        $this->announceBooking(CalendarEventNames::BOOKING_CONFIRMED, $tenantId, $booking);
        $this->tell('confirmed', $tenantId, $booking);

        return $booking;
    }

    /**
     * Suggest another time for a request: the request moves there, stays a
     * request, and the customer takes it from their link
     * ({@see self::acceptSuggestion()}) or picks another time.
     *
     * @return array<string, mixed>
     */
    public function suggest(int $tenantId, int $bookingId, DateTimeImmutable $start, ?int $profileId): array
    {
        $current = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if ($current['status'] !== BookingRepository::STATUS_REQUESTED) {
            throw new CalendarRejectedException('Another time can only be suggested for a request.', 422);
        }
        $booking = $this->moveUnderLock($tenantId, $bookingId, $start, $profileId, false);
        $this->bookings->markSuggested($tenantId, $bookingId, $this->now());
        $booking['suggested_at'] = Interval::toApi($this->now());
        $this->announceBooking(CalendarEventNames::BOOKING_RESCHEDULED, $tenantId, $booking);
        $this->tell('suggested', $tenantId, $booking);

        return $booking;
    }

    /**
     * The customer takes the time staff suggested.
     *
     * @return array<string, mixed>
     */
    public function acceptSuggestion(int $tenantId, int $bookingId): array
    {
        $row = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if ($row['status'] !== BookingRepository::STATUS_REQUESTED || $row['suggested_at'] === null) {
            throw new CalendarRejectedException('There is no suggested time to take.', 422);
        }

        return $this->accept($tenantId, $bookingId);
    }

    /**
     * Mark the customer as arrived (or undo it). Only a confirmed booking.
     *
     * @return array<string, mixed>
     */
    public function checkIn(int $tenantId, int $bookingId, bool $arrived, ?int $actor = null): array
    {
        $row = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if (!$this->bookings->checkIn($tenantId, $bookingId, $arrived ? $this->now() : null)) {
            throw new CalendarRejectedException('Only a confirmed booking can be checked in.', 422);
        }
        // WHIT-1427 (AP.3): arriving is coming; undoing the check-in undoes that.
        $was = is_string($row['attendance'] ?? null) ? $row['attendance'] : null;
        if ($arrived && $was !== BookingRepository::ATTENDED) {
            $this->recordAttendance($tenantId, $row, BookingRepository::ATTENDED, $actor, false);
        } elseif (!$arrived && $was === BookingRepository::ATTENDED) {
            $this->recordAttendance($tenantId, $row, null, $actor, false);
        }

        return $this->bookingOrFail($tenantId, $bookingId);
    }

    /** WHIT-1441 (AP.27): the desk's statuses, in the order a visit goes. */
    public const DESK_STEPS = ['expected', 'arrived', 'with_staff', 'done'];

    /**
     * Set where a visit is at the front desk (WHIT-1441, design section 39
     * §10.1, AP.27): Expected, Arrived (checked in), With staff (started) or
     * Done (finished). A later step fills in the earlier ones it skipped
     * (Start on someone not checked in checks them in), and an earlier one
     * clears the later ones, so each button can be undone by the one before.
     * Didn't come is {@see self::markAttendance()}. Only a confirmed booking.
     *
     * @return array<string, mixed>
     */
    public function setDeskStatus(int $tenantId, int $bookingId, string $status, ?int $actor): array
    {
        $to = array_search($status, self::DESK_STEPS, true);
        if ($to === false) {
            throw new CalendarRejectedException('status is expected, arrived, with_staff or done.', 422);
        }
        $row = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if ($row['status'] !== BookingRepository::STATUS_CONFIRMED) {
            throw new CalendarRejectedException('Only a confirmed booking can be checked in, started or finished.', 422);
        }
        $now = $this->now();
        $arrivedNow = $row['checked_in_at'] !== null;
        if ($to >= 1 && !$arrivedNow) {
            $this->checkIn($tenantId, $bookingId, true, $actor);
        } elseif ($to === 0 && $arrivedNow) {
            $this->checkIn($tenantId, $bookingId, false, $actor);
        }
        $started = is_string($row['started_at'] ?? null) && $row['started_at'] !== '' ? Interval::fromDb($row['started_at']) : null;
        $finished = is_string($row['finished_at'] ?? null) && $row['finished_at'] !== '' ? Interval::fromDb($row['finished_at']) : null;
        $started = $to >= 2 ? ($started ?? $now) : null;
        $finished = $to >= 3 ? ($finished ?? $now) : null;
        $this->bookings->setVisit($tenantId, $bookingId, $started, $finished);
        try {
            $this->audit?->record('calendar.desk.status', [
                'tenant_id' => $tenantId,
                'actor_user_id' => $actor !== null && $actor > 0 ? $actor : null,
                'target_type' => 'booking',
                'target_id' => $bookingId,
                'metadata' => ['status' => $status],
            ]);
        } catch (Throwable $e) {
            ScrubbedErrorLog::failure('[calendar] could not audit a desk status', $e);
        }

        return $this->bookingOrFail($tenantId, $bookingId);
    }

    /**
     * The customer is running late (WHIT-1441, §10.2, AP.29): shown on the
     * booking, and nothing moves. Null clears it. A late arrival past the
     * grace time can still be checked in.
     *
     * @return array<string, mixed>
     */
    public function markLate(int $tenantId, int $bookingId, ?int $minutes): array
    {
        if ($minutes !== null && ($minutes < 1 || $minutes > 240)) {
            throw new CalendarRejectedException('minutes is from 1 to 240, or null.', 422);
        }
        $row = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if (!in_array($row['status'], BookingRepository::LIVE, true)) {
            throw new CalendarRejectedException('Only a booking that is still on can be marked late.', 422);
        }
        $this->bookings->setLate($tenantId, $bookingId, $minutes, $this->now());

        return $this->bookingOrFail($tenantId, $bookingId);
    }

    /**
     * The front desk's day in one read (WHIT-1441, §10.1, AP.27, AP.28): the
     * local day's bookings with their desk status and customer flag, and the
     * walk-ins waiting. With `$since`, only the bookings changed since then,
     * cancelled ones included so the desk can drop them.
     *
     * @return array{date: string, time_zone: string, now: string, full: bool, bookings: list<array<string, mixed>>, waitlist_count: int}
     */
    public function deskDay(int $tenantId, ?string $date, ?int $unitId, ?DateTimeImmutable $since): array
    {
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $day = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? new DateTimeImmutable($date . ' 00:00:00', $settings->zone)
            : $now->setTimezone($settings->zone)->setTime(0, 0);
        $from = $day->setTimezone(new DateTimeZone('UTC'));
        $to = $day->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $statuses = $since === null
            ? BookingRepository::LIVE
            : [...BookingRepository::LIVE, BookingRepository::STATUS_CANCELLED, BookingRepository::STATUS_EXPIRED];
        $rows = array_map([BookingRepository::class, 'toApi'], $this->bookings->list($tenantId, [], $from, $to, $statuses, $unitId));
        if ($since !== null) {
            $rows = array_values(array_filter($rows, static fn (array $b): bool => is_string($b['updated_at'] ?? null) && Interval::fromApi($b['updated_at']) >= $since));
        }

        return [
            'date' => $day->format('Y-m-d'),
            'time_zone' => $settings->zone->getName(),
            'now' => Interval::toApi($now),
            'full' => $since === null,
            'bookings' => $this->withIntake($tenantId, $this->withCustomerFlags($tenantId, $rows)),
            // WHIT-1442 (AP.23): how many are on the waitlist.
            'waitlist_count' => $this->waitlist()->count($tenantId),
        ];
    }

    /**
     * A staff member is behind (WHIT-1441, §10.2, AP.29): the rest of their
     * bookings today (not started and not over yet), each with the time it would move to when pushed by
     * `$minutes`, or why it can't (it would run into something, outside
     * their hours, or a room or piece of equipment is taken then). Nothing
     * moves here. Read from the last booking back, so one that can't move
     * stops the ones before it from being pushed into it.
     *
     * @return list<array{booking: array<string, mixed>, to: ?string, reason: ?string}>
     */
    public function lateStaffPlan(int $tenantId, int $profileId, int $minutes): array
    {
        if ($minutes < 1 || $minutes > 240) {
            throw new CalendarRejectedException('minutes is from 1 to 240.', 422);
        }
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $zone = $this->zoneOf($tenantId, $profileId, $settings);
        $endOfDay = $now->setTimezone($zone)->setTime(0, 0)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $rows = array_values(array_filter(
            array_map([BookingRepository::class, 'toApi'], $this->bookings->list($tenantId, [$profileId], $now, $endOfDay, [BookingRepository::STATUS_CONFIRMED, BookingRepository::STATUS_REQUESTED])),
            // Not started yet, whether or not its time has come: a booking
            // waiting because they are behind is the one to push.
            static fn (array $b): bool => $b['started_at'] === null && $b['attendance'] !== BookingRepository::NO_SHOW && $b['items'] === [],
        ));
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
        if ($rows === []) {
            return [];
        }
        // Their busy time without the bookings that would move.
        $moving = [];
        foreach ($rows as $b) {
            $moving[$b['blocks_from'] . '|' . $b['blocks_until']] = true;
        }
        $busy = array_values(array_filter(
            $this->busyFor($tenantId, $profileId, $now->modify('-1 day'), $endOfDay->modify('+1 day'), $now, null),
            static fn (Interval $i): bool => !isset($moving[Interval::toApi($i->start) . '|' . Interval::toApi($i->end)]),
        ));
        $windows = Availability::openWindows(
            $this->calendar->hours($tenantId, $profileId),
            $this->calendar->exceptions($tenantId, $profileId, $now->modify('-1 day'), $endOfDay->modify('+1 day')),
            $zone,
            $now->modify('-1 day'),
            $endOfDay->modify('+1 day'),
        );

        $plan = [];
        foreach (array_reverse($rows) as $b) {
            $service = self::asBooked($this->service($tenantId, (int) $b['service_id'], false), ['starts_at' => $b['starts_at'], 'ends_at' => $b['ends_at'], 'blocks_until' => $b['blocks_until']]);
            $service['for_profile_id'] = $profileId;
            $length = (int) $service['duration_minutes'];
            $start = Interval::fromApi($b['starts_at']) ?? $now;
            $to = $start->modify('+' . $minutes . ' minutes');
            $reason = null;
            if (Availability::clashes($to, $length, (int) $service['buffer_before_minutes'], (int) $service['buffer_after_minutes'], $busy)) {
                $reason = CalendarRejectedException::PERSON_BUSY;
            } elseif (!Availability::withinWindows($to, $length, $windows)) {
                $reason = CalendarRejectedException::CLOSED;
            } elseif ($this->chooseFor($tenantId, $service, $to, $now, (int) $b['id'], $this->bookings->resourcesOf($tenantId, (int) $b['id'])[0] ?? []) === null) {
                $reason = CalendarRejectedException::ROOM_TAKEN;
            }
            if ($reason !== null) {
                // It stays where it is, so the ones before it can't run into it.
                $busy[] = new Interval(Interval::fromApi($b['blocks_from']) ?? $start, Interval::fromApi($b['blocks_until']) ?? $start);
            }
            $plan[] = ['booking' => $b, 'to' => $reason === null ? Interval::toApi($to) : null, 'reason' => $reason];
        }

        return array_reverse($plan);
    }

    /**
     * Move and tell them (WHIT-1441, AP.29): push each booking named in
     * `$bookingIds` that the plan can move by `$minutes`, from the last back,
     * each under the locks and with the customer told the new time. One that
     * can no longer move is left and said so; nothing else is touched.
     *
     * @param list<int> $bookingIds
     * @return list<array{id: int, moved: bool, to: ?string, reason: ?string}>
     */
    public function applyLateStaff(int $tenantId, int $profileId, int $minutes, array $bookingIds, ?int $actor): array
    {
        $out = [];
        foreach (array_reverse($this->lateStaffPlan($tenantId, $profileId, $minutes)) as $step) {
            $id = (int) $step['booking']['id'];
            if (!in_array($id, $bookingIds, true)) {
                continue;
            }
            if ($step['to'] === null) {
                $out[] = ['id' => $id, 'moved' => false, 'to' => null, 'reason' => $step['reason']];
                continue;
            }
            try {
                $this->move($tenantId, $id, Interval::fromApi($step['to']) ?? $this->now(), null, false, true);
                $out[] = ['id' => $id, 'moved' => true, 'to' => $step['to'], 'reason' => null];
            } catch (CalendarRejectedException $e) {
                $out[] = ['id' => $id, 'moved' => false, 'to' => null, 'reason' => is_string($e->extra['reason'] ?? null) ? $e->extra['reason'] : CalendarRejectedException::PERSON_BUSY];
            }
        }
        try {
            $this->audit?->record('calendar.desk.staff_late', [
                'tenant_id' => $tenantId,
                'actor_user_id' => $actor !== null && $actor > 0 ? $actor : null,
                'target_type' => 'profile',
                'target_id' => $profileId,
                'metadata' => ['minutes' => $minutes, 'moved' => array_column(array_filter($out, static fn (array $r): bool => $r['moved']), 'id')],
            ]);
        } catch (Throwable $e) {
            ScrubbedErrorLog::failure('[calendar] could not audit a running-late move', $e);
        }

        return array_reverse($out);
    }

    /**
     * Came, didn't come, or clear the mark (WHIT-1427, AP.3, AP.4). Only a
     * confirmed booking whose time has started; a mark can always be changed.
     * Who and when are kept and audited, and the change is announced.
     *
     * @return array<string, mixed>
     */
    public function markAttendance(int $tenantId, int $bookingId, ?string $attendance, ?int $actor): array
    {
        if ($attendance !== null && $attendance !== BookingRepository::ATTENDED && $attendance !== BookingRepository::NO_SHOW) {
            throw new CalendarRejectedException('attendance is attended, no_show or null.', 422);
        }
        $row = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if ($row['status'] !== BookingRepository::STATUS_CONFIRMED) {
            throw new CalendarRejectedException('Only a confirmed booking can be marked.', 422);
        }
        if ($attendance !== null && Interval::fromDb((string) $row['starts_at']) > $this->now()) {
            throw new CalendarRejectedException('This booking hasn\'t started yet. Check them in when they arrive.', 422);
        }
        if ($attendance === BookingRepository::NO_SHOW && $row['checked_in_at'] !== null) {
            // They didn't come after all: the check-in goes with the mark.
            $this->bookings->checkIn($tenantId, $bookingId, null);
        }
        if (($row['attendance'] ?? null) !== $attendance || \Whity\Core\Db\DbBool::of($row['attendance_auto'] ?? false)) {
            $this->recordAttendance($tenantId, $row, $attendance, $actor, false);
        }

        return $this->bookingOrFail($tenantId, $bookingId);
    }

    /**
     * End-of-day marking (WHIT-1427, AP.4, Q2): in each workspace that turned
     * it on, confirmed bookings nobody marked whose day has ended in the
     * workspace's zone become Didn't come, marked by Whity. Only bookings from
     * the last two days and since the switch was turned on. Returns how many.
     */
    public function autoMarkNoShows(): int
    {
        $marked = 0;
        foreach ($this->attendanceRepository()->autoNoShowWorkspaces() as $tenantId => $onSince) {
            try {
                $settings = $this->settingsFor($tenantId);
                if (!$settings->autoNoShow) {
                    continue;
                }
                $midnight = $this->now()->setTimezone($settings->zone)->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
                $from = $midnight->modify('-2 days');
                $on = $onSince !== '' ? Interval::fromDb($onSince) : $from;
                if ($on > $from) {
                    $from = $on;
                }
                foreach ($this->attendanceRepository()->unmarkedIds($tenantId, $from, $midnight) as $id) {
                    $row = $this->bookings->find($tenantId, $id);
                    if ($row !== null && $row['attendance'] === null && $this->recordAttendance($tenantId, $row, BookingRepository::NO_SHOW, null, true)) {
                        $marked++;
                    }
                }
            } catch (Throwable $e) {
                \Whity\Core\Log\ScrubbedErrorLog::failure('[calendar] end-of-day marking failed for workspace ' . $tenantId, $e);
            }
        }

        return $marked;
    }

    /**
     * Today's bookings that started at least the grace minutes ago and nobody
     * has marked: the desk's Did they come? list (WHIT-1427, AP.4).
     *
     * @return list<array<string, mixed>>
     */
    public function unmarkedToday(int $tenantId, ?string $date, ?int $unitId): array
    {
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $day = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? new DateTimeImmutable($date . ' 00:00:00', $settings->zone)
            : $now->setTimezone($settings->zone)->setTime(0, 0);
        $from = $day->setTimezone(new DateTimeZone('UTC'));
        $to = $day->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $cutoff = $now->modify('-' . $settings->noShowGraceMinutes . ' minutes');
        $out = [];
        foreach ($this->bookings->list($tenantId, [], $from, $to, [BookingRepository::STATUS_CONFIRMED], $unitId) as $row) {
            if (($row['attendance'] ?? null) === null && Interval::fromDb((string) $row['starts_at']) <= $cutoff) {
                $out[] = BookingRepository::toApi($row);
            }
        }

        return $this->withCustomerFlags($tenantId, $out);
    }

    /**
     * The flag "Missed 2 of the last 5" on each booking's customer (WHIT-1427,
     * AP.8, AP.10), as `customer_flag` {misses, of}, or null when the policy
     * does not apply. Counted over the window, or since the count started again.
     *
     * @param list<array<string, mixed>> $bookings {@see BookingRepository::toApi()}
     * @return list<array<string, mixed>>
     */
    public function withCustomerFlags(int $tenantId, array $bookings): array
    {
        $settings = $this->settingsFor($tenantId);
        if ($bookings === [] || $settings->noShowFlagAfter <= 0) {
            return array_map(static fn (array $b): array => $b + ['customer_flag' => null], $bookings);
        }
        $keys = [];
        foreach ($bookings as $b) {
            $key = AttendanceRepository::customerKey($b);
            if ($key !== null) {
                $keys[] = $key;
            }
        }
        $now = $this->now();
        $history = $this->attendanceRepository()->history($tenantId, $keys, $now->modify('-' . $settings->noShowWindowMonths . ' months'), $now, $settings->noShowCountLateCancels);

        return array_map(static function (array $b) use ($history, $settings): array {
            $key = AttendanceRepository::customerKey($b);
            $missedFlags = $key !== null ? ($history[$key] ?? []) : [];
            $misses = count(array_filter($missedFlags));
            $b['customer_flag'] = $misses >= $settings->noShowFlagAfter ? ['misses' => $misses, 'of' => count($missedFlags)] : null;

            return $b;
        }, $bookings);
    }

    /**
     * One customer's line and flag (WHIT-1427, AP.8): came, didn't come and
     * late cancels over all time, since their first booking, and the flag.
     *
     * @return array{came: int, no_show: int, late_cancels: int, since: ?string, flag: ?array{misses: int, of: int}, window_months: int}
     */
    public function customerAttendance(int $tenantId, string $key): array
    {
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $totals = $this->attendanceRepository()->customerTotals($tenantId, $key, $now);
        $history = $this->attendanceRepository()->history($tenantId, [$key], $now->modify('-' . $settings->noShowWindowMonths . ' months'), $now, $settings->noShowCountLateCancels)[$key] ?? [];
        $misses = count(array_filter($history));

        return [
            'came' => $totals['came'],
            'no_show' => $totals['no_show'],
            'late_cancels' => $totals['late_cancels'],
            'since' => $totals['first_at'],
            'flag' => $settings->noShowFlagAfter > 0 && $misses >= $settings->noShowFlagAfter ? ['misses' => $misses, 'of' => count($history)] : null,
            'window_months' => $settings->noShowWindowMonths,
        ];
    }

    /** "Start again from today" for a customer's count (AP.10), recorded and audited. */
    public function resetCustomerAttendance(int $tenantId, string $key, int $actor): void
    {
        $this->attendanceRepository()->reset($tenantId, $key, $this->now(), $actor);
        try {
            $this->audit?->record('calendar.attendance.reset', [
                'tenant_id' => $tenantId,
                'actor_user_id' => $actor > 0 ? $actor : null,
                'target_type' => 'contact',
                'target_id' => str_starts_with($key, 'c:') ? (int) substr($key, 2) : null,
                'metadata' => ['customer' => str_starts_with($key, 'c:') ? $key : 'email'],
            ]);
        } catch (Throwable $e) {
            \Whity\Core\Log\ScrubbedErrorLog::failure('[calendar] could not audit a count started again', $e);
        }
    }

    /**
     * The rates for a period (WHIT-1427, AP.6), in the workspace's zone.
     *
     * @param array{profile_id?: ?int, service_id?: ?int, customer?: ?string, source?: ?string} $filters
     * @return array<string, mixed>
     */
    public function attendanceRates(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to, array $filters): array
    {
        $settings = $this->settingsFor($tenantId);
        $cutoff = $this->now()->modify('-' . $settings->noShowGraceMinutes . ' minutes');

        return ['from' => Interval::toApi($from), 'to' => Interval::toApi($to)]
            + $this->attendanceRepository()->counts($tenantId, $from, $to, $cutoff, $filters);
    }

    /**
     * Save a mark, then audit and announce it. False when the booking is no
     * longer confirmed.
     *
     * @param array<string, mixed> $row as {@see BookingRepository::find()} returns it
     */
    private function recordAttendance(int $tenantId, array $row, ?string $attendance, ?int $actor, bool $auto): bool
    {
        $id = (int) $row['id'];
        if (!$this->bookings->setAttendance($tenantId, $id, $attendance, $this->now(), $actor !== null && $actor > 0 ? $actor : null, $auto)) {
            return false;
        }
        $previous = is_string($row['attendance'] ?? null) ? $row['attendance'] : null;
        try {
            $this->audit?->record('calendar.attendance.marked', [
                'tenant_id' => $tenantId,
                'actor_user_id' => $actor !== null && $actor > 0 ? $actor : null,
                'target_type' => 'booking',
                'target_id' => $id,
                'metadata' => ['attendance' => $attendance, 'previous' => $previous, 'auto' => $auto],
            ]);
        } catch (Throwable $e) {
            \Whity\Core\Log\ScrubbedErrorLog::failure('[calendar] could not audit an attendance mark', $e);
        }
        $this->announce(CalendarEventNames::ATTENDANCE_MARKED, [
            'id' => $id,
            'tenant_id' => $tenantId,
            'profile_id' => (int) $row['profile_id'],
            'service_id' => (int) $row['service_id'],
            'attendance' => $attendance,
            'previous' => $previous,
            'auto' => $auto,
            'acting_profile_id' => $actor !== null && $actor > 0 ? $actor : null,
        ]);

        return true;
    }

    /**
     * The Today panel for one local day (design 3.3).
     *
     * @return array{date: string, booked: int, checked_in: int, requests: int, reminders_sent: int}
     */
    public function today(int $tenantId, ?string $date, ?int $unitId): array
    {
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $day = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? new DateTimeImmutable($date . ' 00:00:00', $settings->zone)
            : $now->setTimezone($settings->zone)->setTime(0, 0);
        $from = $day->setTimezone(new DateTimeZone('UTC'));
        $to = $day->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));

        return ['date' => $day->format('Y-m-d')] + $this->bookings->today($tenantId, $from, $to, $now, $unitId);
    }

    /**
     * Move a live booking or request under the lock, without telling anyone.
     *
     * @return array<string, mixed>
     */
    private function moveUnderLock(int $tenantId, int $bookingId, DateTimeImmutable $start, ?int $profileId, bool $enforceHours, bool $bookAnyway = false, bool $withinHours = false): array
    {
        $current = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if (!in_array($current['status'], BookingRepository::LIVE, true)) {
            throw new CalendarRejectedException('Only a booking that is still on can be moved.', 422);
        }
        if (is_array($current['items'] ?? null) && $current['items'] !== []) {
            return $this->moveManyUnderLock($tenantId, $current, $start, $profileId, $enforceHours, $bookAnyway, $withinHours);
        }
        $isRequest = $current['status'] === BookingRepository::STATUS_REQUESTED;
        $service = $this->service($tenantId, (int) $current['service_id'], false);
        // A move keeps the booking's own length and buffer after, so one made
        // before the service changed, or with a person's own length
        // (WHIT-1440, AP.19), keeps the time it was booked for.
        $service = self::asBooked($service, $current);
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $from = (int) $current['profile_id'];
        $to = $profileId ?? $from;
        if ($to !== $from && !in_array($to, $this->candidates($tenantId, $service, $to, false), true)) {
            throw new CalendarRejectedException('That person does not offer this service.', 422);
        }
        // WHIT-1440 (AP.16): a group's resource from the branch of whoever has it.
        $service['for_profile_id'] = $to;
        if ($enforceHours) {
            $this->assertInWindow($start, $settings, $now);
        }

        $locks = array_values(array_unique([$from, $to]));
        sort($locks);
        $end = $start->modify('+' . $service['duration_minutes'] . ' minutes');
        $block = Availability::blockFor($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes']);
        $why = null;
        $moved = $this->underLock(array_map(static fn (int $p): array => [$tenantId, $p], $locks), function () use ($tenantId, $bookingId, $to, $service, $settings, $start, $end, $block, $now, $enforceHours, $isRequest, $bookAnyway, $withinHours, &$why): bool {
            $why = $this->whyNotFree($tenantId, $to, $service, $settings, $start, $now, $bookingId, $enforceHours || $withinHours, $bookAnyway);
            if ($why !== null) {
                return false;
            }
            $overlapOk = $bookAnyway && $this->personClashes($tenantId, $to, $service, $start, $now, $bookingId);
            // WHIT-1439 (AP.18): keep what it held when that is still free.
            $chosen = $this->chooseFor($tenantId, $service, $start, $now, $bookingId, $this->bookings->resourcesOf($tenantId, $bookingId)[0] ?? []) ?? [];
            $this->bookings->move($tenantId, $bookingId, $to, $start, $end, $block, $isRequest ? null : $this->reminderDue($start, $settings, $now), $overlapOk);
            $this->bookings->setResources($tenantId, $bookingId, self::resourceRows([$chosen], [$block]));

            return true;
        }, $this->roomLock($tenantId, $service));
        if (!$moved) {
            throw is_string($why) ? CalendarRejectedException::notFree($why) : CalendarRejectedException::taken();
        }

        return $this->afterWrite($tenantId, $bookingId);
    }

    /**
     * Move a booking of several services (WHIT-1173): every service moves by
     * the same amount and stays back to back. Each keeps its person, unless
     * `$profileId` names one person for them all, who must do every one.
     * Every person before and after is locked in profile-id order, then the
     * rooms.
     *
     * @param array<string, mixed> $current the booking row, with its `items`
     * @return array<string, mixed>
     */
    private function moveManyUnderLock(int $tenantId, array $current, DateTimeImmutable $start, ?int $profileId, bool $enforceHours, bool $bookAnyway, bool $withinHours): array
    {
        $bookingId = (int) $current['id'];
        $isRequest = $current['status'] === BookingRepository::STATUS_REQUESTED;
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        if ($enforceHours) {
            $this->assertInWindow($start, $settings, $now);
        }
        $shift = $start->getTimestamp() - Interval::fromDb((string) $current['starts_at'])->getTimestamp();
        $parts = [];
        $services = [];
        $people = [];
        /** @var list<array<string, mixed>> $items */
        $items = $current['items'];
        foreach ($items as $item) {
            $service = $this->service($tenantId, (int) $item['service_id'], false);
            $from = Interval::fromDb((string) $item['starts_at']);
            // Each keeps the length and buffer after it was booked with.
            $service = self::asBooked($service, $item);
            $was = (int) $item['profile_id'];
            $person = $profileId ?? $was;
            if ($person !== $was && !in_array($person, $this->candidates($tenantId, $service, $person, false), true)) {
                throw new CalendarRejectedException('That person does not offer all of these services.', 422);
            }
            $service['for_profile_id'] = $person;
            $at = (new DateTimeImmutable('@' . ($from->getTimestamp() + $shift)))->setTimezone(new DateTimeZone('UTC'));
            $parts[] = [
                'service_id' => (int) $item['service_id'],
                'profile_id' => $person,
                'room_id' => isset($item['room_id']) ? (int) $item['room_id'] : null,
                'starts_at' => $at,
                'ends_at' => $at->modify('+' . $service['duration_minutes'] . ' minutes'),
                'block' => Availability::blockFor($at, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes']),
                'price_minor' => $item['price_minor'] !== null ? (int) $item['price_minor'] : null,
            ];
            $services[] = $service;
            $people[] = $was;
            $people[] = $person;
        }
        if ($parts === []) {
            throw CalendarRejectedException::notFound('Booking');
        }

        $why = null;
        $moved = $this->underLocks($tenantId, $people, array_merge(self::roomsOf($parts), $this->resourceIds($tenantId, $services)), function () use ($tenantId, $bookingId, $parts, $services, $settings, $start, $now, $enforceHours, $withinHours, $isRequest, $bookAnyway, &$why): bool {
            $overlapOk = false;
            $held = $this->bookings->resourcesOf($tenantId, $bookingId);
            $chosen = [];
            foreach ($parts as $k => $part) {
                $why = $this->whyNotFree($tenantId, $part['profile_id'], $services[$k], $settings, $part['starts_at'], $now, $bookingId, $enforceHours || $withinHours, $bookAnyway);
                if ($why !== null) {
                    return false;
                }
                $overlapOk = $overlapOk || ($bookAnyway && $this->personClashes($tenantId, $part['profile_id'], $services[$k], $part['starts_at'], $now, $bookingId));
                $chosen[$k] = $this->chooseFor($tenantId, $services[$k], $part['starts_at'], $now, $bookingId, $held[$k] ?? []) ?? [];
                $parts[$k]['room_id'] = $chosen[$k][0] ?? null;
            }
            $this->bookings->move($tenantId, $bookingId, $parts[0]['profile_id'], $start, $parts[count($parts) - 1]['ends_at'], self::span($parts), $isRequest ? null : $this->reminderDue($start, $settings, $now), $overlapOk);
            $this->bookings->moveItems($tenantId, $bookingId, $parts);
            $this->bookings->setResources($tenantId, $bookingId, self::resourceRows($chosen, array_column($parts, 'block')));

            return true;
        });
        if (!$moved) {
            throw is_string($why) ? CalendarRejectedException::notFree($why) : CalendarRejectedException::taken();
        }

        return $this->afterWrite($tenantId, $bookingId);
    }

    /**
     * WHIT-1427 (AP.5): `$for` says whose cancel it is when staff cancel:
     * `customer` (the customer asked) counts early or late by the window,
     * `business` (we cancelled) never counts, and null records no kind. A
     * customer cancelling on their link is always theirs.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $tenantId, int $bookingId, string $by, string $reason, ?string $for = null): array
    {
        $row = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if ($for !== null && $for !== 'customer' && $for !== 'business') {
            throw new CalendarRejectedException('cancelled_for is customer or business.', 422);
        }
        $kind = null;
        if ($by === 'customer' || $for === 'customer') {
            // Only a confirmed booking counts: a request or an unpaid hold was never due.
            if ($row['status'] === BookingRepository::STATUS_CONFIRMED) {
                $hours = $this->settingsFor($tenantId)->lateCancelHours;
                $late = Interval::fromDb((string) $row['starts_at'])->getTimestamp() - $this->now()->getTimestamp() < $hours * 3600;
                $kind = $late ? BookingRepository::CANCEL_LATE : BookingRepository::CANCEL_EARLY;
            }
        } elseif ($for === 'business') {
            $kind = BookingRepository::CANCEL_BUSINESS;
        }
        $done = $this->bookings->transition(
            $tenantId,
            $bookingId,
            BookingRepository::LIVE,
            BookingRepository::STATUS_CANCELLED,
            ['cancelled_by' => $by, 'cancel_reason' => mb_substr($reason, 0, 500), 'cancel_kind' => $kind],
        );
        if (!$done) {
            throw new CalendarRejectedException('This booking is already cancelled or has ended.', 422);
        }
        $this->links?->revoke($tenantId, $bookingId);
        $booking = $this->bookingOrFail($tenantId, $bookingId);
        $this->announceBooking(CalendarEventNames::BOOKING_CANCELLED, $tenantId, $booking);
        $this->tell('cancelled', $tenantId, $booking);
        // WHIT-1442 (AP.23): a cancel, or "I can't come", frees the time for the waitlist.
        $this->offerFreed($tenantId, $row);

        return $booking;
    }

    /**
     * A staff event. Busy events take the person's lock so a booking made at
     * the same moment sees them; an event may sit over an existing booking
     * (it is the person's own choice) and the answer lists those bookings.
     *
     * People invited (ADR 0018) see it on their calendar and are busy for it
     * unless they decline; a room it is held in is blocked for it.
     *
     * @param list<int>|null $people who is invited besides the organiser
     * @return array{event: array<string, mixed>, overlapping_bookings: list<int>}
     */
    public function createEvent(int $tenantId, int $profileId, string $title, string $notes, Interval $when, bool $allDay, bool $busy, ?int $actor, ?string $externalRef = null, ?EventPlace $place = null, ?array $people = null, ?string $kind = null): array
    {
        $id = $this->underLock([[$tenantId, $profileId]], function () use ($tenantId, $profileId, $title, $notes, $when, $allDay, $busy, $externalRef, $actor, $place, $people, $kind): int {
            $id = $this->calendar->insertEvent($tenantId, $profileId, $title, $notes, $when, $allDay, $busy, $externalRef, $actor, $place, $kind);
            if ($people !== null) {
                $this->calendar->setEventPeople($tenantId, $id, $people, $profileId);
            }

            return $id;
        });
        $event = $this->calendar->findEvent($tenantId, $id) ?? throw CalendarRejectedException::notFound('Event');
        // ADR 0018: people invited hear about it (in-app, push, email with an .ics).
        $this->invites?->invited($tenantId, $id, array_column($event['people'], 'profile_id'));
        $this->reminders?->schedule($tenantId, $id);
        $this->announce(CalendarEventNames::EVENT_CREATED, ['id' => $id, 'tenant_id' => $tenantId, 'profile_id' => $profileId]);

        return ['event' => $event, 'overlapping_bookings' => $this->overlapping($tenantId, $profileId, $when)];
    }

    /**
     * @param list<int>|null $people who is invited besides the organiser; null keeps the list
     * @return array{event: array<string, mixed>, overlapping_bookings: list<int>}
     */
    public function updateEvent(int $tenantId, int $eventId, string $title, string $notes, Interval $when, bool $allDay, bool $busy, ?EventPlace $place = null, ?array $people = null): array
    {
        $existing = $this->calendar->findEvent($tenantId, $eventId) ?? throw CalendarRejectedException::notFound('Event');
        $profileId = (int) $existing['profile_id'];
        $this->underLock([[$tenantId, $profileId]], function () use ($tenantId, $eventId, $title, $notes, $when, $allDay, $busy, $place, $people, $profileId): bool {
            $this->calendar->updateEvent($tenantId, $eventId, $title, $notes, $when, $allDay, $busy, $place);
            if ($people !== null) {
                $this->calendar->setEventPeople($tenantId, $eventId, $people, $profileId);
            }

            return true;
        });
        $this->announce(CalendarEventNames::EVENT_UPDATED, ['id' => $eventId, 'tenant_id' => $tenantId, 'profile_id' => $profileId]);
        $this->reminders?->schedule($tenantId, $eventId);
        if ($this->invites !== null) {
            $fresh = $this->calendar->findEvent($tenantId, $eventId);
            if ($fresh !== null) {
                $before = array_column($existing['people'], 'profile_id');
                $added = array_values(array_diff(array_column($fresh['people'], 'profile_id'), $before));
                $moved = $fresh['starts_at'] !== $existing['starts_at'] || $fresh['ends_at'] !== $existing['ends_at']
                    || $fresh['location'] !== $existing['location'] || $fresh['room_id'] !== $existing['room_id'];
                if ($moved && $before !== []) {
                    $this->invites->changed($tenantId, $eventId);
                }
                $this->invites->invited($tenantId, $eventId, $added);
            }
        }

        return [
            'event' => $this->calendar->findEvent($tenantId, $eventId) ?? $existing,
            'overlapping_bookings' => $this->overlapping($tenantId, $profileId, $when),
        ];
    }

    public function cancelEvent(int $tenantId, int $eventId): void
    {
        $existing = $this->calendar->findEvent($tenantId, $eventId) ?? throw CalendarRejectedException::notFound('Event');
        $this->calendar->cancelEvent($tenantId, $eventId);
        if ($existing['people'] !== []) {
            $this->invites?->cancelled($tenantId, $eventId);
        }
        $this->announce(CalendarEventNames::EVENT_DELETED, ['id' => $eventId, 'tenant_id' => $tenantId, 'profile_id' => (int) $existing['profile_id']]);
    }

    /**
     * A payment step says the customer paid (SDK 1.98, {@see \Whity\Sdk\Calendar\Bookings}).
     * A booking whose hold lapsed is confirmed only if its time is still free,
     * checked under the person's lock.
     */
    public function confirmPaid(int $tenantId, int $bookingId, string $paymentRef): bool
    {
        $row = $this->bookings->find($tenantId, $bookingId);
        if ($row === null || !in_array($row['status'], [BookingRepository::STATUS_PENDING_PAYMENT, BookingRepository::STATUS_EXPIRED], true)) {
            return false;
        }
        $service = $this->services->find($tenantId, (int) $row['service_id']);
        if ($service === null) {
            return false;
        }
        $settings = $this->settingsFor($tenantId);
        $start = Interval::fromDb((string) $row['starts_at']);
        if (is_array($row['items'] ?? null) && $row['items'] !== []) {
            $done = $this->confirmPaidMany($tenantId, $bookingId, array_values($row['items']), $settings, $paymentRef);
            if ($done) {
                $booking = $this->bookingOrFail($tenantId, $bookingId);
                $this->announceBooking(CalendarEventNames::BOOKING_CONFIRMED, $tenantId, $booking);
                $this->tell('confirmed', $tenantId, $booking);
            }

            return $done;
        }
        $service = self::asBooked($service, $row);
        $done = $this->settleHeld(fn (): bool => $this->underLock([[$tenantId, (int) $row['profile_id']]], function () use ($tenantId, $bookingId, $row, $service, $settings, $start, $paymentRef): bool {
            if ($this->whyNotFree($tenantId, (int) $row['profile_id'], $service, $settings, $start, $this->now(), $bookingId, false) !== null) {
                return false;
            }

            return $this->bookings->transition(
                $tenantId,
                $bookingId,
                [BookingRepository::STATUS_PENDING_PAYMENT, BookingRepository::STATUS_EXPIRED],
                BookingRepository::STATUS_CONFIRMED,
                ['payment_ref' => mb_substr($paymentRef, 0, 255)],
            );
        }, $this->roomLock($tenantId, $service)));
        if (!$done) {
            return false;
        }
        $booking = $this->bookingOrFail($tenantId, $bookingId);
        $this->announceBooking(CalendarEventNames::BOOKING_CONFIRMED, $tenantId, $booking);
        $this->tell('confirmed', $tenantId, $booking);

        return true;
    }

    /**
     * Confirm a paid booking of several services: each service's time is
     * checked again under every person's and room's lock.
     *
     * @param list<array<string, mixed>> $items
     */
    private function confirmPaidMany(int $tenantId, int $bookingId, array $items, CalendarSettings $settings, string $paymentRef): bool
    {
        $checks = [];
        $people = [];
        $rooms = [];
        foreach ($items as $item) {
            $service = $this->services->find($tenantId, (int) $item['service_id']);
            if ($service === null) {
                return false;
            }
            $at = Interval::fromDb((string) $item['starts_at']);
            $checks[] = [(int) $item['profile_id'], self::asBooked($service, $item), $at];
            $people[] = (int) $item['profile_id'];
            if (isset($item['room_id'])) {
                $rooms[] = (int) $item['room_id'];
            }
        }

        return $this->settleHeld(fn (): bool => $this->underLocks($tenantId, $people, $rooms, function () use ($tenantId, $bookingId, $checks, $settings, $paymentRef): bool {
            foreach ($checks as [$person, $service, $at]) {
                if ($this->whyNotFree($tenantId, $person, $service, $settings, $at, $this->now(), $bookingId, false) !== null) {
                    return false;
                }
            }

            return $this->bookings->transition(
                $tenantId,
                $bookingId,
                [BookingRepository::STATUS_PENDING_PAYMENT, BookingRepository::STATUS_EXPIRED],
                BookingRepository::STATUS_CONFIRMED,
                ['payment_ref' => mb_substr($paymentRef, 0, 255)],
            );
        }));
    }

    /**
     * A late payment whose time the database finds taken (AP.11) is simply
     * not confirmed, as when the service's own check finds it taken.
     *
     * @param callable(): bool $confirm
     */
    private function settleHeld(callable $confirm): bool
    {
        try {
            return $confirm();
        } catch (CalendarRejectedException $e) {
            if (!HoldConflicts::isHeld($e)) {
                throw $e;
            }

            return false;
        }
    }

    /** A payment step gives up on a held booking: cancel it and free its time. */
    public function releaseHold(int $tenantId, int $bookingId, string $reason): bool
    {
        $done = $this->bookings->transition(
            $tenantId,
            $bookingId,
            [BookingRepository::STATUS_PENDING_PAYMENT],
            BookingRepository::STATUS_CANCELLED,
            ['cancelled_by' => 'payment', 'cancel_reason' => mb_substr($reason, 0, 500)],
        );
        if ($done) {
            $this->links?->revoke($tenantId, $bookingId);
            $booking = $this->bookingOrFail($tenantId, $bookingId);
            $this->announceBooking(CalendarEventNames::BOOKING_CANCELLED, $tenantId, $booking);
        }

        return $done;
    }

    /** A hold nobody settled: expire it once its time is up (the queued job's work). */
    public function expireHold(int $tenantId, int $bookingId): bool
    {
        $row = $this->bookings->find($tenantId, $bookingId);
        if ($row === null || $row['status'] !== BookingRepository::STATUS_PENDING_PAYMENT || $row['hold_expires_at'] === null
            || Interval::fromDb((string) $row['hold_expires_at']) > $this->now()) {
            return false;
        }
        if (!$this->bookings->transition($tenantId, $bookingId, [BookingRepository::STATUS_PENDING_PAYMENT], BookingRepository::STATUS_EXPIRED)) {
            return false;
        }
        $this->announceBooking(CalendarEventNames::BOOKING_EXPIRED, $tenantId, $this->bookingOrFail($tenantId, $bookingId));

        return true;
    }

    /**
     * Hand a held booking to the payment step and queue its expiry.
     *
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private function startPayment(int $tenantId, array $booking, BookingQuote $quote, PaymentRequirement $requirement, DateTimeImmutable $hold, BookingPaymentStep $step): array
    {
        $bookingId = (int) $booking['id'];
        $token = is_string($booking['manage_token'] ?? null) ? $booking['manage_token'] : '';
        $returnUrl = rtrim($this->manageUrlBase, '/') . ($token !== '' ? '/' . $token : '');
        try {
            $handoff = $step->start(new PendingBooking($tenantId, $bookingId, $quote, $requirement, $hold), $returnUrl);
        } catch (Throwable $e) {
            error_log('[calendar] the payment step could not start: ' . $e->getMessage());
            $this->releaseHold($tenantId, $bookingId, 'The payment could not be started.');
            throw new CalendarRejectedException('Payment is unavailable right now. Try again later.', 503);
        }
        if ($this->queue !== null) {
            try {
                BookingHoldExpiryJob::schedule($this->queue, $tenantId, $bookingId, $hold, $this->now());
            } catch (Throwable $e) {
                error_log('[calendar] could not queue a hold expiry: ' . $e->getMessage());
            }
        }
        $this->announceBooking(CalendarEventNames::BOOKING_CREATED, $tenantId, $booking);
        $this->tell('awaitingPayment', $tenantId, $booking);
        $booking['payment'] = [
            'url' => $handoff->url,
            'amount_minor' => $requirement->amountMinor,
            'currency' => $requirement->currency,
            'label' => $requirement->label,
            'hold_expires_at' => Interval::toApi($hold),
        ];

        return $booking;
    }

    /** @return array<string, mixed> the booking with its service, for the API */
    public function bookingOrFail(int $tenantId, int $id): array
    {
        $row = $this->bookings->find($tenantId, $id) ?? throw CalendarRejectedException::notFound('Booking');
        $booking = BookingRepository::toApi($row);
        $booking['service'] = $this->services->find($tenantId, (int) $row['service_id']);
        $booking['staff_name'] = $this->calendar->displayName((int) $row['profile_id']);
        // WHIT-1417 (AP.14): every reminder sent, with its channel and outcome.
        $booking['reminders'] = $this->bookings->reminderLog($tenantId, $id);
        // WHIT-1427 (AP.8): the customer's flag.
        $booking = $this->withCustomerFlags($tenantId, [$booking])[0];
        // WHIT-1443 (AP.20): the intake forms it needs, done or not.
        $booking['intake'] = $this->intake()->statusFor($tenantId, [$booking])[$id] ?? ['status' => IntakeForms::NOT_NEEDED, 'forms' => []];
        // WHIT-1439 (AP.18): every resource it holds, by part.
        $booking['resources'] = [];
        foreach ($this->bookings->resourcesOf($tenantId, $id) as $part => $resources) {
            foreach ($resources as $resource) {
                $booking['resources'][] = ['part' => $part, 'resource_id' => $resource];
            }
        }
        // WHIT-1173: a booking of several services reads as one to everything
        // that shows a booking's service (the emails, reminders, calendar
        // files, the customer's page): their names joined, the whole length
        // and the total price, and everyone doing them. `items` lists each.
        if (count($booking['items']) > 1) {
            $minutes = 0;
            foreach ($booking['items'] as $item) {
                $minutes += $item['duration_minutes'];
            }
            if (is_array($booking['service'])) {
                $booking['service']['name'] = BookingRepository::joinedNames($booking['items']);
                $booking['service']['duration_minutes'] = $minutes;
                $booking['service']['price_minor'] = $booking['price_minor'];
            }
            $booking['staff_name'] = BookingRepository::joinedStaff($booking['items']);
        }

        return $booking;
    }

    /**
     * Send a booking's reminder if it is still due (the queued job's and the
     * sweep's work). Returns what happened, or `['due' => …]` when it is not
     * due yet and the job must be queued again.
     *
     * WHIT-1417 (AP.14, AP.15): a booking has several reminders
     * (`calendar.reminders`, 24 and 2 hours before by default).
     * `reminder_due_at` is always the next one; sending it moves the booking
     * on to the one after, in the same statement that claims it, so the
     * queued job and the sweep never both send it. Each channel's outcome is
     * kept in `calendar_booking_reminders` for the desk.
     *
     * @return string|array{due: DateTimeImmutable}
     */
    public function sendReminder(int $tenantId, int $bookingId, string $startsAt): string|array
    {
        $row = $this->bookings->find($tenantId, $bookingId);
        if ($row === null || $row['status'] !== BookingRepository::STATUS_CONFIRMED) {
            return 'not on';
        }
        $start = Interval::fromDb((string) $row['starts_at']);
        if (Interval::toApi($start) !== $startsAt) {
            return 'moved';
        }
        if ($row['reminder_due_at'] === null) {
            return 'nothing due';
        }
        $due = Interval::fromDb((string) $row['reminder_due_at']);
        $now = $this->now();
        if ($due->getTimestamp() > $now->getTimestamp() + 60) {
            return ['due' => $due];
        }
        if ($start <= $now) {
            // Too late to remind anyone; stop it being found again.
            $this->bookings->claimReminder($tenantId, $bookingId, $due, null, $now);

            return 'too late';
        }
        $next = $this->reminderDue($start, $this->settingsFor($tenantId), $now);
        // Claimed before sending, so two workers never both send it.
        if (!$this->bookings->claimReminder($tenantId, $bookingId, $due, $next, $now)) {
            return 'already sent';
        }
        $hoursBefore = (int) round(($start->getTimestamp() - $due->getTimestamp()) / 3600);
        $booking = $this->bookingOrFail($tenantId, $bookingId);
        $email = trim((string) ($booking['customer']['email'] ?? ''));
        $phone = trim((string) ($booking['customer']['phone'] ?? ''));
        if ($this->links !== null && ($email !== '' || $phone !== '')) {
            // A fresh link for I'm coming and I can't come; the reminder is now
            // the customer's latest message, so it carries the link that works.
            $booking['manage_token'] = $this->links->issue($tenantId, $bookingId, $start);
            if ($this->manageUrlBase !== '') {
                $booking['manage_url'] = rtrim($this->manageUrlBase, '/') . '/' . $booking['manage_token'];
            }
        }

        $emailed = BookingRepository::REMINDER_NO_ADDRESS;
        if ($email !== '') {
            try {
                if ($this->messenger instanceof ReportsReminderDelivery) {
                    $emailed = $this->messenger->remindReported($tenantId, $booking);
                } else {
                    $this->messenger->remind($tenantId, $booking);
                    $emailed = BookingRepository::REMINDER_SENT;
                }
            } catch (Throwable $e) {
                \Whity\Core\Log\ScrubbedErrorLog::failure('[calendar] could not send the reminder email', $e);
                $emailed = BookingRepository::REMINDER_FAILED;
            }
        }
        $this->logReminder($tenantId, $bookingId, $hoursBefore, 'email', $emailed, $now);
        // C4: and by text on every channel a plugin adds (design 3.3: WhatsApp
        // is paid), through the one way Whity messages customers.
        try {
            $texts = ($this->textReminders ??= BookingTextReminders::fromApp($this->settings))->remind($tenantId, $booking);
            foreach ($texts as $channel => $outcome) {
                $this->logReminder($tenantId, $bookingId, $hoursBefore, $channel, $outcome, $now);
            }
        } catch (Throwable $e) {
            error_log('[calendar] the text reminders failed: ' . $e->getMessage());
        }

        if ($next !== null && $this->queue !== null) {
            try {
                BookingReminderJob::schedule($this->queue, $tenantId, $bookingId, $startsAt, $next, $now);
            } catch (Throwable $e) {
                // The sweep sends it anyway.
                \Whity\Core\Log\ScrubbedErrorLog::failure('[calendar] could not queue the next reminder', $e);
            }
        }

        return 'sent';
    }

    /**
     * Reminders that are due and unsent, in every workspace, for the sweep
     * (WHIT-1417, AP.14).
     *
     * @return list<array{tenant_id: int, id: int, starts_at: string}>
     */
    public function dueReminders(int $limit = 500): array
    {
        return array_map(
            static fn (array $r): array => ['tenant_id' => $r['tenant_id'], 'id' => $r['id'], 'starts_at' => Interval::toApi(Interval::fromDb($r['starts_at']))],
            $this->bookings->dueReminders($this->now(), $limit),
        );
    }

    /**
     * The desk changes the resources a booking of one service holds (WHIT-1439,
     * AP.18): one for each need, in order, each one that can meet that need
     * and is free then. Under the locks, like a booking.
     *
     * @param list<int> $resourceIds
     * @return array<string, mixed>
     */
    public function changeResources(int $tenantId, int $bookingId, array $resourceIds): array
    {
        $row = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if (!in_array($row['status'], BookingRepository::LIVE, true)) {
            throw new CalendarRejectedException('Only a booking that is still on can change its room or equipment.', 422);
        }
        if ($this->bookings->itemsFor($tenantId, [$bookingId]) !== []) {
            throw new CalendarRejectedException('A booking of several services keeps the room and equipment chosen for each.', 422);
        }
        $service = $this->service($tenantId, (int) $row['service_id'], false);
        $needs = $this->needs($tenantId, $service);
        if (count($resourceIds) !== count($needs) || count(array_unique($resourceIds)) !== count($resourceIds)) {
            throw new CalendarRejectedException('Choose one room or piece of equipment for each thing the service needs.', 422);
        }
        foreach ($needs as $k => $candidates) {
            if (!in_array($resourceIds[$k], $candidates, true)) {
                throw new CalendarRejectedException('That room or equipment can\'t be used for this service.', 422);
            }
        }
        $block = new Interval(Interval::fromDb((string) $row['blocks_from']), Interval::fromDb((string) $row['blocks_until']));
        $now = $this->now();
        $ok = $this->underLock([[$tenantId, (int) $row['profile_id']]], function () use ($tenantId, $bookingId, $resourceIds, $block, $now): bool {
            $busyOf = $this->busyReader($tenantId, $block->start->modify('-1 day'), $block->end->modify('+1 day'), $now, $bookingId);
            if (self::choose(array_map(static fn (int $r): array => [$r], $resourceIds), $block, $busyOf) === null) {
                return false;
            }
            $this->bookings->setResources($tenantId, $bookingId, self::resourceRows([$resourceIds], [$block]));

            return true;
        }, array_map(static fn (int $r): array => [$tenantId, $r], self::sorted($resourceIds)));
        if (!$ok) {
            throw CalendarRejectedException::notFree(CalendarRejectedException::ROOM_TAKEN);
        }

        return $this->bookingOrFail($tenantId, $bookingId);
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * The customer pressed I'm coming on their page (WHIT-1417, AP.15).
     * Pressing it again changes nothing.
     *
     * @return array<string, mixed>
     */
    public function confirmByCustomer(int $tenantId, int $bookingId): array
    {
        $row = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if ($row['status'] !== BookingRepository::STATUS_CONFIRMED) {
            throw new CalendarRejectedException('This booking is not confirmed, so there is nothing to answer.', 409);
        }
        $this->bookings->confirmByCustomer($tenantId, $bookingId, $this->now());

        return $this->bookingOrFail($tenantId, $bookingId);
    }

    private function logReminder(int $tenantId, int $bookingId, int $hoursBefore, string $channel, string $outcome, DateTimeImmutable $now): void
    {
        try {
            $this->bookings->logReminder($tenantId, $bookingId, $hoursBefore, $channel, $outcome, $now);
        } catch (Throwable $e) {
            \Whity\Core\Log\ScrubbedErrorLog::failure('[calendar] could not keep the reminder\'s outcome', $e);
        }
    }

    /**
     * The booking after a create or a move: a fresh manage link when the
     * customer gave an email, and the reminder queued.
     *
     * @return array<string, mixed>
     */
    private function afterWrite(int $tenantId, int $bookingId): array
    {
        $booking = $this->bookingOrFail($tenantId, $bookingId);
        $start = Interval::fromApi($booking['starts_at']) ?? $this->now();
        if ($this->links !== null && $booking['customer']['email'] !== '') {
            $booking['manage_token'] = $this->links->issue($tenantId, $bookingId, $start);
        }
        if ($this->queue !== null) {
            $row = $this->bookings->find($tenantId, $bookingId);
            $due = $row !== null && $row['reminder_due_at'] !== null ? Interval::fromDb((string) $row['reminder_due_at']) : null;
            try {
                BookingReminderJob::schedule($this->queue, $tenantId, $bookingId, (string) $booking['starts_at'], $due, $this->now());
            } catch (Throwable $e) {
                error_log('[calendar] could not queue a reminder: ' . $e->getMessage());
            }
        }

        return $booking;
    }

    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function service(int $tenantId, int $serviceId, bool $publicOnly): array
    {
        $service = $this->services->find($tenantId, $serviceId);
        if ($service === null || !$service['active'] || ($publicOnly && !$service['bookable_online'])) {
            throw CalendarRejectedException::notFound('Service');
        }

        return $service;
    }

    /**
     * Who may take this booking, in the fixed order "anyone" tries them.
     *
     * @param array<string, mixed> $service
     * @return list<int>
     */
    private function candidates(int $tenantId, array $service, ?int $profileId, bool $publicOnly): array
    {
        $offered = $service['staff_profile_ids'];
        $order = [];
        foreach ($this->calendar->listStaff($tenantId) as $i => $staff) {
            if ($publicOnly && !$staff['bookable']) {
                continue;
            }
            $order[$staff['profile_id']] = $i;
        }
        $result = [];
        foreach ($offered as $id) {
            if (($profileId === null || $profileId === $id) && isset($order[$id]) && $this->calendar->isStaff($tenantId, $id)) {
                $result[] = $id;
            }
        }
        usort($result, static fn (int $a, int $b): int => [$order[$a], $a] <=> [$order[$b], $b]);

        return $result;
    }

    /**
     * @param array<string, mixed> $service
     * @return list<DateTimeImmutable>
     */
    private function slotsFor(int $tenantId, int $profileId, array $service, CalendarSettings $settings, DateTimeImmutable $earliest, DateTimeImmutable $latest, DateTimeImmutable $now, ?int $exceptBookingId): array
    {
        $from = $earliest->modify('-1 day');
        $to = $latest->modify('+1 day');
        // Windows are built from the start of the local day, not from
        // "now plus notice", so free times stay on the working-hours grid
        // (09:00, 09:15, ...) instead of starting at an odd minute and second;
        // slots() then drops the ones before `$earliest`.
        // WHIT-1440 (AP.16): in the zone of the person's branch.
        $zone = $this->zoneOf($tenantId, $profileId, $settings);
        $dayStart = $earliest->setTimezone($zone)->setTime(0, 0);
        $windows = Availability::openWindows(
            $this->calendar->hours($tenantId, $profileId),
            $this->calendar->exceptions($tenantId, $profileId, $from, $to),
            $zone,
            $dayStart,
            $latest->modify('+' . $service['duration_minutes'] . ' minutes'),
        );

        $slots = Availability::slots(
            $windows,
            $this->busyFor($tenantId, $profileId, $from, $to, $now, $exceptBookingId),
            $service['duration_minutes'],
            $service['buffer_before_minutes'],
            $service['buffer_after_minutes'],
            $settings->slotStepMinutes,
            $earliest,
            $latest,
        );
        // WHIT-1439 (AP.18): a time is free only when every need can be met.
        $needs = $this->needs($tenantId, $service);
        if ($needs === []) {
            return $slots;
        }
        $busyOf = $this->busyReader($tenantId, $from, $to, $now, $exceptBookingId);

        return array_values(array_filter($slots, static fn (DateTimeImmutable $at): bool => self::choose(
            $needs,
            Availability::blockFor($at, (int) $service['duration_minutes'], (int) $service['buffer_before_minutes'], (int) $service['buffer_after_minutes']),
            $busyOf,
        ) !== null));
    }

    /**
     * Whether the person is busy then, ignoring Book anyway: such a booking
     * is written with `overlap_ok`, so the database lets it overlap (AP.11).
     * Only asked when staff chose Book anyway.
     *
     * @param array<string, mixed> $service
     */
    private function personClashes(int $tenantId, int $profileId, array $service, DateTimeImmutable $start, DateTimeImmutable $now, ?int $exceptBookingId): bool
    {
        $block = Availability::blockFor($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes']);

        return Availability::clashes($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes'], $this->busyFor($tenantId, $profileId, $block->start->modify('-1 day'), $block->end->modify('+1 day'), $now, $exceptBookingId));
    }

    /**
     * The check made INSIDE the lock: null when the time is free, otherwise
     * why not (design 3.5, CV.9): the room is taken, the person is busy, or
     * it is outside their hours. `$bookAnyway` lets a busy PERSON through
     * (staff chose "Book anyway"); a taken room or closed hours never pass.
     *
     * @param array<string, mixed> $service
     */
    private function whyNotFree(int $tenantId, int $profileId, array $service, CalendarSettings $settings, DateTimeImmutable $start, DateTimeImmutable $now, ?int $exceptBookingId, bool $enforceHours, bool $bookAnyway = false): ?string
    {
        $block = Availability::blockFor($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes']);
        $from = $block->start->modify('-1 day');
        $to = $block->end->modify('+1 day');
        // WHIT-1439 (AP.18): every need met by a free resource, or the room is taken.
        if ($this->chooseFor($tenantId, $service, $start, $now, $exceptBookingId) === null) {
            return CalendarRejectedException::ROOM_TAKEN;
        }
        if (!$bookAnyway && Availability::clashes($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes'], $this->busyFor($tenantId, $profileId, $from, $to, $now, $exceptBookingId))) {
            return CalendarRejectedException::PERSON_BUSY;
        }
        if (!$enforceHours) {
            return null;
        }
        $windows = Availability::openWindows(
            $this->calendar->hours($tenantId, $profileId),
            $this->calendar->exceptions($tenantId, $profileId, $block->start->modify('-1 day'), $block->end->modify('+1 day')),
            $this->zoneOf($tenantId, $profileId, $settings),
            $start->modify('-1 day'),
            $start->modify('+2 days'),
        );

        return Availability::withinWindows($start, $service['duration_minutes'], $windows) ? null : CalendarRejectedException::CLOSED;
    }

    /**
     * The service as this booking (or one part of it) uses it: the length and
     * buffer after it was booked with, when they differ from the service's
     * today or were the person's own (WHIT-1440, AP.19). The booking keeps
     * them in its end and its block.
     *
     * @param array<string, mixed> $service
     * @param array<string, mixed> $row a booking or booking-part row
     * @return array<string, mixed>
     */
    private static function asBooked(array $service, array $row): array
    {
        $starts = Interval::fromDb((string) $row['starts_at'])->getTimestamp();
        $ends = Interval::fromDb((string) $row['ends_at'])->getTimestamp();
        $kept = (int) round(($ends - $starts) / 60);
        if ($kept > 0) {
            $service['duration_minutes'] = $kept;
        }
        if (is_string($row['blocks_until'] ?? null) && $row['blocks_until'] !== '') {
            $after = (int) round((Interval::fromDb($row['blocks_until'])->getTimestamp() - $ends) / 60);
            if ($after >= 0) {
                $service['buffer_after_minutes'] = $after;
            }
        }

        return $service;
    }

    /**
     * The person's busy time, and the room's when the service needs one.
     *
     * @return list<Interval>
     */
    private function busyFor(int $tenantId, int $profileId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now, ?int $exceptBookingId, ?int $roomId = null): array
    {
        $busy = $this->calendar->busy($tenantId, $profileId, $from, $to, $now, $exceptBookingId);
        if ($roomId !== null) {
            foreach ($this->rooms()->busy($tenantId, $roomId, $from, $to, $now, $exceptBookingId) as $interval) {
                $busy[] = $interval;
            }
        }
        $sources = $this->busySources;
        foreach ($this->plugins?->busySources() ?? [] as $source) {
            $sources[] = $source;
        }
        foreach ($sources as $source) {
            try {
                $times = $source instanceof PluginBusySource
                    ? $source->read($tenantId, $profileId, $from, $to)
                    : $source->busyTimes($tenantId, $profileId, $from, $to);
                foreach ($times as $interval) {
                    $busy[] = $interval;
                }
            } catch (Throwable $e) {
                // Fail open (ADR 0017, section 4), and say so: the desk and
                // the person see that this calendar wasn't checked (WHIT-1440, AP.17).
                $this->busyCheckFailed($tenantId, $profileId, $source instanceof PluginBusySource ? $source->plugin() : BusyCheckFailures::UNNAMED, $now, $e);
            }
        }

        return $busy;
    }

    private function assertInWindow(DateTimeImmutable $start, CalendarSettings $settings, DateTimeImmutable $now): void
    {
        if ($start < $now->modify('+' . $settings->minNoticeMinutes . ' minutes')) {
            throw new CalendarRejectedException('That time is too soon to book.', 422);
        }
        if ($start > $now->modify('+' . $settings->maxAdvanceDays . ' days')) {
            throw new CalendarRejectedException('That time is too far ahead to book.', 422);
        }
    }

    /** The next reminder still ahead of `$now`, or null (WHIT-1417: there may be several). */
    private function reminderDue(DateTimeImmutable $start, CalendarSettings $settings, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $next = null;
        foreach ($settings->reminderHours as $hours) {
            $due = $start->modify('-' . $hours . ' hours');
            if ($due > $now && ($next === null || $due < $next)) {
                $next = $due;
            }
        }

        return $next;
    }

    /** @return list<int> */
    private function overlapping(int $tenantId, int $profileId, Interval $when): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->bookings->list($tenantId, [$profileId], $when->start, $when->end),
        );
    }

    /**
     * Run `$work` in one transaction holding every listed person's lock,
     * taken in the order given.
     *
     * @template T
     * @param list<array{0: int, 1: int}> $locks [tenantId, profileId] pairs
     * @param callable(): T $work
     * @param list<array{0: int, 1: int}> $rooms [tenantId, resourceId] pairs in id order, locked after the people
     * @return T
     */
    private function underLock(array $locks, callable $work, array $rooms = []): mixed
    {
        return $this->locked($locks, $rooms, $work);
    }

    /**
     * Run `$work` holding several people's locks, taken in profile-id order,
     * then several rooms', in room-id order (a booking of several services).
     *
     * @template T
     * @param list<int> $people
     * @param list<int> $rooms
     * @param callable(): T $work
     * @return T
     */
    private function underLocks(int $tenantId, array $people, array $rooms, callable $work): mixed
    {
        $people = array_values(array_unique($people));
        sort($people);
        $rooms = array_values(array_unique($rooms));
        sort($rooms);

        return $this->locked(
            array_map(static fn (int $p): array => [$tenantId, $p], $people),
            array_map(static fn (int $r): array => [$tenantId, $r], $rooms),
            $work,
        );
    }

    /**
     * @template T
     * @param list<array{0: int, 1: int}> $locks [tenantId, profileId] pairs, in order
     * @param list<array{0: int, 1: int}> $rooms [tenantId, roomId] pairs, in order, after the people
     * @param callable(): T $work
     * @return T
     */
    private function locked(array $locks, array $rooms, callable $work): mixed
    {
        $own = !$this->db->inTransaction();
        $postgres = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($postgres && $own) {
            // Outside the transaction, so each expiry commits and is announced
            // on its own, whatever this write then does.
            $this->expireLapsedHolds($locks, $rooms);
        }
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($locks as [$tenantId, $profileId]) {
                $this->calendar->lockStaff($tenantId, $profileId);
            }
            foreach ($rooms as [$tenantId, $roomId]) {
                $this->rooms()->lock($tenantId, $roomId);
            }
            if ($postgres && !$own) {
                $this->expireLapsedHolds($locks, $rooms);
            }
            $result = $work();
            if ($postgres) {
                $this->checkHolds();
            }
            if ($own) {
                $this->db->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The database's own check that no two bookings overlap (migration 360,
     * AP.11). The constraint is deferred, because a booking of several
     * services is written in steps; here it is checked at once, so a refusal
     * comes back as the usual 409 from the write that caused it rather than
     * from a commit somewhere above.
     *
     * The application's locks and re-read make this a second line: it fires
     * only when something wrote a booking without them.
     */
    private function checkHolds(): void
    {
        try {
            $this->db->exec('SET CONSTRAINTS ' . HoldConflicts::CONSTRAINT . ' IMMEDIATE');
            $this->db->exec('SET CONSTRAINTS ' . HoldConflicts::CONSTRAINT . ' DEFERRED');
        } catch (\PDOException $e) {
            throw HoldConflicts::fromPdo($e) ?? $e;
        }
    }

    /**
     * Payment holds whose time ran out still hold their rows until something
     * expires them. Expire the ones on these people and rooms first, so the
     * database's check agrees with the busy time the service reads (which
     * already skips them).
     *
     * @param list<array{0: int, 1: int}> $locks
     * @param list<array{0: int, 1: int}> $rooms
     */
    private function expireLapsedHolds(array $locks, array $rooms): void
    {
        $byTenant = [];
        foreach ($locks as [$tenantId, $profileId]) {
            $byTenant[$tenantId]['person'][] = $profileId;
        }
        foreach ($rooms as [$tenantId, $roomId]) {
            $byTenant[$tenantId]['resource'][] = $roomId;
        }
        foreach ($byTenant as $tenantId => $holders) {
            foreach (HoldConflicts::lapsed($this->db, $tenantId, $holders['person'] ?? [], $holders['resource'] ?? [], $this->now()) as $bookingId) {
                $this->expireHold($tenantId, $bookingId);
            }
        }
    }

    private function rooms(): RoomRepository
    {
        return $this->rooms ??= new RoomRepository($this->db);
    }

    /** Keep a busy source's failure for the desk; never let keeping it fail a booking. */
    private function busyCheckFailed(int $tenantId, int $profileId, string $source, DateTimeImmutable $now, Throwable $e): void
    {
        ScrubbedErrorLog::failure('[calendar] an external busy-time source failed', $e);
        try {
            (new BusyCheckFailures($this->db))->record($tenantId, $profileId, $source, $now);
        } catch (Throwable $kept) {
            ScrubbedErrorLog::failure('[calendar] could not keep a busy-source failure', $kept);
        }
    }

    /** WHIT-1440 (AP.16): the zone of the person's branch, or the workspace's. */
    private function zoneOf(int $tenantId, int $profileId, CalendarSettings $settings): DateTimeZone
    {
        return ($this->zones ??= new BranchZones($this->db))->forPerson($tenantId, $profileId, $settings->zone);
    }

    /**
     * The services of a booking of several, who may do each, and the
     * currency of the total (WHIT-1173). `fixed` is true when staff named a
     * person for every service, so a refusal can say why.
     *
     * @param list<array{service_id: int, profile_id: ?int}> $items
     * @return array{services: list<array<string, mixed>>, candidates: list<list<int>>, same: bool, currency: string, fixed: bool}
     */
    private function plan(int $tenantId, array $items, bool $samePerson, bool $publicOnly, bool $requireSetting = true): array
    {
        if ($items === []) {
            throw new CalendarRejectedException('Choose a service.', 422);
        }
        if (count($items) > self::MAX_SERVICES) {
            throw new CalendarRejectedException('A booking can hold at most ' . self::MAX_SERVICES . ' services.', 422);
        }
        if ($requireSetting && count($items) > 1 && !$this->settingsFor($tenantId)->multiServiceBooking) {
            throw new CalendarRejectedException('Booking several services at once is turned off here. Book them one at a time.', 422, ['code' => 'multi_service_off']);
        }
        $services = [];
        $candidates = [];
        $currency = null;
        $fixed = true;
        foreach ($items as $item) {
            $service = $this->service($tenantId, $item['service_id'], $publicOnly);
            $who = $this->candidates($tenantId, $service, $item['profile_id'], $publicOnly);
            if ($who === []) {
                throw new CalendarRejectedException(count($items) > 1 ? 'Nobody offers one of these services.' : 'Nobody offers this service.', 422);
            }
            if ($service['price_minor'] !== null) {
                $currency ??= (string) $service['currency'];
                if ($currency !== (string) $service['currency']) {
                    throw new CalendarRejectedException('These services are priced in different currencies, so they can’t be booked together.', 422);
                }
            }
            $fixed = $fixed && $item['profile_id'] !== null;
            $services[] = $service;
            $candidates[] = $who;
        }
        if ($samePerson) {
            $common = $candidates[0];
            foreach ($candidates as $who) {
                $common = array_values(array_filter($common, static fn (int $p): bool => in_array($p, $who, true)));
            }
            if ($common === []) {
                throw new CalendarRejectedException('Nobody does all of these services. Choose someone for each, or anyone.', 422);
            }
            $candidates = array_fill(0, count($services), $common);
        }

        return [
            'services' => $services,
            'candidates' => $candidates,
            'same' => $samePerson,
            'currency' => $currency ?? (string) $services[0]['currency'],
            'fixed' => $fixed,
        ];
    }

    /**
     * Who does each service when they start at `$start`, back to back, or
     * null when nobody can do one of them. Each service's choice depends only
     * on its own time, so taking the first free person for each finds an
     * answer whenever there is one; the person who did the service before is
     * tried first, so a booking stays with one person when it can.
     *
     * @param array{services: list<array<string, mixed>>, candidates: list<list<int>>, same: bool} $plan
     * @param callable(int, int, DateTimeImmutable): bool $free person, service index, its start
     * @return list<int>|null
     */
    private static function assign(array $plan, DateTimeImmutable $start, callable $free): ?array
    {
        // WHIT-1440 (AP.19): each part lasts as long as its person takes, so
        // the next part starts when that person's part ends.
        $length = static fn (int $k, int $person): int => (int) ServiceRepository::forPerson($plan['services'][$k], $person)['duration_minutes'];
        $count = count($plan['services']);
        if ($plan['same']) {
            foreach ($plan['candidates'][0] as $person) {
                $all = true;
                $t = $start;
                for ($k = 0; $k < $count; $k++) {
                    if (!$free($person, $k, $t)) {
                        $all = false;
                        break;
                    }
                    $t = $t->modify('+' . $length($k, $person) . ' minutes');
                }
                if ($all) {
                    return array_fill(0, $count, $person);
                }
            }

            return null;
        }
        $people = [];
        $t = $start;
        for ($k = 0; $k < $count; $k++) {
            $order = $plan['candidates'][$k];
            $previous = $k > 0 ? $people[$k - 1] : null;
            if ($previous !== null && in_array($previous, $order, true)) {
                $order = [$previous, ...array_values(array_filter($order, static fn (int $p): bool => $p !== $previous))];
            }
            $chosen = null;
            foreach ($order as $person) {
                if ($free($person, $k, $t)) {
                    $chosen = $person;
                    break;
                }
            }
            if ($chosen === null) {
                return null;
            }
            $people[] = $chosen;
            $t = $t->modify('+' . $length($k, $chosen) . ' minutes');
        }

        return $people;
    }

    /**
     * Each service's own part of the booking, back to back from `$start`.
     *
     * @param list<array<string, mixed>> $services
     * @param list<int> $people who does each
     * @return non-empty-list<array{service_id: int, profile_id: int, room_id: ?int, starts_at: DateTimeImmutable, ends_at: DateTimeImmutable, block: Interval, price_minor: ?int}>
     */
    private static function parts(array $services, array $people, DateTimeImmutable $start): array
    {
        $parts = [];
        $at = $start;
        foreach ($services as $k => $service) {
            // WHIT-1440 (AP.19): the part as its person provides it.
            $service = ServiceRepository::forPerson($service, $people[$k]);
            $end = $at->modify('+' . (int) $service['duration_minutes'] . ' minutes');
            $parts[] = [
                'service_id' => (int) $service['id'],
                'profile_id' => $people[$k],
                'room_id' => is_int($service['room_id'] ?? null) ? $service['room_id'] : null,
                'starts_at' => $at,
                'ends_at' => $end,
                'block' => Availability::blockFor($at, (int) $service['duration_minutes'], (int) $service['buffer_before_minutes'], (int) $service['buffer_after_minutes']),
                'price_minor' => is_int($service['price_minor'] ?? null) ? $service['price_minor'] : null,
            ];
            $at = $end;
        }
        if ($parts === []) {
            throw new CalendarRejectedException('Choose a service.', 422);
        }

        return $parts;
    }

    /**
     * Everything the parts block together, buffers included.
     *
     * @param non-empty-list<array{block: Interval}> $parts
     */
    private static function span(array $parts): Interval
    {
        $from = $parts[0]['block']->start;
        $until = $parts[0]['block']->end;
        foreach ($parts as $part) {
            $from = min($from, $part['block']->start);
            $until = max($until, $part['block']->end);
        }

        return new Interval($from, $until);
    }

    /**
     * The total of the services' prices; null when none shows a price.
     *
     * @param list<array<string, mixed>> $services
     */
    private static function totalPrice(array $services): ?int
    {
        $total = null;
        foreach ($services as $service) {
            if (is_int($service['price_minor'] ?? null)) {
                $total = ($total ?? 0) + $service['price_minor'];
            }
        }

        return $total;
    }

    /**
     * @param list<array{room_id: ?int}> $parts
     * @return list<int>
     */
    private static function roomsOf(array $parts): array
    {
        $rooms = [];
        foreach ($parts as $part) {
            if ($part['room_id'] !== null) {
                $rooms[] = $part['room_id'];
            }
        }

        return $rooms;
    }

    /**
     * The locks for every resource a service could take (AP.18), in id order:
     * every candidate of a group, so two bookings choosing from one group
     * queue rather than both taking the same chair.
     *
     * @param array<string, mixed> $service
     * @return list<array{0: int, 1: int}>
     */
    private function roomLock(int $tenantId, array $service): array
    {
        return array_map(static fn (int $r): array => [$tenantId, $r], $this->resourceIds($tenantId, [$service]));
    }

    /**
     * Every resource these services could take, without repeats, in id order.
     *
     * @param list<array<string, mixed>> $services
     * @return list<int>
     */
    private function resourceIds(int $tenantId, array $services): array
    {
        $ids = [];
        foreach ($services as $service) {
            foreach ($this->needs($tenantId, $service) as $candidates) {
                foreach ($candidates as $id) {
                    $ids[$id] = $id;
                }
            }
        }
        ksort($ids);

        return array_values($ids);
    }

    /**
     * What a service needs (AP.18): for each need, the resources that can
     * meet it, in the order to try them.
     *
     * @param array<string, mixed> $service
     * @return list<list<int>>
     */
    private function needs(int $tenantId, array $service): array
    {
        if (!is_int($service['id'] ?? null)) {
            return is_int($service['room_id'] ?? null) ? [[$service['room_id']]] : [];
        }

        // WHIT-1440 (AP.16): for a person, a group's resources in their branch.
        $branch = is_int($service['for_profile_id'] ?? null) ? ($this->zones ??= new BranchZones($this->db))->branchOf($tenantId, $service['for_profile_id']) : null;

        return $this->rooms()->needsFor($tenantId, $service['id'], is_int($service['room_id'] ?? null) ? $service['room_id'] : null, $branch);
    }

    /**
     * Each resource's busy time in `[from, to)`, read once per resource.
     *
     * @return \Closure(int): list<Interval>
     */
    private function busyReader(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now, ?int $exceptBookingId): \Closure
    {
        $read = [];

        return function (int $resourceId) use (&$read, $tenantId, $from, $to, $now, $exceptBookingId): array {
            return $read[$resourceId] ??= $this->rooms()->busy($tenantId, $resourceId, $from, $to, $now, $exceptBookingId);
        };
    }

    /**
     * The resources a service would take at `$start`, one for each need, or
     * null when a need can't be met. `$prefer` (what a booking already holds)
     * is tried first, so moving a booking keeps its chair when it can.
     *
     * @param array<string, mixed> $service
     * @param list<int> $prefer
     * @return list<int>|null
     */
    private function chooseFor(int $tenantId, array $service, DateTimeImmutable $start, DateTimeImmutable $now, ?int $exceptBookingId, array $prefer = []): ?array
    {
        $needs = $this->needs($tenantId, $service);
        if ($needs === []) {
            return [];
        }
        $block = Availability::blockFor($start, (int) $service['duration_minutes'], (int) $service['buffer_before_minutes'], (int) $service['buffer_after_minutes']);

        return self::choose($needs, $block, $this->busyReader($tenantId, $block->start->modify('-1 day'), $block->end->modify('+1 day'), $now, $exceptBookingId), $prefer);
    }

    /**
     * For each need in order, the first of its resources that is free over
     * `$block` and not already taken by an earlier need; null when one can't
     * be met. Resources in `$prefer` go first.
     *
     * @param list<list<int>> $needs
     * @param callable(int): list<Interval> $busyOf
     * @param list<int> $prefer
     * @return list<int>|null
     */
    private static function choose(array $needs, Interval $block, callable $busyOf, array $prefer = []): ?array
    {
        $chosen = [];
        foreach ($needs as $candidates) {
            usort($candidates, static fn (int $a, int $b): int => (in_array($b, $prefer, true) <=> in_array($a, $prefer, true)));
            $pick = null;
            foreach ($candidates as $resource) {
                if (in_array($resource, $chosen, true)) {
                    continue;
                }
                $free = true;
                foreach ($busyOf($resource) as $busy) {
                    if ($busy->start < $block->end && $busy->end > $block->start) {
                        $free = false;
                        break;
                    }
                }
                if ($free) {
                    $pick = $resource;
                    break;
                }
            }
            if ($pick === null) {
                return null;
            }
            $chosen[] = $pick;
        }

        return $chosen;
    }

    /**
     * Rows for {@see BookingRepository::setResources()}: each part's chosen
     * resources over that part's block.
     *
     * @param array<int, list<int>> $chosen part => resources
     * @param array<int, Interval> $blocks part => block
     * @return list<array{resource_id: int, part: int, block: Interval}>
     */
    private static function resourceRows(array $chosen, array $blocks): array
    {
        $rows = [];
        foreach ($chosen as $part => $resources) {
            foreach ($resources as $resource) {
                $rows[] = ['resource_id' => $resource, 'part' => (int) $part, 'block' => $blocks[$part]];
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $booking */
    private function announceBooking(string $event, int $tenantId, array $booking): void
    {
        $this->announce($event, [
            'id' => $booking['id'],
            'tenant_id' => $tenantId,
            'profile_id' => $booking['profile_id'],
            'service_id' => $booking['service_id'],
            'status' => $booking['status'],
            // WHIT-1173: everyone doing one of its services, and the services.
            'profile_ids' => array_values(array_unique(array_map('intval', array_column($booking['items'] ?? [], 'profile_id')) ?: [(int) $booking['profile_id']])),
            'service_ids' => array_map('intval', array_column($booking['items'] ?? [], 'service_id')) ?: [(int) $booking['service_id']],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function announce(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch($event, $payload);
        } catch (Throwable $e) {
            error_log('[calendar] a listener on ' . $event . ' failed: ' . $e->getMessage());
        }
        try {
            $this->hooks->dispatchAsync($event . '.async', $payload);
        } catch (Throwable $e) {
            error_log('[calendar] could not record ' . $event . ': ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $booking */
    private function tell(string $what, int $tenantId, array $booking): void
    {
        try {
            match ($what) {
                'confirmed' => $this->messenger->confirmed($tenantId, $booking),
                'rescheduled' => $this->messenger->rescheduled($tenantId, $booking),
                'remind' => $this->messenger->remind($tenantId, $booking),
                'awaitingPayment' => $this->messenger->awaitingPayment($tenantId, $booking),
                'requested' => $this->messenger->requested($tenantId, $booking),
                'suggested' => $this->messenger->suggested($tenantId, $booking),
                default => $this->messenger->cancelled($tenantId, $booking),
            };
        } catch (Throwable $e) {
            error_log('[calendar] could not send the ' . $what . ' message: ' . $e->getMessage());
        }
    }
}
