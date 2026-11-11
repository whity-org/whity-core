<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;

/**
 * The customer's link to move or cancel a booking (ADR 0017, section 6).
 *
 * A random 256-bit token; only its SHA-256 is stored, the pattern invitations
 * and document QR tokens use. It expires when the booking starts and is
 * replaced on every move, so an old email cannot move a booking again.
 * Stateless apart from the PDO handle — worker-safe.
 */
final class ManageLinks
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Issue a fresh token for a booking and return it (the only time it is seen). */
    public function issue(int $tenantId, int $bookingId, DateTimeImmutable $expiresAt): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->db->prepare(
            'UPDATE calendar_bookings SET manage_token_hash = :hash, manage_token_expires_at = :expires
              WHERE tenant_id = :tenant_id AND id = :id'
        )->execute([
            ':hash' => hash('sha256', $raw),
            ':expires' => Interval::toDb($expiresAt),
            ':tenant_id' => $tenantId,
            ':id' => $bookingId,
        ]);

        return $raw;
    }

    /**
     * The booking a live token names, as `[tenantId, bookingId]`, or null for
     * an unknown, malformed or expired token.
     *
     * @return array{0: int, 1: int}|null
     */
    public function find(string $raw, DateTimeImmutable $now): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
            return null;
        }
        // @tenant-guard-ignore: the customer's manage link — this read is what RESOLVES the tenant, from a 256-bit token stored only as its hash under a global unique index (migration 248), on a path where the caller has no account. Every later read and write in PublicBookingApiHandler binds the tenant_id it returns. Mirrors FormRepository::findByPublicSlug().
        $stmt = $this->db->prepare(
            'SELECT tenant_id, id FROM calendar_bookings WHERE manage_token_hash = :hash AND manage_token_expires_at > :now LIMIT 1'
        );
        $stmt->execute([':hash' => hash('sha256', $raw), ':now' => Interval::toDb($now)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [(int) $row['tenant_id'], (int) $row['id']] : null;
    }

    /** Stop a booking's link working (it was cancelled). */
    public function revoke(int $tenantId, int $bookingId): void
    {
        $this->db->prepare(
            'UPDATE calendar_bookings SET manage_token_hash = NULL, manage_token_expires_at = NULL
              WHERE tenant_id = :tenant_id AND id = :id'
        )->execute([':tenant_id' => $tenantId, ':id' => $bookingId]);
    }
}
