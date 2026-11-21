<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Whity\Core\i18n\LocalizedText;

/**
 * The walk-in queue at the front desk (WHIT-1334, design section 35 §3,
 * register SL.2-SL.4): someone who came in without a booking waits here until
 * the desk hands them to a member of staff.
 *
 * It holds only the wait. Handing someone over books the service now for that
 * person through {@see BookingService::book()} (staff-made, without emailing
 * anyone, over whatever they had) and checks the booking in, so the visit is
 * in the calendar like any other; the booking finds or adds the customer in
 * Contacts by their mobile, as every booking does. The row keeps the booking
 * and the contact it ended with.
 *
 * Moved from the Salons plugin's `WalkinQueue` (WHIT-1154), which did the same
 * through core's HTTP routes. Rows are never deleted: a row ends `served` or
 * `left`.
 */
final class WalkinQueue
{
    public const WAITING = 'waiting';
    /** Claimed by one desk while the booking is made, so two desks can't serve one person. */
    public const ASSIGNING = 'assigning';
    public const SERVED = 'served';
    public const LEFT = 'left';

    /** @var (\Closure(): DateTimeImmutable)|null */
    private readonly ?\Closure $clock;

    /** @param (\Closure(): DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly PDO $db,
        private readonly BookingService $booking,
        private readonly CalendarRepository $calendar,
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookings,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * Who is waiting, first come first served, with what they want and how
     * long they have waited.
     *
     * @return list<array<string, mixed>>
     */
    public function waiting(int $tenantId, string $language = 'en'): array
    {
        $stmt = $this->db->prepare('SELECT * FROM calendar_walkins WHERE tenant_id = :t AND status = :s ORDER BY added_at, id');
        $stmt->execute([':t' => $tenantId, ':s' => self::WAITING]);
        $services = $this->serviceNames($tenantId, $language);
        $staff = $this->staffNames($tenantId);
        $now = $this->now()->getTimestamp();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
            $preferred = $row['preferred_profile_id'] !== null ? (int) $row['preferred_profile_id'] : null;
            $added = strtotime((string) $row['added_at'] . ' UTC');
            $out[] = [
                'id' => (int) $row['id'],
                'position' => $i + 1,
                'name' => (string) $row['name'],
                'phone' => (string) $row['phone'],
                'service_id' => (int) $row['service_id'],
                'service' => $services[(int) $row['service_id']] ?? '',
                'preferred_profile_id' => $preferred,
                'preferred' => $preferred !== null ? ($staff[$preferred] ?? '') : '',
                'note' => (string) $row['note'],
                'added_at' => (string) $row['added_at'],
                'waited_minutes' => $added === false ? 0 : max(0, intdiv($now - $added, 60)),
            ];
        }

        return $out;
    }

    /**
     * Add someone to the end of the queue.
     *
     * @param array<string, mixed> $input name, phone, service_id, profile_id (who they asked for), note
     * @return array<string, mixed> the new row
     */
    public function add(int $tenantId, array $input, ?int $addedBy): array
    {
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || mb_strlen($name) > 200) {
            throw new CalendarRejectedException('Write the customer\'s name, up to 200 characters.', 422, ['field' => 'name']);
        }
        $phone = trim(is_string($input['phone'] ?? null) ? $input['phone'] : '');
        if ($phone !== '' && preg_match('/^\+?[0-9 ()-]{6,40}$/', $phone) !== 1) {
            throw new CalendarRejectedException('The phone number does not look right.', 422, ['field' => 'phone']);
        }
        $serviceId = self::int($input['service_id'] ?? null);
        if ($serviceId === null || !array_key_exists($serviceId, $this->serviceNames($tenantId, 'en'))) {
            throw new CalendarRejectedException('Choose the service they want.', 422, ['field' => 'service_id']);
        }
        $preferred = self::int($input['profile_id'] ?? null);
        if ($preferred !== null && !array_key_exists($preferred, $this->staffNames($tenantId))) {
            throw new CalendarRejectedException('Choose someone from the list, or leave it empty for whoever is free.', 422, ['field' => 'profile_id']);
        }
        $note = mb_substr(trim(is_string($input['note'] ?? null) ? $input['note'] : ''), 0, 500);

        $stmt = $this->db->prepare(
            'INSERT INTO calendar_walkins (tenant_id, name, phone, service_id, preferred_profile_id, note, status, added_at, added_by_profile_id)
             VALUES (:t, :name, :phone, :service, :preferred, :note, :status, :at, :by)'
        );
        $stmt->execute([
            ':t' => $tenantId, ':name' => $name, ':phone' => $phone, ':service' => $serviceId,
            ':preferred' => $preferred, ':note' => $note, ':status' => self::WAITING,
            ':at' => $this->now()->format('Y-m-d H:i:s'), ':by' => $addedBy,
        ]);

        return $this->row($tenantId, (int) $this->db->lastInsertId()) ?? throw new \RuntimeException('The walk-in was not saved');
    }

    /**
     * The bookable staff, free ones first: free means no confirmed booking in
     * the calendar right now.
     *
     * @return list<array{profile_id: int, name: string, free: bool, label: string}>
     */
    public function staff(int $tenantId, string $language = 'en'): array
    {
        $busy = $this->busyNow($tenantId);
        $ar = $language === 'ar';
        $out = [];
        foreach ($this->staffNames($tenantId) as $profileId => $name) {
            $until = $busy[$profileId] ?? null;
            $out[] = [
                'profile_id' => $profileId,
                'name' => $name,
                'free' => $until === null,
                'label' => $until === null
                    ? $name . ($ar ? ' · متاح الآن' : ' · free now')
                    : $name . ($ar ? ' · مشغول حتى ' : ' · busy until ') . $until,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['free'], $a['name']] <=> [$a['free'], $b['name']]);

        return $out;
    }

    /**
     * The services a walk-in can choose: the active ones, by id.
     *
     * @return list<array{id: int, name: string}>
     */
    public function services(int $tenantId, string $language = 'en'): array
    {
        $out = [];
        foreach ($this->serviceNames($tenantId, $language) as $id => $name) {
            $out[] = ['id' => $id, 'name' => $name];
        }

        return $out;
    }

    /**
     * Hand the walk-in to someone: the one chosen, else the one they asked for
     * if free, else the first free one. Books the service now for them,
     * checked in.
     *
     * @return array<string, mixed> the served row, with booking_id and contact_id
     */
    public function assign(int $tenantId, int $id, ?int $profileId, ?int $actor): array
    {
        $row = $this->row($tenantId, $id) ?? throw CalendarRejectedException::notFound('Walk-in');
        if ($row['status'] !== self::WAITING) {
            throw new CalendarRejectedException('Someone has already served them, or they left.', 409, ['reason' => 'not_waiting']);
        }
        $staff = $this->staffNames($tenantId);
        if ($profileId !== null && !array_key_exists($profileId, $staff)) {
            throw new CalendarRejectedException('Choose someone from the list.', 422, ['field' => 'profile_id']);
        }
        $chosen = $profileId ?? $this->pick($tenantId, $row['preferred_profile_id']);
        if ($chosen === null) {
            throw new CalendarRejectedException('Nobody is free right now. Choose someone, or keep them waiting.', 409, ['reason' => 'nobody_free']);
        }

        // Claim the row first, so two people at the desk can't both serve it.
        $claim = $this->db->prepare('UPDATE calendar_walkins SET status = :to WHERE tenant_id = :t AND id = :id AND status = :from');
        $claim->execute([':to' => self::ASSIGNING, ':t' => $tenantId, ':id' => $id, ':from' => self::WAITING]);
        if ($claim->rowCount() !== 1) {
            throw new CalendarRejectedException('Someone has already served them, or they left.', 409, ['reason' => 'not_waiting']);
        }

        try {
            $booking = $this->booking->book($tenantId, [
                'service_id' => (int) $row['service_id'],
                'profile_id' => $chosen,
                'starts_at' => $this->now(),
                'customer_profile_id' => null,
                'customer_name' => (string) $row['name'],
                'customer_email' => '',
                'customer_phone' => (string) $row['phone'],
                'customer_note' => (string) $row['note'],
                'source' => BookingRepository::SOURCE_WALKIN,
                'created_by_profile_id' => $actor,
                'enforce_hours' => false,
                'public' => false,
                'book_anyway' => true,
                'notify' => false,
            ]);
            $bookingId = self::int($booking['id'] ?? null) ?? throw new \RuntimeException('The calendar did not return the booking');
            $this->booking->checkIn($tenantId, $bookingId, true);
        } catch (\Throwable $e) {
            $this->db->prepare('UPDATE calendar_walkins SET status = :to WHERE tenant_id = :t AND id = :id AND status = :from')
                ->execute([':to' => self::WAITING, ':t' => $tenantId, ':id' => $id, ':from' => self::ASSIGNING]);

            throw $e;
        }

        $contact = $this->db->prepare('SELECT contact_id FROM calendar_bookings WHERE tenant_id = :t AND id = :id');
        $contact->execute([':t' => $tenantId, ':id' => $bookingId]);
        $contactId = self::int($contact->fetchColumn());

        $this->db->prepare(
            'UPDATE calendar_walkins SET status = :to, assigned_at = :at, assigned_profile_id = :p, booking_id = :b, contact_id = :c
             WHERE tenant_id = :t AND id = :id'
        )->execute([
            ':to' => self::SERVED, ':at' => $this->now()->format('Y-m-d H:i:s'), ':p' => $chosen,
            ':b' => $bookingId, ':c' => $contactId, ':t' => $tenantId, ':id' => $id,
        ]);

        return $this->row($tenantId, $id) ?? [];
    }

    /**
     * They left without being served. The row stays in the history.
     *
     * @return array<string, mixed>
     */
    public function left(int $tenantId, int $id): array
    {
        $this->row($tenantId, $id) ?? throw CalendarRejectedException::notFound('Walk-in');
        $stmt = $this->db->prepare('UPDATE calendar_walkins SET status = :to, left_at = :at WHERE tenant_id = :t AND id = :id AND status = :from');
        $stmt->execute([':to' => self::LEFT, ':at' => $this->now()->format('Y-m-d H:i:s'), ':t' => $tenantId, ':id' => $id, ':from' => self::WAITING]);
        if ($stmt->rowCount() !== 1) {
            throw new CalendarRejectedException('They are no longer waiting.', 409, ['reason' => 'not_waiting']);
        }

        return $this->row($tenantId, $id) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-impure the row changes as the queue moves
     */
    public function row(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM calendar_walkins WHERE tenant_id = :t AND id = :id');
        $stmt->execute([':t' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'tenant_id', 'service_id', 'preferred_profile_id', 'assigned_profile_id', 'booking_id', 'contact_id', 'added_by_profile_id', 'legacy_id'] as $key) {
            $row[$key] = isset($row[$key]) ? (int) $row[$key] : null;
        }

        return $row;
    }

    private function pick(int $tenantId, mixed $preferred): ?int
    {
        $free = array_column(array_filter($this->staff($tenantId), static fn (array $s): bool => $s['free']), 'profile_id');
        if (is_int($preferred) && in_array($preferred, $free, true)) {
            return $preferred;
        }

        return $free[0] ?? null;
    }

    /** @return array<int, string> bookable staff: profile id => name */
    private function staffNames(int $tenantId): array
    {
        $out = [];
        foreach ($this->calendar->listStaff($tenantId) as $s) {
            if ($s['bookable']) {
                $out[$s['profile_id']] = $s['display_name'];
            }
        }

        return $out;
    }

    /** @return array<int, string> active service id => name in the person's language */
    private function serviceNames(int $tenantId, string $language): array
    {
        $out = [];
        foreach ($this->services->list($tenantId, true) as $s) {
            $name = $s['name'];
            $out[(int) $s['id']] = is_array($name) || is_string($name) ? LocalizedText::resolve($name, $language) : '';
        }

        return $out;
    }

    /** @return array<int, string> profile id => "HH:MM" their current booking ends, in the workspace's time */
    private function busyNow(int $tenantId): array
    {
        $now = $this->now();
        $zone = $this->booking->settingsFor($tenantId)->zone;
        $busy = [];
        foreach ($this->bookings->list($tenantId, [], $now, $now->modify('+1 minute'), [BookingRepository::STATUS_CONFIRMED]) as $raw) {
            $b = BookingRepository::toApi($raw);
            $profileId = self::int($b['profile_id'] ?? null);
            $starts = is_string($b['starts_at'] ?? null) ? strtotime($b['starts_at']) : false;
            $ends = is_string($b['ends_at'] ?? null) ? strtotime($b['ends_at']) : false;
            if ($profileId === null || $starts === false || $ends === false || $starts > $now->getTimestamp() || $ends <= $now->getTimestamp()) {
                continue;
            }
            $label = (new DateTimeImmutable('@' . $ends))->setTimezone($zone)->format('H:i');
            $busy[$profileId] = max($busy[$profileId] ?? '', $label);
        }

        return $busy;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : $this->booking->now();
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
