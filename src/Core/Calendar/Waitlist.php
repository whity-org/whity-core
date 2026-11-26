<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Whity\Core\Contacts\CustomerContacts;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\Log\ScrubbedErrorLog;
use Whity\Core\Security\PublicToken;
use Whity\Http\Validation\Field;
use Whity\Http\Validation\FieldValidator;

/**
 * The waitlist (WHIT-1442, design section 39 §8, register AP.23).
 *
 * Someone who wants a time that isn't free joins it, at the desk or on the
 * booking page: a service, a person or anyone, the days, and the times of day.
 * When a cancel or a move frees a time, every entry that matches is offered it
 * at once, by email and by WhatsApp or SMS when connected, each with a link to
 * a page with that one time (owner, Q12). Staff can also offer a time by hand.
 *
 * WHO GETS IT. Taking an offer is an ordinary booking, under the person's lock
 * and guarded by the database (M1, `calendar_holds`): the first booking the
 * database accepts wins, and every other offer of that time becomes `lost`.
 * Nothing here picks a winner. It is a rule (offer everyone who matches), never
 * a decision. Tenant first everywhere.
 */
final class Waitlist
{
    public const WAITING = 'waiting';
    public const OFFERED = 'offered';
    public const BOOKED = 'booked';
    public const REMOVED = 'removed';
    /** What is still on the list. */
    public const OPEN = [self::WAITING, self::OFFERED];

    public const OFFER_OPEN = 'open';
    public const OFFER_ACCEPTING = 'accepting';
    public const OFFER_TAKEN = 'taken';
    public const OFFER_LOST = 'lost';
    public const OFFER_CLOSED = 'closed';

    /** Times of day, in the person's branch zone: before 12:00, 12:00–17:00, from 17:00. */
    public const TIMES = ['morning', 'afternoon', 'evening'];
    public const ANY = 'any';

    public function __construct(
        private readonly PDO $db,
        private readonly BookingService $booking,
        private readonly ?WaitlistMessenger $mail,
        private readonly ?BookingTextReminders $texts,
        private readonly string $offerUrlBase,
    ) {
    }

    // ── the list ────────────────────────────────────────────────────────

    /**
     * Join the waitlist. `$source` is `staff` or `public`.
     *
     * @param array<string, mixed> $v
     * @return array<string, mixed> the entry
     */
    public function add(int $tenantId, array $v, ?int $actor, string $source): array
    {
        $serviceId = is_int($v['service_id'] ?? null) ? $v['service_id'] : throw new CalendarRejectedException('Choose a service.', 422);
        $service = (new ServiceRepository($this->db))->find($tenantId, $serviceId);
        if ($service === null || !$service['active'] || ($source === 'public' && !$service['bookable_online'])) {
            throw CalendarRejectedException::notFound('Service');
        }
        $profileId = is_int($v['profile_id'] ?? null) ? $v['profile_id'] : null;
        if ($profileId !== null && !in_array($profileId, $service['staff_profile_ids'], true)) {
            throw new CalendarRejectedException('That person does not offer this service.', 422);
        }
        // The shared field validator (#2174), as booking uses.
        $checked = FieldValidator::check($v, [
            'name' => Field::text()->required()->max(200),
            'email' => Field::email()->max(320),
            'phone' => Field::phone(),
        ]);
        $sentence = ['name' => 'Add their name.', 'email' => 'That email doesn’t look right.', 'phone' => 'That phone number doesn’t look right.'];
        if (($first = $checked->first()) !== null) {
            throw new CalendarRejectedException($sentence[$first] ?? 'Check their details.', 422, ['field' => $first]);
        }
        $name = $checked->value('name');
        $email = $checked->value('email');
        $phone = $checked->value('phone');
        if ($email === '' && $phone === '') {
            throw new CalendarRejectedException('Add an email or a phone, so we can tell them when a time is free.', 422, ['field' => 'email']);
        }
        $settings = $this->booking->settingsFor($tenantId);
        $today = $this->booking->now()->setTimezone($settings->zone)->format('Y-m-d');
        $from = self::date($v['date_from'] ?? null) ?? $today;
        $to = self::date($v['date_to'] ?? null) ?? $from;
        if ($from < $today) {
            $from = $today;
        }
        if ($to < $from) {
            throw new CalendarRejectedException('The last day must come after the first.', 422, ['field' => 'date_to']);
        }
        if ((new DateTimeImmutable($to))->diff(new DateTimeImmutable($from))->days > 366) {
            throw new CalendarRejectedException('Choose days within a year.', 422, ['field' => 'date_to']);
        }
        $times = self::times($v['times'] ?? null);
        $language = in_array($v['language'] ?? null, ['en', 'ar'], true) ? (string) $v['language'] : '';
        $note = mb_substr(trim(is_string($v['note'] ?? null) ? $v['note'] : ''), 0, 500);
        $contactId = (new CustomerContacts($this->db))->forCustomer($tenantId, $name, $email, $phone, null);
        $now = Interval::toDb($this->booking->now());

        $stmt = $this->db->prepare(
            'INSERT INTO calendar_waitlist (tenant_id, contact_id, customer_name, customer_email, customer_phone, service_id, profile_id,
                    date_from, date_to, times, note, language, source, status, created_by_profile_id, created_at, updated_at)
             VALUES (:tenant_id, :contact_id, :name, :email, :phone, :service_id, :profile_id,
                    :from, :to, :times, :note, :language, :source, :status, :actor, :now, :now2)
             RETURNING id'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':contact_id', $contactId, $contactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':phone', $phone);
        $stmt->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
        $stmt->bindValue(':times', $times);
        $stmt->bindValue(':note', $note);
        $stmt->bindValue(':language', $language);
        $stmt->bindValue(':source', $source === 'public' ? 'public' : 'staff');
        $stmt->bindValue(':status', self::WAITING);
        $stmt->bindValue(':actor', $actor !== null && $actor > 0 ? $actor : null, $actor !== null && $actor > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':now2', $now);
        $stmt->execute();

        return $this->find($tenantId, (int) $stmt->fetchColumn()) ?? throw CalendarRejectedException::notFound('Waitlist entry');
    }

    /**
     * The list, oldest first, with the service's and the person's names.
     *
     * @param list<string> $statuses empty = what is still on the list
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $statuses = []): array
    {
        $statuses = $statuses !== [] ? $statuses : self::OPEN;
        $in = [];
        $params = [':tenant_id' => $tenantId];
        foreach ($statuses as $i => $status) {
            $in[] = ':s' . $i;
            $params[':s' . $i] = $status;
        }
        $stmt = $this->db->prepare(
            'SELECT w.*, s.name AS service_name, p.display_name AS staff_name
               FROM calendar_waitlist w
               JOIN calendar_services s ON s.id = w.service_id AND s.tenant_id = w.tenant_id
               LEFT JOIN profiles p ON p.id = w.profile_id
              WHERE w.tenant_id = :tenant_id AND w.status IN (' . implode(', ', $in) . ')
              ORDER BY w.created_at, w.id'
        );
        $stmt->execute($params);

        return array_values(array_map([self::class, 'toApi'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT w.*, s.name AS service_name, p.display_name AS staff_name
               FROM calendar_waitlist w
               JOIN calendar_services s ON s.id = w.service_id AND s.tenant_id = w.tenant_id
               LEFT JOIN profiles p ON p.id = w.profile_id
              WHERE w.tenant_id = :tenant_id AND w.id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::toApi($row) : null;
    }

    /** How many are still waiting or have an offer open: the desk's count. */
    public function count(int $tenantId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM calendar_waitlist WHERE tenant_id = :tenant_id AND status IN ('waiting', 'offered')");
        $stmt->execute([':tenant_id' => $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /** Take someone off the list; their open offers close. */
    public function remove(int $tenantId, int $id): void
    {
        $entry = $this->find($tenantId, $id) ?? throw CalendarRejectedException::notFound('Waitlist entry');
        if (!in_array($entry['status'], self::OPEN, true)) {
            return;
        }
        $this->setStatus($tenantId, $id, self::REMOVED, null);
        $this->db->prepare("UPDATE calendar_waitlist_offers SET status = 'closed', decided_at = :now
                             WHERE tenant_id = :tenant_id AND waitlist_id = :id AND status = 'open'")
            ->execute([':now' => Interval::toDb($this->booking->now()), ':tenant_id' => $tenantId, ':id' => $id]);
    }

    // ── offering ────────────────────────────────────────────────────────

    /**
     * A time was freed (a cancel, a move, "I can't come"): offer it to every
     * entry that matches, oldest first, all at once. Only when the time is
     * really free for that person and service, and far enough ahead for the
     * minimum notice. Returns how many were offered. Never throws.
     */
    public function offerFreed(int $tenantId, int $profileId, int $serviceId, DateTimeImmutable $start): int
    {
        try {
            $settings = $this->booking->settingsFor($tenantId);
            $now = $this->booking->now();
            $expires = $start->modify('-' . $settings->minNoticeMinutes . ' minutes');
            if ($expires <= $now || !$this->isFree($tenantId, $serviceId, $profileId, $start)) {
                return 0;
            }
            $zone = (new BranchZones($this->db))->forPerson($tenantId, $profileId, $settings->zone);
            $local = $start->setTimezone($zone);
            $date = $local->format('Y-m-d');
            $part = self::partOfDay((int) $local->format('G'));

            $stmt = $this->db->prepare(
                "SELECT id, times FROM calendar_waitlist
                  WHERE tenant_id = :tenant_id AND service_id = :service_id AND status IN ('waiting', 'offered')
                    AND (profile_id IS NULL OR profile_id = :profile_id)
                    AND date_from <= :date AND date_to >= :date2
                  ORDER BY created_at, id"
            );
            $stmt->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId, ':profile_id' => $profileId, ':date' => $date, ':date2' => $date]);
            $offered = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $times = (string) $row['times'];
                if ($times !== self::ANY && !in_array($part, explode(',', $times), true)) {
                    continue;
                }
                if ($this->offer($tenantId, (int) $row['id'], $serviceId, $profileId, $start, $expires, null) !== null) {
                    $offered++;
                }
            }

            return $offered;
        } catch (Throwable $e) {
            ScrubbedErrorLog::failure('[calendar] could not offer a freed time to the waitlist', $e);

            return 0;
        }
    }

    /**
     * Staff offer a time by hand to one entry (AP.23). The time must be free.
     *
     * @return array<string, mixed> the offer
     */
    public function offerByHand(int $tenantId, int $entryId, int $profileId, DateTimeImmutable $start, ?int $actor): array
    {
        $entry = $this->find($tenantId, $entryId) ?? throw CalendarRejectedException::notFound('Waitlist entry');
        if (!in_array($entry['status'], self::OPEN, true)) {
            throw new CalendarRejectedException('They are no longer on the waitlist.', 422);
        }
        $settings = $this->booking->settingsFor($tenantId);
        $expires = $start->modify('-' . $settings->minNoticeMinutes . ' minutes');
        if ($expires <= $this->booking->now()) {
            throw new CalendarRejectedException('That time is too soon to offer.', 422);
        }
        if (!$this->isFree($tenantId, (int) $entry['service_id'], $profileId, $start)) {
            throw CalendarRejectedException::taken();
        }

        return $this->offer($tenantId, $entryId, (int) $entry['service_id'], $profileId, $start, $expires, $actor)
            ?? throw new CalendarRejectedException('That time was already offered to them.', 409);
    }

    /**
     * What the offer page shows: the time, and whether it can still be taken.
     * `state` is `open`, `booked` (they took it), `taken` (someone else did),
     * `expired`, or `closed` (they left the list).
     *
     * @return array<string, mixed>|null
     */
    public function offerFor(string $token): ?array
    {
        $offer = $this->offerByToken($token);
        if ($offer === null) {
            return null;
        }
        $tenantId = (int) $offer['tenant_id'];
        $service = (new ServiceRepository($this->db))->find($tenantId, (int) $offer['service_id']);
        $start = Interval::fromDb((string) $offer['starts_at']);
        $minutes = (int) ($service !== null ? ServiceRepository::forPerson($service, (int) $offer['profile_id'])['duration_minutes'] : 0);

        return [
            'tenant_id' => $tenantId,
            'state' => $this->stateOf($offer),
            'starts_at' => Interval::toApi($start),
            'ends_at' => Interval::toApi($start->modify('+' . $minutes . ' minutes')),
            'expires_at' => Interval::toApi(Interval::fromDb((string) $offer['expires_at'])),
            'service' => ['name' => $service['name'] ?? [], 'duration_minutes' => $minutes],
            'staff_name' => $this->nameOf((int) $offer['profile_id']),
            'customer_name' => (string) $offer['customer_name'],
            'time_zone' => BranchZones::valid((new BranchZones($this->db))->forPerson($tenantId, (int) $offer['profile_id'], $this->booking->settingsFor($tenantId)->zone)->getName()),
            'booking_id' => $offer['booking_id'] !== null ? (int) $offer['booking_id'] : null,
        ];
    }

    /**
     * Take the offered time (AP.23): a normal booking under the locks. If the
     * database already holds that time for someone else, this offer is lost
     * and they stay on the waitlist.
     *
     * @return array{outcome: string, booking: ?array<string, mixed>, tenant_id: int}
     *         outcome `booked`, `taken_first`, `expired` or `closed`
     */
    public function accept(string $token): array
    {
        $offer = $this->offerByToken($token) ?? throw CalendarRejectedException::notFound('Offer');
        $tenantId = (int) $offer['tenant_id'];
        $id = (int) $offer['id'];
        $state = $this->stateOf($offer);
        if ($state === 'booked') {
            return ['outcome' => 'booked', 'booking' => $this->booking->bookingOrFail($tenantId, (int) $offer['booking_id']), 'tenant_id' => $tenantId];
        }
        if ($state !== self::OFFER_OPEN) {
            return ['outcome' => $state === 'taken' ? 'taken_first' : $state, 'booking' => null, 'tenant_id' => $tenantId];
        }
        // Claim this offer, so the same link pressed twice books once.
        $claim = $this->db->prepare("UPDATE calendar_waitlist_offers SET status = 'accepting' WHERE tenant_id = :tenant_id AND id = :id AND status = 'open'");
        $claim->execute([':tenant_id' => $tenantId, ':id' => $id]);
        if ($claim->rowCount() === 0) {
            return $this->accept($token);
        }
        $start = Interval::fromDb((string) $offer['starts_at']);
        try {
            $booking = $this->booking->book($tenantId, [
                'service_id' => (int) $offer['service_id'],
                'profile_id' => (int) $offer['profile_id'],
                'starts_at' => $start,
                'customer_profile_id' => null,
                'customer_name' => (string) $offer['customer_name'],
                'customer_email' => (string) $offer['customer_email'],
                'customer_phone' => (string) $offer['customer_phone'],
                'customer_note' => (string) $offer['note'],
                'source' => BookingRepository::SOURCE_PUBLIC,
                'created_by_profile_id' => null,
                'enforce_hours' => false,
                'within_hours' => true,
                'public' => false,
                'notify' => true,
            ]);
        } catch (CalendarRejectedException $e) {
            if ($e->status !== 409) {
                $this->setOffer($tenantId, $id, self::OFFER_OPEN, null);
                throw $e;
            }
            // Someone took this time first: the database held it for them.
            $this->setOffer($tenantId, $id, self::OFFER_LOST, null);
            $this->backToWaiting($tenantId, (int) $offer['waitlist_id']);

            return ['outcome' => 'taken_first', 'booking' => null, 'tenant_id' => $tenantId];
        } catch (Throwable $e) {
            $this->setOffer($tenantId, $id, self::OFFER_OPEN, null);
            throw $e;
        }
        $bookingId = (int) $booking['id'];
        $this->setOffer($tenantId, $id, self::OFFER_TAKEN, $bookingId);
        $this->setStatus($tenantId, (int) $offer['waitlist_id'], self::BOOKED, $bookingId);
        // Everyone else offered this time has lost it; they stay on the list.
        $others = $this->db->prepare(
            "SELECT id, waitlist_id FROM calendar_waitlist_offers
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND starts_at = :at AND id <> :id AND status = 'open'"
        );
        $others->execute([':tenant_id' => $tenantId, ':profile_id' => (int) $offer['profile_id'], ':at' => Interval::toDb($start), ':id' => $id]);
        foreach ($others->fetchAll(PDO::FETCH_ASSOC) as $other) {
            $this->setOffer($tenantId, (int) $other['id'], self::OFFER_LOST, null);
            $this->backToWaiting($tenantId, (int) $other['waitlist_id']);
        }

        return ['outcome' => 'booked', 'booking' => $booking, 'tenant_id' => $tenantId];
    }

    // ── inside ──────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null the offer created, or null when this entry already has this time */
    private function offer(int $tenantId, int $entryId, int $serviceId, int $profileId, DateTimeImmutable $start, DateTimeImmutable $expires, ?int $actor): ?array
    {
        $exists = $this->db->prepare(
            "SELECT 1 FROM calendar_waitlist_offers
              WHERE tenant_id = :tenant_id AND waitlist_id = :entry AND profile_id = :profile_id AND starts_at = :at AND status IN ('open', 'accepting')"
        );
        $exists->execute([':tenant_id' => $tenantId, ':entry' => $entryId, ':profile_id' => $profileId, ':at' => Interval::toDb($start)]);
        if ($exists->fetchColumn() !== false) {
            return null;
        }
        $token = PublicToken::make();
        $now = Interval::toDb($this->booking->now());
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_waitlist_offers (tenant_id, waitlist_id, service_id, profile_id, starts_at, expires_at, token_hash, status, offered_by_profile_id, created_at)
             VALUES (:tenant_id, :entry, :service_id, :profile_id, :at, :expires, :hash, :status, :actor, :now)
             RETURNING id'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':entry', $entryId, PDO::PARAM_INT);
        $stmt->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':at', Interval::toDb($start));
        $stmt->bindValue(':expires', Interval::toDb($expires));
        $stmt->bindValue(':hash', PublicToken::hash($token));
        $stmt->bindValue(':status', self::OFFER_OPEN);
        $stmt->bindValue(':actor', $actor !== null && $actor > 0 ? $actor : null, $actor !== null && $actor > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':now', $now);
        $stmt->execute();
        $offerId = (int) $stmt->fetchColumn();
        $this->setStatus($tenantId, $entryId, self::OFFERED, null);
        $this->tell($tenantId, $entryId, $profileId, $start, $expires, $token);

        return ['id' => $offerId, 'waitlist_id' => $entryId, 'profile_id' => $profileId, 'starts_at' => Interval::toApi($start), 'expires_at' => Interval::toApi($expires), 'token' => $token];
    }

    /** Email, and text where a text channel is on; a failure is logged, never thrown. */
    private function tell(int $tenantId, int $entryId, int $profileId, DateTimeImmutable $start, DateTimeImmutable $expires, string $token): void
    {
        $entry = $this->find($tenantId, $entryId);
        if ($entry === null) {
            return;
        }
        $url = rtrim($this->offerUrlBase, '/') . '/' . $token;
        $zone = (new BranchZones($this->db))->forPerson($tenantId, $profileId, $this->booking->settingsFor($tenantId)->zone);
        $staff = $this->nameOf($profileId);
        try {
            $this->mail?->waitlistOffer($tenantId, [
                'to' => (string) $entry['customer']['email'],
                'name' => (string) $entry['customer']['name'],
                'language' => (string) $entry['language'],
                'service' => is_array($entry['service_name']) ? $entry['service_name'] : [],
                'staff_name' => $staff,
                'starts_at' => Interval::toApi($start),
                'time_zone' => $zone->getName(),
                'offer_url' => $url,
                'expires_at' => Interval::toApi($expires),
            ]);
            $phone = (string) $entry['customer']['phone'];
            if ($phone !== '' && $this->texts !== null) {
                $arabic = $entry['language'] === 'ar';
                $when = EmailBookingMessenger::when($start->setTimezone($zone), $arabic);
                $names = is_array($entry['service_name']) ? $entry['service_name'] : [];
                $service = (string) ($names[$arabic ? 'ar' : 'en'] ?? $names['en'] ?? reset($names) ?: '');
                $text = $arabic
                    ? 'أصبح موعد متاحًا: ' . $service . ' ' . $when . '. أول من يحجزه يحصل عليه: ' . $url
                    : 'A time is free: ' . $service . ' ' . $when . '. The first to take it gets it: ' . $url;
                $this->texts->sendText($tenantId, $phone, (string) $entry['customer']['name'], $text, 'calendar.waitlist');
            }
        } catch (Throwable $e) {
            ScrubbedErrorLog::failure('[calendar] could not tell someone about a waitlist offer', $e);
        }
    }

    /** Whether the service can be booked with the person at exactly `$start`. */
    private function isFree(int $tenantId, int $serviceId, int $profileId, DateTimeImmutable $start): bool
    {
        try {
            foreach ($this->booking->availability($tenantId, $serviceId, $profileId, $start, $start->modify('+1 minute')) as $slot) {
                if (Interval::fromApi($slot['starts_at']) == $start) {
                    return true;
                }
            }
        } catch (CalendarRejectedException) {
            return false;
        }

        return false;
    }

    /** @return array<string, mixed>|null the offer with its entry's customer */
    private function offerByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        // The token is the key, as for a booking's manage link; the tenant comes from the row.
        $stmt = $this->db->prepare(
            'SELECT o.*, w.customer_name, w.customer_email, w.customer_phone, w.note, w.status AS entry_status
               FROM calendar_waitlist_offers o
               JOIN calendar_waitlist w ON w.id = o.waitlist_id AND w.tenant_id = o.tenant_id
              WHERE o.token_hash = :hash'
        );
        $stmt->execute([':hash' => PublicToken::hash($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $offer */
    private function stateOf(array $offer): string
    {
        return match (true) {
            $offer['status'] === self::OFFER_TAKEN => 'booked',
            $offer['status'] === self::OFFER_LOST => 'taken',
            $offer['status'] === self::OFFER_CLOSED || $offer['entry_status'] === self::REMOVED => 'closed',
            Interval::fromDb((string) $offer['expires_at']) <= $this->booking->now() => 'expired',
            default => self::OFFER_OPEN,
        };
    }

    private function setOffer(int $tenantId, int $id, string $status, ?int $bookingId): void
    {
        $stmt = $this->db->prepare('UPDATE calendar_waitlist_offers SET status = :status, booking_id = :booking, decided_at = :now WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':booking', $bookingId, $bookingId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':now', $status === self::OFFER_OPEN ? null : Interval::toDb($this->booking->now()), $status === self::OFFER_OPEN ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function setStatus(int $tenantId, int $id, string $status, ?int $bookingId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE calendar_waitlist SET status = :status, booking_id = COALESCE(:booking, booking_id), updated_at = :now
              WHERE tenant_id = :tenant_id AND id = :id AND status <> :booked'
        );
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':booking', $bookingId, $bookingId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':now', Interval::toDb($this->booking->now()));
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':booked', $status === self::BOOKED ? '' : self::BOOKED);
        $stmt->execute();
    }

    /** An entry whose offers are all gone waits again. */
    private function backToWaiting(int $tenantId, int $entryId): void
    {
        $open = $this->db->prepare("SELECT 1 FROM calendar_waitlist_offers WHERE tenant_id = :tenant_id AND waitlist_id = :id AND status IN ('open', 'accepting')");
        $open->execute([':tenant_id' => $tenantId, ':id' => $entryId]);
        if ($open->fetchColumn() === false) {
            $this->db->prepare("UPDATE calendar_waitlist SET status = 'waiting', updated_at = :now WHERE tenant_id = :tenant_id AND id = :id AND status = 'offered'")
                ->execute([':now' => Interval::toDb($this->booking->now()), ':tenant_id' => $tenantId, ':id' => $entryId]);
        }
    }

    private function nameOf(int $profileId): string
    {
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** `any`, or the chosen times of day in their order, comma-separated. */
    private static function times(mixed $value): string
    {
        if ($value === null || $value === self::ANY || $value === []) {
            return self::ANY;
        }
        if (!is_array($value)) {
            throw new CalendarRejectedException('times is a list of morning, afternoon and evening, or any.', 422, ['field' => 'times']);
        }
        $chosen = array_values(array_filter(self::TIMES, static fn (string $t): bool => in_array($t, $value, true)));
        if (count($chosen) !== count(array_unique($value))) {
            throw new CalendarRejectedException('times is a list of morning, afternoon and evening, or any.', 422, ['field' => 'times']);
        }

        return count($chosen) === count(self::TIMES) ? self::ANY : implode(',', $chosen);
    }

    private static function partOfDay(int $hour): string
    {
        return $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function toApi(array $r): array
    {
        $times = (string) $r['times'];

        return [
            'id' => (int) $r['id'],
            'contact_id' => $r['contact_id'] !== null ? (int) $r['contact_id'] : null,
            'customer' => ['name' => (string) $r['customer_name'], 'email' => (string) $r['customer_email'], 'phone' => (string) $r['customer_phone']],
            'service_id' => (int) $r['service_id'],
            'service_name' => LocalizedText::decode((string) $r['service_name'], languages: LocalizedText::BILINGUAL),
            'profile_id' => $r['profile_id'] !== null ? (int) $r['profile_id'] : null,
            'staff_name' => $r['staff_name'] !== null ? (string) $r['staff_name'] : null,
            'date_from' => substr((string) $r['date_from'], 0, 10),
            'date_to' => substr((string) $r['date_to'], 0, 10),
            'times' => $times === self::ANY ? [] : explode(',', $times),
            'note' => (string) $r['note'],
            'language' => (string) $r['language'],
            'source' => (string) $r['source'],
            'status' => (string) $r['status'],
            'booking_id' => $r['booking_id'] !== null ? (int) $r['booking_id'] : null,
            'created_at' => Interval::toApi(Interval::fromDb((string) $r['created_at'])),
        ];
    }
}
