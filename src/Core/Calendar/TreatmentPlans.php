<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Treatment plans (WHIT-1444, design section 39 §7.4, register AP.22).
 *
 * A plan is a customer (their contact), the staff member who treats them, and
 * steps in order: a service and the gap after the step before, as a range of
 * days. It is made from a service's template ("Laser, 6 sessions") or by hand.
 *
 * - **Book the next visit** finds the first free time in the next step's gap
 *   with the same person, and books it when staff say so.
 * - **Book all** books every step still to book, each one an ordinary booking
 *   checked like any other. A step that can't be placed is left unbooked and
 *   said so; the ones after it are placed from where it would have been.
 * - **Progress** ("3 of 6") is read from the steps' bookings: attended is
 *   done, didn't come is missed (a no-show like any booking), cancelled is to
 *   book again.
 *
 * The customer's name, email and phone are read from the contact, never kept
 * on the plan. Each booking keeps its own copy, as every booking does, because
 * it is what the customer was told. Tenant first everywhere.
 */
final class TreatmentPlans
{
    public const ACTIVE = 'active';
    public const DONE = 'done';
    public const STOPPED = 'stopped';
    public const MAX_STEPS = 30;
    public const MAX_GAP_DAYS = 365;

    public function __construct(
        private readonly PDO $db,
        private readonly BookingService $booking,
    ) {
    }

    // ── plans ───────────────────────────────────────────────────────────

    /**
     * A new plan, from steps or a service's template.
     *
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed> the plan
     */
    public function create(int $tenantId, int $contactId, int $profileId, string $title, array $steps, ?int $actor): array
    {
        $this->contactOrFail($tenantId, $contactId);
        $title = mb_substr(trim($title), 0, 200);
        if ($title === '') {
            throw new CalendarRejectedException('Give the plan a title.', 422, ['field' => 'title']);
        }
        $clean = $this->cleanSteps($tenantId, $steps, $profileId);
        $now = Interval::toDb($this->booking->now());
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_plans (tenant_id, contact_id, profile_id, title, status, created_by_profile_id, created_at, updated_at)
             VALUES (:tenant_id, :contact_id, :profile_id, :title, :status, :actor, :now, :now2) RETURNING id'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':status', self::ACTIVE);
        $stmt->bindValue(':actor', $actor !== null && $actor > 0 ? $actor : null, $actor !== null && $actor > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':now2', $now);
        $stmt->execute();
        $planId = (int) $stmt->fetchColumn();
        $insert = $this->db->prepare(
            'INSERT INTO calendar_plan_steps (tenant_id, plan_id, position, service_id, gap_min_days, gap_max_days)
             VALUES (:tenant_id, :plan_id, :position, :service_id, :min, :max)'
        );
        foreach ($clean as $i => $step) {
            $insert->execute([':tenant_id' => $tenantId, ':plan_id' => $planId, ':position' => $i, ':service_id' => $step['service_id'], ':min' => $step['gap_min_days'], ':max' => $step['gap_max_days']]);
        }

        return $this->findOrFail($tenantId, $planId);
    }

    /**
     * A new plan from a service's template.
     *
     * @return array<string, mixed>
     */
    public function createFromTemplate(int $tenantId, int $templateId, int $contactId, int $profileId, ?int $actor): array
    {
        $template = $this->template($tenantId, $templateId) ?? throw CalendarRejectedException::notFound('Plan template');

        return $this->create($tenantId, $contactId, $profileId, $template['title'], $template['steps'], $actor);
    }

    /**
     * A plan with its customer (read from the contact), its steps and progress.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $planId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.contact_id, p.profile_id, p.title, p.status, p.created_at,
                    c.name AS contact_name, c.email AS contact_email, c.mobile AS contact_mobile, c.phone AS contact_phone,
                    pr.display_name AS staff_name
               FROM calendar_plans p
               JOIN contacts c ON c.id = p.contact_id AND c.tenant_id = p.tenant_id
               JOIN profiles pr ON pr.id = p.profile_id
              WHERE p.tenant_id = :tenant_id AND p.id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $steps = $this->steps($tenantId, $planId);
        $done = count(array_filter($steps, static fn (array $s): bool => $s['state'] === 'done'));
        $missed = count(array_filter($steps, static fn (array $s): bool => $s['state'] === 'missed'));

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'status' => (string) $row['status'],
            'customer' => [
                'contact_id' => (int) $row['contact_id'],
                'name' => (string) $row['contact_name'],
                'email' => (string) ($row['contact_email'] ?? ''),
                'phone' => (string) ($row['contact_mobile'] ?: ($row['contact_phone'] ?? '')),
            ],
            'staff' => ['profile_id' => (int) $row['profile_id'], 'name' => (string) $row['staff_name']],
            'steps' => $steps,
            'progress' => ['done' => $done, 'missed' => $missed, 'total' => count($steps)],
            'created_at' => Interval::toApi(Interval::fromDb((string) $row['created_at'])),
        ];
    }

    /** @return array<string, mixed> */
    public function findOrFail(int $tenantId, int $planId): array
    {
        return $this->find($tenantId, $planId) ?? throw CalendarRejectedException::notFound('Plan');
    }

    /**
     * A customer's plans, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forContact(int $tenantId, int $contactId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM calendar_plans WHERE tenant_id = :tenant_id AND contact_id = :contact_id ORDER BY created_at DESC, id DESC');
        $stmt->execute([':tenant_id' => $tenantId, ':contact_id' => $contactId]);

        return array_values(array_filter(array_map(fn (mixed $id): ?array => $this->find($tenantId, (int) $id), $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * The plan a booking belongs to, as the booking shows it ("Laser, step 3
     * of 6"), or null.
     *
     * @return array{plan_id: int, title: string, step: int, total: int, done: int, status: string, next_to_book: bool}|null
     */
    public function forBooking(int $tenantId, int $bookingId): ?array
    {
        $stmt = $this->db->prepare('SELECT plan_id, position FROM calendar_plan_steps WHERE tenant_id = :tenant_id AND booking_id = :booking_id');
        $stmt->execute([':tenant_id' => $tenantId, ':booking_id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $plan = $this->find($tenantId, (int) $row['plan_id']);
        if ($plan === null) {
            return null;
        }

        return [
            'plan_id' => $plan['id'],
            'title' => $plan['title'],
            'step' => (int) $row['position'] + 1,
            'total' => $plan['progress']['total'],
            'done' => $plan['progress']['done'],
            'status' => $plan['status'],
            'next_to_book' => $plan['status'] === self::ACTIVE && $this->nextStep($plan) !== null,
        ];
    }

    /** @return array<string, mixed> */
    public function setStatus(int $tenantId, int $planId, string $status): array
    {
        if (!in_array($status, [self::ACTIVE, self::DONE, self::STOPPED], true)) {
            throw new CalendarRejectedException('status is active, done or stopped.', 422);
        }
        $this->findOrFail($tenantId, $planId);
        $this->db->prepare('UPDATE calendar_plans SET status = :status, updated_at = :now WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':status' => $status, ':now' => Interval::toDb($this->booking->now()), ':tenant_id' => $tenantId, ':id' => $planId]);

        return $this->findOrFail($tenantId, $planId);
    }

    // ── booking the steps ───────────────────────────────────────────────

    /**
     * Book the next visit: the first free time in the next step's gap with
     * the same person. Nothing is booked here.
     *
     * @return array{step_id: ?int, starts_at: ?string, window: ?array{from: string, to: string}}
     */
    public function nextSlot(int $tenantId, int $planId): array
    {
        $plan = $this->findOrFail($tenantId, $planId);
        $step = $this->nextStep($plan);
        if ($step === null) {
            return ['step_id' => null, 'starts_at' => null, 'window' => null];
        }
        [$from, $to] = $this->window($tenantId, $plan, $step, null);
        $slot = $this->firstFree($tenantId, $step['service_id'], $plan['staff']['profile_id'], $from, $to);

        return ['step_id' => $step['id'], 'starts_at' => $slot !== null ? Interval::toApi($slot) : null, 'window' => ['from' => Interval::toApi($from), 'to' => Interval::toApi($to)]];
    }

    /**
     * Book the next step at `$start` (a time staff chose, or the suggestion),
     * as an ordinary booking with the same person.
     *
     * @return array<string, mixed> the plan
     */
    public function bookNext(int $tenantId, int $planId, ?DateTimeImmutable $start, ?int $actor): array
    {
        $plan = $this->findOrFail($tenantId, $planId);
        if ($plan['status'] !== self::ACTIVE) {
            throw new CalendarRejectedException('This plan is not active.', 422);
        }
        $step = $this->nextStep($plan) ?? throw new CalendarRejectedException('Every step is booked.', 422);
        if ($start === null) {
            [$from, $to] = $this->window($tenantId, $plan, $step, null);
            $start = $this->firstFree($tenantId, $step['service_id'], $plan['staff']['profile_id'], $from, $to)
                ?? throw new CalendarRejectedException('There is no free time in this step\'s gap. Choose a time.', 409, ['code' => 'no_time']);
        }
        $this->bookStep($tenantId, $plan, $step, $start, $actor);

        return $this->findOrFail($tenantId, $planId);
    }

    /**
     * Book all: every step still to book, in order, each at the first free
     * time in its gap. A step that can't be placed is left and said so.
     *
     * @return array{plan: array<string, mixed>, steps: list<array{step_id: int, booked: bool, starts_at: ?string}>}
     */
    public function bookAll(int $tenantId, int $planId, ?int $actor): array
    {
        $plan = $this->findOrFail($tenantId, $planId);
        if ($plan['status'] !== self::ACTIVE) {
            throw new CalendarRejectedException('This plan is not active.', 422);
        }
        $results = [];
        $previous = null;
        foreach ($plan['steps'] as $step) {
            if ($step['state'] !== 'to_book') {
                $previous = $step['booking']['starts_at'] ?? null;
                $previous = is_string($previous) ? Interval::fromApi($previous) : null;
                continue;
            }
            [$from, $to] = $this->window($tenantId, $plan, $step, $previous);
            $slot = $this->firstFree($tenantId, $step['service_id'], $plan['staff']['profile_id'], $from, $to);
            $at = null;
            if ($slot !== null) {
                try {
                    $this->bookStep($tenantId, $plan, $step, $slot, $actor);
                    $at = $slot;
                } catch (CalendarRejectedException) {
                    $at = null;
                }
            }
            $results[] = ['step_id' => $step['id'], 'booked' => $at !== null, 'starts_at' => $at !== null ? Interval::toApi($at) : null];
            // The next step's gap counts from this one, or from where it would have been.
            $previous = $at ?? $from;
        }

        return ['plan' => $this->findOrFail($tenantId, $planId), 'steps' => $results];
    }

    // ── templates ───────────────────────────────────────────────────────

    /**
     * A service's templates.
     *
     * @return list<array{id: int, title: string, steps: list<array{service_id: int, gap_min_days: int, gap_max_days: int}>}>
     */
    public function templates(int $tenantId, int $serviceId): array
    {
        $stmt = $this->db->prepare('SELECT id, title, steps FROM calendar_plan_templates WHERE tenant_id = :tenant_id AND service_id = :service_id ORDER BY id');
        $stmt->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId]);

        return array_values(array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'steps' => self::decodeSteps((string) $r['steps'])], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Replace a service's templates.
     *
     * @param list<array<string, mixed>> $templates
     * @return list<array{id: int, title: string, steps: list<array{service_id: int, gap_min_days: int, gap_max_days: int}>}>
     */
    public function replaceTemplates(int $tenantId, int $serviceId, array $templates): array
    {
        if (count($templates) > 10) {
            throw new CalendarRejectedException('A service can have up to 10 plan templates.', 422);
        }
        $clean = [];
        foreach ($templates as $template) {
            $title = mb_substr(trim(is_string($template['title'] ?? null) ? $template['title'] : ''), 0, 200);
            if ($title === '') {
                throw new CalendarRejectedException('Give each template a title.', 422);
            }
            $clean[] = ['title' => $title, 'steps' => $this->cleanSteps($tenantId, is_array($template['steps'] ?? null) ? array_values($template['steps']) : [], null)];
        }
        $this->db->prepare('DELETE FROM calendar_plan_templates WHERE tenant_id = :tenant_id AND service_id = :service_id')
            ->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId]);
        $insert = $this->db->prepare(
            'INSERT INTO calendar_plan_templates (tenant_id, service_id, title, steps, created_at, updated_at)
             VALUES (:tenant_id, :service_id, :title, :steps, :now, :now2)'
        );
        $now = Interval::toDb($this->booking->now());
        foreach ($clean as $template) {
            $insert->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId, ':title' => $template['title'], ':steps' => json_encode($template['steps']), ':now' => $now, ':now2' => $now]);
        }

        return $this->templates($tenantId, $serviceId);
    }

    // ── inside ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $step
     */
    private function bookStep(int $tenantId, array $plan, array $step, DateTimeImmutable $start, ?int $actor): void
    {
        // The booking keeps what the customer was told, as every booking does.
        $booking = $this->booking->book($tenantId, [
            'service_id' => $step['service_id'],
            'profile_id' => $plan['staff']['profile_id'],
            'starts_at' => $start,
            'customer_profile_id' => null,
            'customer_name' => $plan['customer']['name'],
            'customer_email' => $plan['customer']['email'],
            'customer_phone' => $plan['customer']['phone'],
            'customer_note' => '',
            'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => $actor !== null && $actor > 0 ? $actor : null,
            'enforce_hours' => false,
            'within_hours' => true,
            'public' => false,
            'notify' => true,
        ]);
        $this->db->prepare('UPDATE calendar_plan_steps SET booking_id = :booking_id WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':booking_id' => (int) $booking['id'], ':tenant_id' => $tenantId, ':id' => $step['id']]);
        // The booking belongs to the plan's customer, even when their email
        // or phone would not have found them.
        $this->db->prepare('UPDATE calendar_bookings SET contact_id = :contact_id WHERE tenant_id = :tenant_id AND id = :id')
            ->execute([':contact_id' => $plan['customer']['contact_id'], ':tenant_id' => $tenantId, ':id' => (int) $booking['id']]);
    }

    /**
     * Where a step's time may go: from the step before (its booking, or now
     * for the first) plus the gap's least days, to plus its most, whole days
     * in the workspace's zone, and never before now.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $step
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function window(int $tenantId, array $plan, array $step, ?DateTimeImmutable $previous): array
    {
        $now = $this->booking->now();
        if ($previous === null) {
            foreach ($plan['steps'] as $s) {
                if ($s['position'] >= $step['position']) {
                    break;
                }
                $at = $s['booking']['starts_at'] ?? null;
                if (is_string($at) && $s['state'] !== 'to_book') {
                    $previous = Interval::fromApi($at);
                }
            }
        }
        $zone = $this->booking->settingsFor($tenantId)->zone;
        $base = ($previous ?? $now)->setTimezone($zone)->setTime(0, 0);
        $from = $base->modify('+' . (int) $step['gap_min_days'] . ' days');
        $to = $base->modify('+' . ((int) $step['gap_max_days'] + 1) . ' days');
        $utc = new DateTimeZone('UTC');
        $from = max($from->setTimezone($utc), $now);
        $to = $to->setTimezone($utc);

        return [$from, max($to, $from->modify('+1 day'))];
    }

    private function firstFree(int $tenantId, int $serviceId, int $profileId, DateTimeImmutable $from, DateTimeImmutable $to): ?DateTimeImmutable
    {
        try {
            $slots = $this->booking->availability($tenantId, $serviceId, $profileId, $from, $to);
        } catch (CalendarRejectedException) {
            return null;
        }

        return $slots === [] ? null : Interval::fromApi($slots[0]['starts_at']);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>|null the first step still to book
     */
    private function nextStep(array $plan): ?array
    {
        foreach ($plan['steps'] as $step) {
            if ($step['state'] === 'to_book') {
                return $step;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function steps(int $tenantId, int $planId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.position, s.service_id, s.gap_min_days, s.gap_max_days, s.booking_id, sv.name AS service_name,
                    b.starts_at, b.status AS booking_status, b.attendance, b.finished_at
               FROM calendar_plan_steps s
               JOIN calendar_services sv ON sv.id = s.service_id AND sv.tenant_id = s.tenant_id
               LEFT JOIN calendar_bookings b ON b.id = s.booking_id AND b.tenant_id = s.tenant_id
              WHERE s.tenant_id = :tenant_id AND s.plan_id = :plan_id
              ORDER BY s.position, s.id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':plan_id' => $planId]);

        return array_values(array_map(static function (array $r): array {
            $status = $r['booking_status'] ?? null;
            $state = match (true) {
                $r['booking_id'] === null || $status === null || in_array($status, [BookingRepository::STATUS_CANCELLED, BookingRepository::STATUS_EXPIRED], true) => 'to_book',
                $r['attendance'] === BookingRepository::NO_SHOW => 'missed',
                $r['attendance'] === BookingRepository::ATTENDED || $r['finished_at'] !== null => 'done',
                default => 'booked',
            };

            return [
                'id' => (int) $r['id'],
                'position' => (int) $r['position'],
                'service_id' => (int) $r['service_id'],
                'service_name' => \Whity\Core\i18n\LocalizedText::decode((string) $r['service_name'], languages: \Whity\Core\i18n\LocalizedText::BILINGUAL),
                'gap_min_days' => (int) $r['gap_min_days'],
                'gap_max_days' => (int) $r['gap_max_days'],
                'state' => $state,
                'booking' => $state === 'to_book' ? null : [
                    'id' => (int) $r['booking_id'],
                    'starts_at' => Interval::toApi(Interval::fromDb((string) $r['starts_at'])),
                    'status' => (string) $status,
                ],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @param list<mixed> $steps
     * @return list<array{service_id: int, gap_min_days: int, gap_max_days: int}>
     */
    private function cleanSteps(int $tenantId, array $steps, ?int $profileId): array
    {
        if ($steps === [] || count($steps) > self::MAX_STEPS) {
            throw new CalendarRejectedException('A plan has 1 to ' . self::MAX_STEPS . ' steps.', 422, ['field' => 'steps']);
        }
        if ($profileId !== null && !$this->isStaff($tenantId, $profileId)) {
            throw CalendarRejectedException::notFound('Staff member');
        }
        $services = new ServiceRepository($this->db);
        $clean = [];
        foreach ($steps as $step) {
            if (!is_array($step) || !is_int($step['service_id'] ?? null)) {
                throw new CalendarRejectedException('Each step needs a service_id.', 422, ['field' => 'steps']);
            }
            $service = $services->find($tenantId, $step['service_id']) ?? throw CalendarRejectedException::notFound('Service');
            if ($profileId !== null && !in_array($profileId, $service['staff_profile_ids'], true)) {
                throw new CalendarRejectedException('That person does not offer one of these services.', 422, ['field' => 'steps']);
            }
            $min = $step['gap_min_days'] ?? 0;
            $max = $step['gap_max_days'] ?? $min;
            if (!is_int($min) || !is_int($max) || $min < 0 || $max < $min || $max > self::MAX_GAP_DAYS) {
                throw new CalendarRejectedException('A gap is 0 to ' . self::MAX_GAP_DAYS . ' days, the least first.', 422, ['field' => 'steps']);
            }
            $clean[] = ['service_id' => $step['service_id'], 'gap_min_days' => $min, 'gap_max_days' => $max];
        }

        return $clean;
    }

    /** @return array{title: string, steps: list<array{service_id: int, gap_min_days: int, gap_max_days: int}>}|null */
    private function template(int $tenantId, int $templateId): ?array
    {
        $stmt = $this->db->prepare('SELECT title, steps FROM calendar_plan_templates WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['title' => (string) $row['title'], 'steps' => self::decodeSteps((string) $row['steps'])] : null;
    }

    /** @return list<array{service_id: int, gap_min_days: int, gap_max_days: int}> */
    private static function decodeSteps(string $json): array
    {
        $out = [];
        foreach ((array) json_decode($json, true) as $step) {
            if (is_array($step) && is_numeric($step['service_id'] ?? null)) {
                $out[] = ['service_id' => (int) $step['service_id'], 'gap_min_days' => (int) ($step['gap_min_days'] ?? 0), 'gap_max_days' => (int) ($step['gap_max_days'] ?? 0)];
            }
        }

        return $out;
    }

    private function contactOrFail(int $tenantId, int $contactId): void
    {
        $stmt = $this->db->prepare('SELECT 1 FROM contacts WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $contactId]);
        if ($stmt->fetchColumn() === false) {
            throw CalendarRejectedException::notFound('Customer');
        }
    }

    private function isStaff(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM calendar_staff WHERE tenant_id = :tenant_id AND profile_id = :profile_id');
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }
}
