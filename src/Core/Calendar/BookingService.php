<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Whity\Core\Hooks\HookManager;
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
    /** @var list<ExternalBusySource> */
    private array $busySources = [];

    private ?ManageLinks $links = null;

    private ?QueueService $queue = null;

    private ?CalendarPlugins $plugins = null;

    private string $manageUrlBase = '';

    private ?RoomRepository $rooms = null;

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

    public function addBusySource(ExternalBusySource $source): void
    {
        $this->busySources[] = $source;
    }

    public function setMessenger(BookingMessenger $messenger): void
    {
        $this->messenger = $messenger;
    }

    /** Customer manage links (WHIT-942): issued on create and move, revoked on cancel. */
    public function setLinks(ManageLinks $links): void
    {
        $this->links = $links;
    }

    /** Plugins' payment step and busy times (SDK 1.98), read at call time. */
    public function setPlugins(CalendarPlugins $plugins): void
    {
        $this->plugins = $plugins;
    }

    /** Where the customer's manage page lives; a payment step returns them there. */
    public function setManageUrlBase(string $base): void
    {
        $this->manageUrlBase = $base;
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
     * the people free then.
     *
     * @return list<array{starts_at: string, ends_at: string, profile_ids: list<int>}>
     */
    public function availability(int $tenantId, int $serviceId, ?int $profileId, DateTimeImmutable $from, DateTimeImmutable $to, bool $publicOnly = false): array
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
            foreach ($this->slotsFor($tenantId, $candidate, $service, $settings, $earliest, $latest, $now, null) as $slot) {
                $key = $slot->getTimestamp();
                $byStart[$key] ??= ['start' => $slot, 'profile_ids' => []];
                $byStart[$key]['profile_ids'][] = $candidate;
            }
        }
        ksort($byStart);

        return array_values(array_map(static fn (array $s): array => [
            'starts_at' => Interval::toApi($s['start']),
            'ends_at' => Interval::toApi($s['start']->modify('+' . $service['duration_minutes'] . ' minutes')),
            'profile_ids' => $s['profile_ids'],
        ], $byStart));
    }

    /**
     * Book a service.
     *
     * @param array{
     *   service_id: int, profile_id: ?int, starts_at: DateTimeImmutable,
     *   customer_profile_id: ?int, customer_name: string, customer_email: string,
     *   customer_phone: string, customer_note: string, source: string,
     *   created_by_profile_id: ?int, enforce_hours: bool, public: bool
     * } $request
     * @return array<string, mixed> the booking
     */
    public function book(int $tenantId, array $request): array
    {
        $service = $this->service($tenantId, $request['service_id'], $request['public']);
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

        $end = $start->modify('+' . $service['duration_minutes'] . ' minutes');
        $block = Availability::blockFor($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes']);
        // A request is not paid for until it is accepted: no payment step.
        $asRequest = $request['public'] && ($service['needs_approval'] || !$settings->instantBooking);
        $step = $asRequest ? null : $this->plugins?->paymentStep();
        $room = $this->roomLock($tenantId, $service);
        foreach ($candidates as $candidate) {
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
            $id = $this->underLock([[$tenantId, $candidate]], function () use ($tenantId, $candidate, $service, $settings, $request, $start, $end, $block, $now, $hold, $asRequest): ?int {
                if (!$this->isFree($tenantId, $candidate, $service, $settings, $start, $now, null, $request['enforce_hours'])) {
                    return null;
                }

                return $this->bookings->insert($tenantId, [
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
                    'room_id' => $service['room_id'] ?? null,
                ]);
            }, $room);
            if ($id !== null) {
                $booking = $this->afterWrite($tenantId, $id);
                if ($requirement !== null && $hold !== null) {
                    return $this->startPayment($tenantId, $booking, $quote, $requirement, $hold, $step);
                }
                if ($asRequest) {
                    $this->announceBooking(CalendarEventNames::BOOKING_CREATED, $tenantId, $booking);
                    $this->tell('requested', $tenantId, $booking);

                    return $booking;
                }
                $this->announceBooking(CalendarEventNames::BOOKING_CREATED, $tenantId, $booking);
                $this->announceBooking(CalendarEventNames::BOOKING_CONFIRMED, $tenantId, $booking);
                $this->tell('confirmed', $tenantId, $booking);

                return $booking;
            }
        }

        throw CalendarRejectedException::taken();
    }

    /**
     * Move a booking to a new start, and optionally to another person.
     *
     * @return array<string, mixed>
     */
    public function move(int $tenantId, int $bookingId, DateTimeImmutable $start, ?int $profileId, bool $enforceHours): array
    {
        $booking = $this->moveUnderLock($tenantId, $bookingId, $start, $profileId, $enforceHours);
        if ($booking['status'] === BookingRepository::STATUS_REQUESTED) {
            // The customer (or staff) changed the time of a request: it is
            // still a request, and no longer staff's suggestion.
            $this->bookings->markSuggested($tenantId, $bookingId, null);
            $booking['suggested_at'] = null;
            $this->announceBooking(CalendarEventNames::BOOKING_RESCHEDULED, $tenantId, $booking);
            $this->tell('requested', $tenantId, $booking);

            return $booking;
        }
        $this->announceBooking(CalendarEventNames::BOOKING_RESCHEDULED, $tenantId, $booking);
        $this->tell('rescheduled', $tenantId, $booking);

        return $booking;
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
    public function checkIn(int $tenantId, int $bookingId, bool $arrived): array
    {
        $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if (!$this->bookings->checkIn($tenantId, $bookingId, $arrived ? $this->now() : null)) {
            throw new CalendarRejectedException('Only a confirmed booking can be checked in.', 422);
        }

        return $this->bookingOrFail($tenantId, $bookingId);
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
    private function moveUnderLock(int $tenantId, int $bookingId, DateTimeImmutable $start, ?int $profileId, bool $enforceHours): array
    {
        $current = $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        if (!in_array($current['status'], BookingRepository::LIVE, true)) {
            throw new CalendarRejectedException('Only a booking that is still on can be moved.', 422);
        }
        $isRequest = $current['status'] === BookingRepository::STATUS_REQUESTED;
        $service = $this->service($tenantId, (int) $current['service_id'], false);
        $settings = $this->settingsFor($tenantId);
        $now = $this->now();
        $from = (int) $current['profile_id'];
        $to = $profileId ?? $from;
        if ($to !== $from && !in_array($to, $this->candidates($tenantId, $service, $to, false), true)) {
            throw new CalendarRejectedException('That person does not offer this service.', 422);
        }
        if ($enforceHours) {
            $this->assertInWindow($start, $settings, $now);
        }

        $locks = array_values(array_unique([$from, $to]));
        sort($locks);
        $end = $start->modify('+' . $service['duration_minutes'] . ' minutes');
        $block = Availability::blockFor($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes']);
        $moved = $this->underLock(array_map(static fn (int $p): array => [$tenantId, $p], $locks), function () use ($tenantId, $bookingId, $to, $service, $settings, $start, $end, $block, $now, $enforceHours, $isRequest): bool {
            if (!$this->isFree($tenantId, $to, $service, $settings, $start, $now, $bookingId, $enforceHours)) {
                return false;
            }
            $this->bookings->move($tenantId, $bookingId, $to, $start, $end, $block, $isRequest ? null : $this->reminderDue($start, $settings, $now));

            return true;
        }, $this->roomLock($tenantId, $service));
        if (!$moved) {
            throw CalendarRejectedException::taken();
        }

        return $this->afterWrite($tenantId, $bookingId);
    }

    /** @return array<string, mixed> */
    public function cancel(int $tenantId, int $bookingId, string $by, string $reason): array
    {
        $this->bookings->find($tenantId, $bookingId) ?? throw CalendarRejectedException::notFound('Booking');
        $done = $this->bookings->transition(
            $tenantId,
            $bookingId,
            BookingRepository::LIVE,
            BookingRepository::STATUS_CANCELLED,
            ['cancelled_by' => $by, 'cancel_reason' => mb_substr($reason, 0, 500)],
        );
        if (!$done) {
            throw new CalendarRejectedException('This booking is already cancelled or has ended.', 422);
        }
        $this->links?->revoke($tenantId, $bookingId);
        $booking = $this->bookingOrFail($tenantId, $bookingId);
        $this->announceBooking(CalendarEventNames::BOOKING_CANCELLED, $tenantId, $booking);
        $this->tell('cancelled', $tenantId, $booking);

        return $booking;
    }

    /**
     * A staff event. Busy events take the person's lock so a booking made at
     * the same moment sees them; an event may sit over an existing booking
     * (it is the person's own choice) and the answer lists those bookings.
     *
     * @return array{event: array<string, mixed>, overlapping_bookings: list<int>}
     */
    public function createEvent(int $tenantId, int $profileId, string $title, string $notes, Interval $when, bool $allDay, bool $busy, ?int $actor, ?string $externalRef = null): array
    {
        $id = $this->underLock([[$tenantId, $profileId]], fn (): int => $this->calendar->insertEvent($tenantId, $profileId, $title, $notes, $when, $allDay, $busy, $externalRef, $actor));
        $event = $this->calendar->findEvent($tenantId, $id) ?? throw CalendarRejectedException::notFound('Event');
        $this->announce(CalendarEventNames::EVENT_CREATED, ['id' => $id, 'tenant_id' => $tenantId, 'profile_id' => $profileId]);

        return ['event' => $event, 'overlapping_bookings' => $this->overlapping($tenantId, $profileId, $when)];
    }

    /** @return array{event: array<string, mixed>, overlapping_bookings: list<int>} */
    public function updateEvent(int $tenantId, int $eventId, string $title, string $notes, Interval $when, bool $allDay, bool $busy): array
    {
        $existing = $this->calendar->findEvent($tenantId, $eventId) ?? throw CalendarRejectedException::notFound('Event');
        $profileId = (int) $existing['profile_id'];
        $this->underLock([[$tenantId, $profileId]], function () use ($tenantId, $eventId, $title, $notes, $when, $allDay, $busy): bool {
            $this->calendar->updateEvent($tenantId, $eventId, $title, $notes, $when, $allDay, $busy);

            return true;
        });
        $this->announce(CalendarEventNames::EVENT_UPDATED, ['id' => $eventId, 'tenant_id' => $tenantId, 'profile_id' => $profileId]);

        return [
            'event' => $this->calendar->findEvent($tenantId, $eventId) ?? $existing,
            'overlapping_bookings' => $this->overlapping($tenantId, $profileId, $when),
        ];
    }

    public function cancelEvent(int $tenantId, int $eventId): void
    {
        $existing = $this->calendar->findEvent($tenantId, $eventId) ?? throw CalendarRejectedException::notFound('Event');
        $this->calendar->cancelEvent($tenantId, $eventId);
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
        $done = $this->underLock([[$tenantId, (int) $row['profile_id']]], function () use ($tenantId, $bookingId, $row, $service, $settings, $start, $paymentRef): bool {
            if (!$this->isFree($tenantId, (int) $row['profile_id'], $service, $settings, $start, $this->now(), $bookingId, false)) {
                return false;
            }

            return $this->bookings->transition(
                $tenantId,
                $bookingId,
                [BookingRepository::STATUS_PENDING_PAYMENT, BookingRepository::STATUS_EXPIRED],
                BookingRepository::STATUS_CONFIRMED,
                ['payment_ref' => mb_substr($paymentRef, 0, 255)],
            );
        }, $this->roomLock($tenantId, $service));
        if (!$done) {
            return false;
        }
        $booking = $this->bookingOrFail($tenantId, $bookingId);
        $this->announceBooking(CalendarEventNames::BOOKING_CONFIRMED, $tenantId, $booking);
        $this->tell('confirmed', $tenantId, $booking);

        return true;
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

        return $booking;
    }

    /**
     * Send a booking's reminder if it is still due (the queued job's work).
     * Returns what happened, or `['due' => …]` when it is not due yet and the
     * job must be queued again.
     *
     * @return string|array{due: DateTimeImmutable}
     */
    public function sendReminder(int $tenantId, int $bookingId, string $startsAt): string|array
    {
        $row = $this->bookings->find($tenantId, $bookingId);
        if ($row === null || $row['status'] !== BookingRepository::STATUS_CONFIRMED) {
            return 'not on';
        }
        if (Interval::toApi(Interval::fromDb((string) $row['starts_at'])) !== $startsAt) {
            return 'moved';
        }
        if ($row['reminder_sent_at'] !== null || $row['reminder_due_at'] === null) {
            return 'nothing due';
        }
        $due = Interval::fromDb((string) $row['reminder_due_at']);
        if ($due->getTimestamp() > $this->now()->getTimestamp() + 60) {
            return ['due' => $due];
        }
        // Claimed before sending, so two workers never both send it.
        if (!$this->bookings->markReminded($tenantId, $bookingId, $this->now())) {
            return 'already sent';
        }
        $booking = $this->bookingOrFail($tenantId, $bookingId);
        $this->tell('remind', $tenantId, $booking);
        // Other channels a plugin adds (design 3.3: WhatsApp is paid).
        foreach ($this->plugins?->reminderChannels() ?? [] as $channel) {
            try {
                $channel->remind($tenantId, $booking);
            } catch (Throwable $e) {
                error_log('[calendar] the ' . $channel->name() . ' reminder failed: ' . $e->getMessage());
            }
        }

        return 'sent';
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
        $dayStart = $earliest->setTimezone($settings->zone)->setTime(0, 0);
        $windows = Availability::openWindows(
            $this->calendar->hours($tenantId, $profileId),
            $this->calendar->exceptions($tenantId, $profileId, $from, $to),
            $settings->zone,
            $dayStart,
            $latest->modify('+' . $service['duration_minutes'] . ' minutes'),
        );

        return Availability::slots(
            $windows,
            $this->busyFor($tenantId, $profileId, $from, $to, $now, $exceptBookingId, $service['room_id'] ?? null),
            $service['duration_minutes'],
            $service['buffer_before_minutes'],
            $service['buffer_after_minutes'],
            $settings->slotStepMinutes,
            $earliest,
            $latest,
        );
    }

    /**
     * The check made INSIDE the lock.
     *
     * @param array<string, mixed> $service
     */
    private function isFree(int $tenantId, int $profileId, array $service, CalendarSettings $settings, DateTimeImmutable $start, DateTimeImmutable $now, ?int $exceptBookingId, bool $enforceHours): bool
    {
        $block = Availability::blockFor($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes']);
        $busy = $this->busyFor($tenantId, $profileId, $block->start->modify('-1 day'), $block->end->modify('+1 day'), $now, $exceptBookingId, $service['room_id'] ?? null);
        if (Availability::clashes($start, $service['duration_minutes'], $service['buffer_before_minutes'], $service['buffer_after_minutes'], $busy)) {
            return false;
        }
        if (!$enforceHours) {
            return true;
        }
        $windows = Availability::openWindows(
            $this->calendar->hours($tenantId, $profileId),
            $this->calendar->exceptions($tenantId, $profileId, $block->start->modify('-1 day'), $block->end->modify('+1 day')),
            $settings->zone,
            $start->modify('-1 day'),
            $start->modify('+2 days'),
        );

        return Availability::withinWindows($start, $service['duration_minutes'], $windows);
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
                foreach ($source->busyTimes($tenantId, $profileId, $from, $to) as $interval) {
                    $busy[] = $interval;
                }
            } catch (Throwable $e) {
                // Fail open (ADR 0017, section 4).
                error_log('[calendar] an external busy-time source failed: ' . $e->getMessage());
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

    private function reminderDue(DateTimeImmutable $start, CalendarSettings $settings, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($settings->reminderHoursBefore <= 0) {
            return null;
        }
        $due = $start->modify('-' . $settings->reminderHoursBefore . ' hours');

        return $due > $now ? $due : null;
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
     * @param array{0: int, 1: int}|null $room [tenantId, roomId], locked after the people
     * @return T
     */
    private function underLock(array $locks, callable $work, ?array $room = null): mixed
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($locks as [$tenantId, $profileId]) {
                $this->calendar->lockStaff($tenantId, $profileId);
            }
            if ($room !== null) {
                $this->rooms()->lock($room[0], $room[1]);
            }
            $result = $work();
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

    private function rooms(): RoomRepository
    {
        return $this->rooms ??= new RoomRepository($this->db);
    }

    /**
     * @param array<string, mixed> $service
     * @return array{0: int, 1: int}|null
     */
    private function roomLock(int $tenantId, array $service): ?array
    {
        $roomId = $service['room_id'] ?? null;

        return is_int($roomId) ? [$tenantId, $roomId] : null;
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
