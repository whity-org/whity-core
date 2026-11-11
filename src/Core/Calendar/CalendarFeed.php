<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Whity\Core\Form\LocalizedLabel;

/**
 * A person's calendar as an ICS feed at a secret URL (ADR 0017, section 6).
 *
 * The token is 256 random bits; only its SHA-256 is kept, on the person's
 * `calendar_staff` row. Turning the feed on again replaces the token, which
 * is how a leaked URL is stopped. The feed covers 30 days back to the
 * booking window ahead, and includes customer names: the screen says so.
 */
final class CalendarFeed
{
    public function __construct(
        private readonly PDO $db,
        private readonly CalendarRepository $calendar,
        private readonly BookingRepository $bookings,
    ) {
    }

    /** Turn the feed on (or replace its URL) and return the new token. */
    public function issue(int $tenantId, int $profileId): string
    {
        $this->calendar->ensureStaff($tenantId, $profileId);
        $raw = bin2hex(random_bytes(32));
        $this->db->prepare('UPDATE calendar_staff SET feed_token_hash = :hash WHERE tenant_id = :tenant_id AND profile_id = :profile_id')
            ->execute([':hash' => hash('sha256', $raw), ':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $raw;
    }

    public function revoke(int $tenantId, int $profileId): void
    {
        $this->db->prepare('UPDATE calendar_staff SET feed_token_hash = NULL WHERE tenant_id = :tenant_id AND profile_id = :profile_id')
            ->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
    }

    /** @return array{0: int, 1: int}|null [tenantId, profileId] */
    public function find(string $raw): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
            return null;
        }
        // @tenant-guard-ignore: a staff member's ICS feed — this read is what RESOLVES the tenant, from a 256-bit token stored only as its hash under a global unique index (migration 247), fetched by a calendar app with no session. The feed is then built only from rows that bind the tenant_id it returns.
        $stmt = $this->db->prepare('SELECT tenant_id, profile_id FROM calendar_staff WHERE feed_token_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => hash('sha256', $raw)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [(int) $row['tenant_id'], (int) $row['profile_id']] : null;
    }

    public function render(int $tenantId, int $profileId, CalendarSettings $settings, DateTimeImmutable $now, string $calendarName): string
    {
        $from = $now->modify('-30 days');
        $to = $now->modify('+' . $settings->maxAdvanceDays . ' days');
        $events = [];
        foreach ($this->bookings->list($tenantId, [$profileId], $from, $to) as $row) {
            $service = LocalizedLabel::preferred(LocalizedLabel::decode((string) $row['service_name']));
            $events[] = [
                'uid' => 'booking-' . (int) $row['id'] . '-' . $tenantId . '@whity',
                'start' => Interval::fromDb((string) $row['starts_at']),
                'end' => Interval::fromDb((string) $row['ends_at']),
                'summary' => trim($service . ' · ' . (string) $row['customer_name'], ' ·'),
                'description' => (string) $row['customer_note'],
                'status' => $row['status'] === BookingRepository::STATUS_CONFIRMED ? 'CONFIRMED' : 'TENTATIVE',
            ];
        }
        foreach ($this->calendar->listEvents($tenantId, [$profileId], $from, $to) as $event) {
            $events[] = [
                'uid' => 'event-' . $event['id'] . '-' . $tenantId . '@whity',
                'start' => Interval::fromApi($event['starts_at']) ?? $now,
                'end' => Interval::fromApi($event['ends_at']) ?? $now,
                'summary' => (string) $event['title'],
                'description' => (string) $event['notes'],
            ];
        }

        return Ics::calendar($events, $calendarName);
    }
}
